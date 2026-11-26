<?php

declare(strict_types=1);

use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ErrorCategory;
use AgenTools\Scenario\ScenarioOutcome;
use AgenTools\Scenario\ScenarioRunner;
use AgenTools\Schema\Canonicalizer;
use AgenTools\Tool\DescriptorDigest;
use AgenTools\Tool\ToolDescriptor;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\AgenTools\AgenToolsOfficeExecutor;
use Vaulter\Ai\AgenTools\OfficeToolDescriptorRegistry;
use Vaulter\Ai\AgenTools\OfficeToolPort;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Tests\Support\AgenToolsTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(AgenToolsTestCase::class);

test('OfficeToolDescriptorRegistry validates descriptors, digests, and HostPolicy scoping', function (): void {
    expect($this->app->make(OfficeRegistry::class)->executor('agentools'))->toBeInstanceOf(AgenToolsOfficeExecutor::class)
        ->and($this->app->make(ToolDescriptorRegistry::class))->toBeInstanceOf(OfficeToolDescriptorRegistry::class)
        ->and($this->app->make(ToolPort::class))->toBeInstanceOf(OfficeToolPort::class);

    /** @var OfficeToolDescriptorRegistry $registry */
    $registry = $this->app->make(OfficeToolDescriptorRegistry::class);
    $canonicalizer = new Canonicalizer;

    $all = $registry->all();
    expect($all)->toHaveCount(7);
    foreach ($all as $descriptor) {
        expect($descriptor)->toBeInstanceOf(ToolDescriptor::class);
        $digest = DescriptorDigest::for($descriptor, $canonicalizer);
        expect($digest->value)->toStartWith('sha-256:');
    }

    expect($registry->descriptor('office_commit')?->actionProfile->sideEffect)->toBe('write')
        ->and($registry->descriptor('office_commit')?->actionProfile->confirmation)->toBe('confirm')
        ->and($registry->descriptor('office_commit')?->actionProfile->idempotentCommit)->toBeTrue()
        ->and($registry->descriptor('office_prepare')?->actionProfile->sideEffect)->toBe('draft')
        ->and($registry->descriptor('office_read')?->actionProfile->sideEffect)->toBe('read');

    $this->app->instance(HostPolicy::class, new class implements HostPolicy
    {
        public function profile(CallContext $context): HostProfile
        {
            return new HostProfile('ro', 'agentools', null, $context->profile === 'read_only' ? [] : ['text.replace_exact@1'], ApprovalMode::Human);
        }

        public function allowedCommands(CallContext $context, ResourceEntry $resource): array
        {
            return $this->profile($context)->commands;
        }

        public function allowsCommand(CallContext $context, ResourceEntry $resource, string $command): bool
        {
            return in_array($command, $this->allowedCommands($context, $resource), true);
        }
    });

    /** @var OfficeToolDescriptorRegistry $readOnlyRegistry */
    $readOnlyRegistry = (new OfficeToolDescriptorRegistry($this->app->make(HostPolicy::class)))
        ->forContext(new CallContext('61', 'default', 'user/1', profile: 'read_only'));
    expect($readOnlyRegistry->descriptor('office_prepare'))->toBeNull()
        ->and($readOnlyRegistry->descriptor('office_commit'))->toBeNull()
        ->and($readOnlyRegistry->descriptor('office_read'))->not->toBeNull();
});

