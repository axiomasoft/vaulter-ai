<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\LaravelAi\ExecuteOfficeRun;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;

uses(LaravelAiTestCase::class);

function configureDeterministicLaravelAi(
    Application $app,
    TextCommandFixture $fixture,
    string $approval = 'automatic',
): void {
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => $approval,
        'revision' => 'rev-1',
    ]);
    Config::set('ai.default', 'openai');
    Config::set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'sk-test-agent-secret-only',
    ]);

    $app->instance(RunContextResolver::class, new class($fixture->context) implements RunContextResolver
    {
        public function __construct(private readonly CallContext $context) {}

        public function resolve(string $tenantId, string $runId): ?CallContext
        {
            return $this->context->tenantId === $tenantId ? $this->context : null;
        }
    });
}

test('deterministic OfficeAgent completes full search->read->describe->prepare->commit->verify flow without secrets', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    configureDeterministicLaravelAi($this->app, $fixture);

    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $runs = $this->app->make(Runs::class);
    $sourceEventId = 'evt-full-flow-1';

    $turn = 0;
    OfficeAgent::fake(function () use (&$turn, $fixture, $runs, $sourceEventId): mixed {
        $run = $runs->createOrFind($fixture->context, 'laravel-ai', $sourceEventId, 'rev-1', ['prompt' => 'update p42']);
        $steps = $runs->listSteps($fixture->context, $run->runId);

        $latestOf = static function (OfficeOperation $op) use ($steps): ?array {
            $match = null;
            foreach ($steps as $step) {
                if ($step->operation === $op) {
                    $match = $step->compactResult;
                }
            }

            return $match;
        };

        return match ($turn++) {
            0 => new ToolCall('tc-search-1', 'office_search', ['query' => 'Договор', 'resource_type' => 'text', 'limit' => 5]),
            1 => new ToolCall('tc-read-outline-1', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_outline']]),
            2 => new ToolCall('tc-read-block-1', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']]),
            3 => new ToolCall('tc-describe-1', 'office_describe', ['resource_id' => $fixture->node->id, 'command' => 'text.replace_exact@1']),
            4 => new ToolCall('tc-prepare-1', 'office_prepare', [
                'resource_id' => $fixture->node->id,
                'command' => 'text.replace_exact@1',
                'read_token' => (string) ($latestOf(OfficeOperation::Read)['result']['read_token'] ?? ''),
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
            ]),
            5 => new ToolCall('tc-commit-1', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => (string) ($latestOf(OfficeOperation::Prepare)['result']['proposal_id'] ?? ''),
                'digest' => (string) ($latestOf(OfficeOperation::Prepare)['result']['digest'] ?? ''),
            ]),
            6 => new ToolCall('tc-verify-1', 'office_verify', [
                'resource_id' => $fixture->node->id,
                'receipt_id' => (string) ($latestOf(OfficeOperation::Commit)['result']['receipt_id'] ?? ''),
                'mode' => 'at_commit',
            ]),
            default => 'Updated payment term to 30 working days and verified receipt.',
        };
    })->preventStrayPrompts();

    $executor->dispatch($fixture->context, [
        'channel' => 'laravel-ai',
        'source_event_id' => $sourceEventId,
        'prompt' => 'Change payment days in 4.2 from 15 to 30.',
        'resource_id' => $fixture->node->id,
    ]);

    $run = $runs->createOrFind($fixture->context, 'laravel-ai', $sourceEventId, 'rev-1', ['prompt' => '']);
    $steps = $runs->listSteps($fixture->context, $run->runId);
    expect($run->state)->toBe('completed')
        ->and($steps)->toHaveCount(7);

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);

    $doc = $fixture->snapshot()->content->toArray();
    expect($doc['content'][2]['content'])->toBe([
        ['type' => 'text', 'text' => '😀 Pay within '],
        ['type' => 'text', 'text' => '30', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' working days.'],
    ])->and($doc['content'][5]['content'][0]['text'])->toBe('Human terms.');

    // Verify provider API key never persisted anywhere in AI tables.
    $db = $this->app->make(OfficeDatabase::class);
    $encodedSteps = json_encode($steps, JSON_THROW_ON_ERROR);
    expect($encodedSteps)->not->toContain('sk-test-agent-secret-only')
        ->and((string) $db->query('ai_run_inputs')->value('encrypted_request'))->not->toContain('sk-test-agent-secret-only');
});

