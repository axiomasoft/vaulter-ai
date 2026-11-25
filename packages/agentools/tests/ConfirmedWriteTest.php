<?php

declare(strict_types=1);

use AgenTools\Error\ErrorCategory;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\AgenTools\ActionKeyMap;
use Vaulter\Ai\AgenTools\OfficeToolPort;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Tests\Support\AgenToolsTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(AgenToolsTestCase::class);

test('ConfirmedWrite binds AgenTools action key to one command_id, blocks pre-approval commit, and applies approved digest once', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-human-1',
    ]);

    $approverActor = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($approverActor), RoleRegistry::WRITER));
    $approverContext = new CallContext(
        $fixture->context->tenantId,
        $fixture->context->projectScope,
        'user/'.$approverActor->getKey(),
        profile: $fixture->context->profile,
        policyRevision: $fixture->context->policyRevision,
        driveId: $fixture->context->driveId,
        actor: $approverActor,
    );

    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $initiator) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->initiator->tenantId === $tenantId ? $this->initiator : null;
        }
    });

    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    /** @var ActionKeyMap $actionKeyMap */
    $actionKeyMap = $this->app->make(ActionKeyMap::class);
    /** @var OfficeToolPort $basePort */
    $basePort = $this->app->make(OfficeToolPort::class);

    $run = $runs->createOrFind($fixture->context, 'agentools', 'evt-human-confirm-1', 'rev-human-1', ['prompt' => 'replace 15 -> 30']);
    $port = $basePort->forContext($fixture->context, $run->runId);

    $read = $port->call('office_read', [
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
    ], 'at-step-read');
    expect($read->isError())->toBeFalse();

    $prepare = $port->call('office_prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ], 'at-step-prepare');
    expect($prepare->isError())->toBeFalse()
        ->and($prepare->value['approval'] ?? null)->toBe('human');

    $proposalId = (string) $prepare->value['proposal_id'];
    $digest = (string) $prepare->value['digest'];

    // Attempting commit BEFORE human confirmation fails cleanly with permission/approval_required and 0 domain writes.
    $beforeApproval = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => $digest,
    ], 'at-step-commit-1');
    expect($beforeApproval->isError())->toBeTrue()
        ->and($beforeApproval->error?->category)->toBe(ErrorCategory::PERMISSION)
        ->and($beforeApproval->error?->code)->toBe('vaulter.permission.approval_required');

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(0);

    // Mismatched digest approval attempt is rejected without polluting v_ai_run_steps.
    expect(fn () => $actionKeyMap->bindConfirmedApproval(
        initiator: $fixture->context,
        runId: $run->runId,
        actionKey: 'at-step-commit-1',
        resourceId: $fixture->node->id,
        proposalId: $proposalId,
        digest: str_repeat('0', 64),
        approver: $approverContext,
        approved: true,
    ))->toThrow(OfficeException::class);

    // Exact digest human confirmation resolves the proposal and binds a single command_id (also dispatching via sync queue).
    $decision = $actionKeyMap->bindConfirmedApproval(
        initiator: $fixture->context,
        runId: $run->runId,
        actionKey: 'at-step-commit-1',
        resourceId: $fixture->node->id,
        proposalId: $proposalId,
        digest: $digest,
        approver: $approverContext,
        approved: true,
    );
    expect($decision['decided'])->toBeTrue()
        ->and($decision['command_id'])->toBe('at-step-commit-1');

    // Retrying office_commit with original OR re-planned AgenTools idempotency key recovers the exact receipt via status.
    $retrySame = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => $digest,
    ], 'at-step-commit-1');
    $retryReplan = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => $digest,
    ], 'at-step-commit-replan-2');

    expect($retrySame->isError())->toBeFalse()
        ->and($retryReplan->isError())->toBeFalse()
        ->and($retrySame->value['command_id'])->toBe('at-step-commit-1')
        ->and($retryReplan->value['command_id'])->toBe('at-step-commit-1')
        ->and($retrySame->value['receipt_id'])->toBe($retryReplan->value['receipt_id'])
        ->and($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);

    // Mismatched digest on same proposal conflicts with idempotency_conflict.
    $conflict = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => str_repeat('f', 64),
    ], 'at-step-commit-1');
    expect($conflict->isError())->toBeTrue()
        ->and($conflict->error?->code)->toBe('vaulter.conflict.idempotency_conflict');

    // Only one commit step exists for the run and route holds the bound command_id.
    $commitSteps = array_values(array_filter(
        $runs->listSteps($fixture->context, $run->runId),
        static fn ($step): bool => $step->operation === OfficeOperation::Commit,
    ));
    expect($commitSteps)->toHaveCount(1)
        ->and($this->app->make(OwnerRoutes::class)->forProposal($fixture->context, $proposalId)?->commandId)->toBe('at-step-commit-1');
});