test('ScenarioRunner drives OfficeToolPort through read->prepare->commit->verify without LLM and enforces boundaries', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-at-1',
    ]);

    /** @var OfficeToolPort $unscopedPort */
    $unscopedPort = $this->app->make(OfficeToolPort::class);
    $unauth = $unscopedPort->call('office_search', ['query' => 'Договор'], 'k-unauth');
    expect($unauth->isError())->toBeTrue()
        ->and($unauth->error?->category)->toBe(ErrorCategory::PERMISSION)
        ->and($unauth->error?->code)->toBe('vaulter.permission.authentication_required');

    $scoped = $unscopedPort->forContext($fixture->context);
    $missingKey = $scoped->call('office_search', ['query' => 'Договор'], '');
    expect($missingKey->isError())->toBeTrue()
        ->and($missingKey->error?->code)->toBe('vaulter.validation.idempotency_key_missing');

    $badAuthorityArg = $scoped->call('office_search', ['query' => 'Договор', 'tenant_id' => '999'], 'k-auth-inj');
    expect($badAuthorityArg->isError())->toBeTrue()
        ->and($badAuthorityArg->error?->code)->toBe('vaulter.validation.invalid_input');

    $unknownTool = $scoped->call('office_drop_table', ['query' => 'x'], 'k-unknown');
    expect($unknownTool->isError())->toBeTrue()
        ->and($unknownTool->error?->code)->toBe('vaulter.not_found.resource_unavailable');

    $scenario = [
        'spec_rev' => '2026-09',
        'scenario_id' => 'office-text-replace',
        'steps' => [
            [
                'id' => 'search',
                'tool' => 'office_search',
                'input_map' => ['query' => '"Договор"', 'resource_type' => '"text"', 'limit' => 5],
            ],
            [
                'id' => 'read_block',
                'tool' => 'office_read',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
                ],
            ],
            [
                'id' => 'describe',
                'tool' => 'office_describe',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'command' => '"text.replace_exact@1"',
                ],
            ],
            [
                'id' => 'prepare',
                'tool' => 'office_prepare',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'command' => '"text.replace_exact@1"',
                    'read_token' => '$steps.read_block.read_token',
                    'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
                ],
            ],
            [
                'id' => 'commit',
                'tool' => 'office_commit',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'proposal_id' => '$steps.prepare.proposal_id',
                    'digest' => '$steps.prepare.digest',
                ],
            ],
            [
                'id' => 'verify',
                'tool' => 'office_verify',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'receipt_id' => '$steps.commit.receipt_id',
                    'mode' => '"at_commit"',
                ],
            ],
        ],
    ];

    /** @var AgenToolsOfficeExecutor $executor */
    $executor = $this->app->make(AgenToolsOfficeExecutor::class);
    $result = $executor->runScenario($fixture->context, 'agentools', 'evt-scenario-1', $scenario, [
        'resource_id' => $fixture->node->id,
    ]);

    expect($result->outcome)->toBe(ScenarioOutcome::Completed)
        ->and($result->stepResults['commit']['state'] ?? null)->toBe('committed')
        ->and($result->stepResults['verify']['outcome'] ?? null)->toBe('passed');

    $receiptId = (string) $result->stepResults['commit']['receipt_id'];
    $commandId = (string) $result->stepResults['commit']['command_id'];

    // Repeating commit under the same proposal inside ScenarioRunner with a second key returns the SAME receipt via status without a second write.
    $runs = $this->app->make(Runs::class);
    $run = $runs->createOrFind($fixture->context, 'agentools', 'evt-scenario-1', 'rev-at-1', ['prompt' => '']);
    $replayPort = $unscopedPort->forContext($fixture->context, $run->runId);
    $repeat = $replayPort->call('office_commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => (string) $result->stepResults['prepare']['proposal_id'],
        'digest' => (string) $result->stepResults['prepare']['digest'],
    ], 're-plan-key-2');
    expect($repeat->isError())->toBeFalse()
        ->and($repeat->value['receipt_id'] ?? null)->toBe($receiptId)
        ->and($repeat->value['command_id'] ?? null)->toBe($commandId);

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1)
        ->and($runs->listSteps($fixture->context, $run->runId))->toHaveCount(6)
        ->and($runs->findCommitStepByProposal($fixture->context, $run->runId, (string) $result->stepResults['prepare']['proposal_id'])?->operation)->toBe(OfficeOperation::Commit);
});