test('worker SIGKILL after owner commit resumes via office.status with a new toolCallId and never duplicates the write or step', function (): void {
    expect((int) config('vaulter-ai-laravel-ai.worker_timeout_seconds'))->toBeLessThan(
        (int) config('vaulter-ai-laravel-ai.queue_retry_after_seconds'),
    );

    $fixture = new TextCommandFixture($this->app, automatic: true);
    configureDeterministicLaravelAi($this->app, $fixture);

    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $runs = $this->app->make(Runs::class);
    $db = $this->app->make(OfficeDatabase::class);
    $owner = $this->app->make(VaulterDatabase::class)->connection();

    $run = $runs->createOrFind(
        context: $fixture->context,
        channel: 'laravel-ai',
        sourceEventId: 'evt-sigkill-recovery',
        profileRevision: 'rev-1',
        inputPayload: ['prompt' => 'Replace 15 with 30 in 4.2', 'resource_id' => $fixture->node->id],
    );

    // Phase 1: Agent runs up to commit and commits owner transaction, then worker suffers SIGKILL before settling CORE.
    $killProcess = $db->driver() === 'pgsql';
    $crashed = false;
    $turn1 = 0;
    OfficeAgent::fake(function () use (&$turn1, &$crashed, $fixture, $runs, $run): mixed {
        if ($crashed) {
            throw new RuntimeException('simulated worker SIGKILL right after owner commit');
        }

        $steps = $runs->listSteps($fixture->context, $run->runId);
        $latestOf = static function (OfficeOperation $op) use ($steps): ?array {
            $match = null;
            foreach ($steps as $step) {
                if ($step->operation === $op) {
                    $match = $step->compactResult;
                }
            }

            return $match;
        };

        return match ($turn1++) {
            0 => new ToolCall('call_w1_read', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']]),
            1 => new ToolCall('call_w1_prepare', 'office_prepare', [
                'resource_id' => $fixture->node->id,
                'command' => 'text.replace_exact@1',
                'read_token' => (string) ($latestOf(OfficeOperation::Read)['result']['read_token'] ?? ''),
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
            ]),
            2 => new ToolCall('call_w1_commit_original', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => (string) ($latestOf(OfficeOperation::Prepare)['result']['proposal_id'] ?? ''),
                'digest' => (string) ($latestOf(OfficeOperation::Prepare)['result']['digest'] ?? ''),
            ]),
            default => throw new RuntimeException('worker reached unexpected turn after commit'),
        };
    })->preventStrayPrompts();

    $routesTable = $db->table('ai_owner_routes');
    $armed = true;
    $db->connection()->beforeExecuting(static function (string $sql) use ($killProcess, $routesTable, &$armed, &$crashed): void {
        if ($armed && str_starts_with($sql, 'update "'.$routesTable.'" set "receipt_id"')) {
            $armed = false;
            if ($killProcess) {
                posix_kill(getmypid(), SIGKILL);
            }
            $crashed = true;
            throw new RuntimeException('simulated worker SIGKILL right after owner commit');
        }
    });

    $job = new ExecuteOfficeRun($fixture->context->tenantId, $run->runId);
    if ($killProcess) {
        foreach (array_keys(Config::get('database.connections', [])) as $connName) {
            DB::disconnect((string) $connName);
        }
        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);
        if ($pid === 0) {
            $job->handle($executor, $this->app->make(RunContextResolver::class));
            exit(1);
        }
        pcntl_waitpid($pid, $status);
        $armed = false;
        expect(pcntl_wifsignaled($status))->toBeTrue()
            ->and(pcntl_wtermsig($status))->toBe(SIGKILL);
    } else {
        expect(fn () => $job->handle($executor, $this->app->make(RunContextResolver::class)))
            ->toThrow(RuntimeException::class, 'simulated worker SIGKILL right after owner commit');
    }

    // Confirm post-crash intermediate state: owner committed 1 effect, CORE route & run_steps still unsettled.
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1)
        ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBeNull();

    $originalReceiptId = (string) $owner->table('v_ai_text_commands')->value('receipt_id');
    $stepsAfterCrash = $runs->listSteps($fixture->context, $run->runId);
    $commitSteps = array_values(array_filter(
        $stepsAfterCrash,
        static fn ($step): bool => $step->operation === OfficeOperation::Commit,
    ));
    expect($commitSteps)->toHaveCount(1)
        ->and($commitSteps[0]->state)->toBe('dispatched')
        ->and($commitSteps[0]->commandId)->not->toBeNull();

    $savedProposalId = (string) $commitSteps[0]->proposalId;
    $savedCommandId = (string) $commitSteps[0]->commandId;
    $savedDigest = (string) ($commitSteps[0]->resumeInput['digest'] ?? '');

    if ($killProcess) {
        // Before lease expiry on real SIGKILL, another worker cannot steal the held lease.
        expect($runs->claimLease($fixture->context, $run->runId, 75))->toBeNull();
    } else {
        expect($runs->find($fixture->context, $run->runId)?->state)->toBe('reconcile');
    }

    // Advance clock past queue_retry_after_seconds (100s > worker_timeout 75s), simulating Laravel worker retry.
    $this->fixedClock->advanceSeconds(100);

    // Track SQL statements during Phase 2 to prove commit is NOT re-inserted/re-run on owner, while status IS queried.
    $ownerQueries = [];
    $owner->listen(static function ($query) use (&$ownerQueries): void {
        if (str_contains($query->sql, 'v_ai_text_commands') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
            $ownerQueries[] = $query->sql;
        }
    });

    // Worker 2 prompt issues a NEW toolCallId ('call_w2_commit_retry_new_id') for the same proposal & digest, then verify.
    $turn2 = 0;
    OfficeAgent::fake(function () use (&$turn2, $fixture, $savedProposalId, $savedDigest, $originalReceiptId): mixed {
        return match ($turn2++) {
            0 => new ToolCall('call_w2_commit_retry_new_id', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => $savedProposalId,
                'digest' => $savedDigest,
            ]),
            1 => new ToolCall('call_w2_verify', 'office_verify', [
                'resource_id' => $fixture->node->id,
                'receipt_id' => $originalReceiptId,
                'mode' => 'at_commit',
            ]),
            default => 'Recovered commit via office.status and verified receipt.',
        };
    })->preventStrayPrompts();

    $retryJob = new ExecuteOfficeRun($fixture->context->tenantId, $run->runId);
    $retryJob->handle($executor, $this->app->make(RunContextResolver::class));

    $recoveredRun = $runs->find($fixture->context, $run->runId);
    $finalSteps = $runs->listSteps($fixture->context, $run->runId);
    $finalCommits = array_values(array_filter(
        $finalSteps,
        static fn ($step): bool => $step->operation === OfficeOperation::Commit,
    ));

    expect($recoveredRun?->state)->toBe('completed')
        ->and($finalCommits)->toHaveCount(1)
        ->and($finalCommits[0]->stepId)->toBe($commitSteps[0]->stepId)
        ->and($finalCommits[0]->commandId)->toBe($savedCommandId)
        ->and($finalCommits[0]->receiptId)->toBe($originalReceiptId)
        ->and($finalCommits[0]->state)->toBe('finished')
        ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBe($originalReceiptId)
        ->and($ownerQueries)->toBeEmpty()
        ->and($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1)
        ->and($fixture->snapshot()->stateVersion)->toBe(2);
});