test('ConfirmedWrite fails cleanly when initiator edit permission is revoked before human approval or commit', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-human-acl',
    ]);

    $approverActor = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'rev2@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($approverActor), RoleRegistry::WRITER));
    $approverContext = new CallContext(
        $fixture->context->tenantId,
        $fixture->context->projectScope,
        'user/'.$approverActor->getKey(),
        profile: $fixture->context->profile,
        policyRevision: $fixture->context->policyRevision,
        driveId: $fixture->context->driveId,
        actor: $approverActor,
    );

    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    /** @var ActionKeyMap $actionKeyMap */
    $actionKeyMap = $this->app->make(ActionKeyMap::class);
    /** @var OfficeToolPort $basePort */
    $basePort = $this->app->make(OfficeToolPort::class);

    $run = $runs->createOrFind($fixture->context, 'agentools', 'evt-human-acl-revoked', 'rev-human-acl', ['prompt' => 'edit']);
    $port = $basePort->forContext($fixture->context, $run->runId);

    $read = $port->call('office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'k-read');
    $prepare = $port->call('office_prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ], 'k-prepare');

    $fixture->revoke();

    expect(fn () => $actionKeyMap->bindConfirmedApproval(
        initiator: $fixture->context,
        runId: $run->runId,
        actionKey: 'k-commit-revoked',
        resourceId: $fixture->node->id,
        proposalId: (string) $prepare->value['proposal_id'],
        digest: (string) $prepare->value['digest'],
        approver: $approverContext,
        approved: true,
    ))->toThrow(OfficeException::class);

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(0);
});

