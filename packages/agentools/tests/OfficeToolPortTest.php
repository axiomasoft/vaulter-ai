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
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Tests\Support\AgenToolsTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;

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
