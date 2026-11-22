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