test('AgenToolsOfficeExecutor preserves awaiting_approval and resumes the same run through verify after approval', function (bool $objectArguments): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => null,
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-at-human-1',
    ]);

    $approverActor = TestActor::query()->create(['name' => 'Scenario Approver', 'email' => 'sc-approver@example.test']);
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

    $scenario = [
        'spec_rev' => '2026-09',
        'scenario_id' => 'office-human-approval-resume',
        'steps' => [
            [
                'id' => 'read_block',
                'tool' => 'office_read',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
                ],
            ],
            [
                'id' => 'prepare',
                'tool' => 'office_prepare',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'command' => '"text.replace_exact@1"',
                    'read_token' => '$steps.read_block.read_token',
                    'arguments' => '$ctx.command_args',
                ],
            ],
            [
                'id' => 'commit',
                'tool' => 'office_commit',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'proposal_id' => '$steps.prepare.proposal_id',
                    'digest' => '$steps.prepare.digest',
                ],
            ],
            [
                'id' => 'verify',
                'tool' => 'office_verify',
                'input_map' => [
                    'resource_id' => '$ctx.resource_id',
                    'receipt_id' => '$steps.commit.receipt_id',
                    'mode' => '"at_commit"',
                ],
            ],
        ],
    ];

    /** @var AgenToolsOfficeExecutor $executor */
    $executor = $this->app->make(AgenToolsOfficeExecutor::class);
    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    $sourceEventId = 'evt-human-scenario-1';

    $commandArgs = ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'];
    $scenarioContext = [
        'resource_id' => $fixture->node->id,
        'command_args' => $objectArguments ? (object) $commandArgs : $commandArgs,
    ];
    $first = $executor->runScenario($fixture->context, 'agentools', $sourceEventId, $scenario, $scenarioContext);
    $pausedRun = $runs->createOrFind($fixture->context, 'agentools', $sourceEventId, 'rev-at-human-1', []);
    expect($first->outcome)->toBe(ScenarioOutcome::Failed)
        ->and($pausedRun->state)->toBe('awaiting_approval');

    $stepsBefore = $runs->listSteps($fixture->context, $pausedRun->runId);
    $prepareStep = null;
    $commitStep = null;
    foreach ($stepsBefore as $s) {
        if ($s->operation === OfficeOperation::Prepare) {
            $prepareStep = $s;
        }
        if ($s->operation === OfficeOperation::Commit) {
            $commitStep = $s;
        }
    }
    expect($prepareStep)->not->toBeNull()
        ->and($commitStep)->not->toBeNull()
        ->and($commitStep?->state)->toBe('waiting')
        ->and($commitStep?->safeError)->toBe('approval_required');

    $proposalId = (string) ($prepareStep?->proposalId ?? '');
    $digest = (string) ($prepareStep?->compactResult['result']['digest'] ?? '');
    $commandId = (string) ($commitStep?->commandId ?? '');

    /** @var ResolveProposal $resolver */
    $resolver = $this->app->make(ResolveProposal::class);
    expect($resolver->resolve(new CommandScope($fixture->context, $fixture->node->id, $commandId), $proposalId, $digest, $approverContext, true))->toBeTrue();

    // Retry the exact same source event -> resumes the awaiting_approval run and reaches verify.
    $resumed = $executor->runScenario($fixture->context, 'agentools', $sourceEventId, $scenario, $scenarioContext);
    $completedRun = $runs->find($fixture->context, $pausedRun->runId);

    expect($resumed->outcome)->toBe(ScenarioOutcome::Completed)
        ->and($resumed->stepResults['commit']['state'] ?? null)->toBe('committed')
        ->and($resumed->stepResults['verify']['outcome'] ?? null)->toBe('passed')
        ->and($completedRun?->state)->toBe('completed');

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1)
        ->and($runs->listSteps($fixture->context, $pausedRun->runId))->toHaveCount(4);
})->with(['array arguments' => false, 'object arguments' => true]);

test('a port scoped to resources refuses tool calls on any other resource', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    $outside = Documents::in($fixture->drive)->create('Outside the scope', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'Not in scope']]]],
    ]);
    $inScope = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id);
    expect($inScope)->toBeInstanceOf(ResourceEntry::class);

    $port = $this->app->make(OfficeToolPort::class)->forContext($fixture->context, null, [$inScope]);
    $read = static fn (string $id, string $blockId, string $key) => $port->call('office_read', [
        'resource_id' => $id,
        'selector' => ['kind' => 'text_block', 'block_id' => $blockId],
    ], $key);

    // The user can read both documents (each with a selector that exists in it);
    // only the scoped one is reachable through this port.
    expect($read($fixture->node->id, 'p42', 'k-in')->isError())->toBeFalse()
        ->and($this->app->make(OfficeToolPort::class)->forContext($fixture->context)
            ->call('office_read', ['resource_id' => $outside->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p1']], 'k-unscoped')
            ->isError())->toBeFalse();
    $empty = $this->app->make(OfficeToolPort::class)->forContext($fixture->context, resources: []);
    $catalog = $this->app->make(OfficeToolDescriptorRegistry::class);
    expect($catalog->forScope($fixture->context, [])->all())->toBeEmpty()
        ->and($catalog->forScope($fixture->context, [$inScope])->descriptor('office_search'))->toBeNull();
    foreach ([
        $read($outside->id, 'p1', 'k-out'),
        $empty->call('office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'empty-scope'),
        $port->call('office_search', ['query' => 'scope'], 'k-search'),
    ] as $refused) {
        expect($refused->isError())->toBeTrue()
            ->and($refused->error?->code)->toBe('vaulter.not_found.resource_unavailable');
    }
});