test('ConfirmedWrite blocks command_id reuse across proposals, reader downgrade replay, wrong-digest lock-in, and replan key reuse', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-auto-f145',
    ]);

    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    /** @var OfficeToolPort $basePort */
    $basePort = $this->app->make(OfficeToolPort::class);

    $run = $runs->createOrFind($fixture->context, 'agentools', 'evt-f1-f4-f5', 'rev-auto-f145', ['prompt' => 'f1 f4 f5']);
    $port = $basePort->forContext($fixture->context, $run->runId);

    $read = $port->call('office_read', [
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
    ], 'k-read-1');
    expect($read->isError())->toBeFalse();

    $propA = $port->call('office_prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ], 'k-prep-a');
    expect($propA->isError())->toBeFalse();
    $proposalA = (string) $propA->value['proposal_id'];
    $digestA = (string) $propA->value['digest'];

    // First commit attempt with wrong digest must fail WITHOUT making a binding durable.
    $wrongFirst = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalA,
        'digest' => str_repeat('0', 64),
    ], 'k-commit-a-1');
    expect($wrongFirst->isError())->toBeTrue()
        ->and($wrongFirst->error?->code)->toBe('vaulter.precondition.precondition_failed');

    // Retrying same proposal with its real digest and command_id succeeds cleanly.
    $commitA = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalA,
        'digest' => $digestA,
        'command_id' => 'cmd-prop-a',
    ], 'k-commit-a-1');
    expect($commitA->isError())->toBeFalse()
        ->and($commitA->value['command_id'])->toBe('cmd-prop-a')
        ->and($commitA->value['state'])->toBe('committed');
    $receiptA = (string) $commitA->value['receipt_id'];

    // Replay proposal A with replan key k2 succeeds, AND k2 stays bound so proposal B cannot reuse k2.
    $replayA = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalA,
        'digest' => $digestA,
    ], 'k-commit-a-replan-2');
    expect($replayA->isError())->toBeFalse()
        ->and($replayA->value['receipt_id'])->toBe($receiptA);

    $read2 = $port->call('office_read', [
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
    ], 'k-read-2');
    $propB = $port->call('office_prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read2->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '30', 'replacement' => '45'],
    ], 'k-prep-b');
    expect($propB->isError())->toBeFalse();
    $proposalB = (string) $propB->value['proposal_id'];
    $digestB = (string) $propB->value['digest'];

    // Reusing accepted replan alias k-commit-a-replan-2 for proposal B must conflict.
    $reuseAlias = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalB,
        'digest' => $digestB,
    ], 'k-commit-a-replan-2');
    expect($reuseAlias->isError())->toBeTrue()
        ->and($reuseAlias->error?->code)->toBe('vaulter.conflict.idempotency_conflict');

    // command_id reuse: submitting proposal B with proposal A's command_id must conflict and never return A's receipt.
    $reuseCmd = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalB,
        'digest' => $digestB,
        'command_id' => 'cmd-prop-a',
    ], 'k-commit-b-fresh');
    expect($reuseCmd->isError())->toBeTrue()
        ->and($reuseCmd->error?->code)->toBe('vaulter.conflict.idempotency_conflict');

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(1);

    // Reader downgrade: downgrade original writer to Reader (retains View, loses Edit) -> commit replay must fail closed.
    $fixture->revoke();
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::READER));

    $downgradedReplay = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalA,
        'digest' => $digestA,
    ], 'k-commit-a-1');
    expect($downgradedReplay->isError())->toBeTrue()
        ->and($downgradedReplay->error?->code)->toBe('vaulter.not_found.resource_unavailable');

    // Direct office_status still works for the reader who initiated the command, while standalone commit replay fails closed.
    $statusStillWorks = $port->call('office_status', [
        'resource_id' => $fixture->node->id,
        'command_id' => 'cmd-prop-a',
    ], 'k-status-reader');
    expect($statusStillWorks->isError())->toBeFalse()
        ->and($statusStillWorks->value['receipt_id'])->toBe($receiptA);

    $standaloneDowngraded = $basePort->forContext($fixture->context)->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalA,
        'digest' => $digestA,
    ], 'k-commit-standalone-downgraded');
    expect($standaloneDowngraded->isError())->toBeTrue()
        ->and($standaloneDowngraded->error?->code)->toBe('vaulter.not_found.resource_unavailable');
});

test('ConfirmedWrite allows valid human approval after an earlier wrong-digest commit attempt', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-human-f4',
    ]);

    $approverActor = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($approverActor), RoleRegistry::WRITER));
    $approverContext = new CallContext(
        $fixture->context->tenantId,
        $fixture->context->projectScope,
        'user/'.$approverActor->getKey(),
        profile: $fixture->context->profile,
        policyRevision: $fixture->context->policyRevision,
        driveId: $fixture->context->driveId,
        actor: $approverActor,
    );

    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $initiator) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->initiator->tenantId === $tenantId ? $this->initiator : null;
        }
    });

    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    /** @var ActionKeyMap $actionKeyMap */
    $actionKeyMap = $this->app->make(ActionKeyMap::class);
    /** @var OfficeToolPort $basePort */
    $basePort = $this->app->make(OfficeToolPort::class);

    $run = $runs->createOrFind($fixture->context, 'agentools', 'evt-human-f4', 'rev-human-f4', ['prompt' => 'edit']);
    $port = $basePort->forContext($fixture->context, $run->runId);

    $read = $port->call('office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'k-r');
    $prep = $port->call('office_prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ], 'k-p');
    $proposalId = (string) $prep->value['proposal_id'];
    $digest = (string) $prep->value['digest'];

    $badCommit = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => str_repeat('a', 64),
    ], 'k-c');
    expect($badCommit->isError())->toBeTrue()
        ->and($badCommit->error?->code)->toBe('vaulter.precondition.precondition_failed');

    $decision = $actionKeyMap->bindConfirmedApproval(
        initiator: $fixture->context,
        runId: $run->runId,
        actionKey: 'k-c',
        resourceId: $fixture->node->id,
        proposalId: $proposalId,
        digest: $digest,
        approver: $approverContext,
        approved: true,
    );
    expect($decision['decided'])->toBeTrue();

    $goodCommit = $port->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $proposalId,
        'digest' => $digest,
    ], 'k-c');
    expect($goodCommit->isError())->toBeFalse()
        ->and($goodCommit->value['state'])->toBe('committed');
});