test('resumeStep recovers both pre-route crash (operation_unknown -> replay same command_id) and post-commit unsettled route via office.status', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    configureDeterministicLaravelAi($this->app, $fixture);

    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $runs = $this->app->make(Runs::class);
    $coordinator = $this->app->make(StepCommitCoordinator::class);
    $owner = $this->app->make(VaulterDatabase::class)->connection();

    $proposal = $fixture->prepare('15', '30', 'p42');
    $run = $runs->createOrFind($fixture->context, 'laravel-ai', 'evt-pre-route-crash', 'rev-1', ['prompt' => 'edit']);

    // Simulate crash right after beginCommitStep, BEFORE OwnerRoutes::bind ever ran.
    $step = $runs->beginCommitStep(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $proposal->proposal_id,
        digest: $proposal->digest,
        initialCommandId: 'cmd-durable-preroute-1',
        ownerKey: 'documents.single_text@1',
    );
    expect($step->state)->toBe('dispatched');

    // Resume explicitly via queued ExecuteOfficeRun($tenantId, $runId, resumeStepId: $step->stepId).
    $resumeJob = new ExecuteOfficeRun($fixture->context->tenantId, $run->runId, $step->stepId);
    $resumeJob->handle($executor, $this->app->make(RunContextResolver::class));

    $resumed = $runs->findStep($fixture->context, $run->runId, $step->stepId);
    expect($resumed)->not->toBeNull()
        ->and($resumed?->state)->toBe('finished')
        ->and($resumed?->commandId)->toBe('cmd-durable-preroute-1')
        ->and($resumed?->receiptId)->not->toBeNull()
        ->and($owner->table('v_ai_text_commands')->count())->toBe(1);

    // Calling resumeStep a second time hits office.status and returns the exact same settled receipt cleanly.
    $again = $coordinator->resumeStep($fixture->context, $run->runId, $step->stepId);
    expect($again['step']->receiptId)->toBe($resumed?->receiptId)
        ->and($owner->table('v_ai_text_commands')->count())->toBe(1);
});
