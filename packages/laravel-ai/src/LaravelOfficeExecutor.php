<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Testing\Fakes\BusFake;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\ResumableOfficeExecutor;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeRun;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\RunStateReducer;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;

/**
 * Laravel AI executor binding all seven Office tools from the core catalog into an isolated OfficeAgent
 * and restoring interrupted runs via v_ai_runs / v_ai_run_steps.
 */
final readonly class LaravelOfficeExecutor implements ResumableOfficeExecutor
{
    public const string KEY = 'laravel-ai';

    public function __construct(
        private Office $office,
        private HostPolicy $policy,
        private Runs $runs,
        private StepCommitCoordinator $commits,
        private RunStateReducer $stateReducer,
        private OfficeDatabase $db,
        private WireErrorMapper $errorMapper,
        private ConfigRepository $config,
        private Dispatcher $bus,
        private Container $container,
        private ?OfficeRegistry $registry = null,
        private ?ResourceDirectory $directory = null,
        private ?CommandWriteCapability $writeCapability = null,
        private ?ChannelChatBridge $channelChat = null,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(CallContext $context, array $payload): void
    {
        $profile = $this->policy->profile($context);
        $channel = is_string($payload['channel'] ?? null) && $payload['channel'] !== ''
            ? $payload['channel']
            : 'laravel-ai';
        $sourceEventId = is_string($payload['source_event_id'] ?? null) && $payload['source_event_id'] !== ''
            ? $payload['source_event_id']
            : 'event-'.bin2hex(random_bytes(8));
        $prompt = is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '';
        $resourceId = is_string($payload['resource_id'] ?? null) && $payload['resource_id'] !== ''
            ? $payload['resource_id']
            : null;

        $this->db->transaction(function () use (
            $context,
            $channel,
            $sourceEventId,
            $profile,
            $prompt,
            $resourceId,
        ): void {
            $run = $this->runs->createOrFind(
                context: $context,
                channel: $channel,
                sourceEventId: $sourceEventId,
                profileRevision: $profile->revision,
                inputPayload: [
                    'prompt' => $prompt,
                    'resource_id' => $resourceId,
                    'profile' => $profile->name,
                ],
            );

            if (in_array($run->state, ['completed', 'rejected'], true)) {
                return;
            }

            $job = $this->buildJob($context->tenantId, $run->runId);

            $this->db->afterCommit(function () use ($context, $run, $job): void {
                Context::add('vaulter.ai.tenant_id', $context->tenantId);
                Context::add('vaulter.ai.project_scope', $context->projectScope);
                Context::add('vaulter.ai.run_id', $run->runId);
                $this->dispatcher()->dispatch($job);
            });
        }, $context->tenantId);
    }

    /**
     * Create authorized OfficeTool instances for the active CallContext and current HostProfile.
     * When $resourceIds is supplied, write tools are omitted unless at least one scoped resource
     * has live Edit permission, an installed command version, and 3.5 readiness for journal mode.
     *
     * @param  list<string>  $resourceIds
     * @return list<OfficeTool>
     */
    public function toolsFor(CallContext $context, ?string $runId = null, array $resourceIds = []): array
    {
        $profile = $this->policy->profile($context);
        $allowsWrites = true;
        if ($resourceIds !== [] && $this->registry !== null && $this->directory !== null) {
            $allowsWrites = false;
            $accessible = $this->directory->filterAccessible($context, $resourceIds);
            foreach ($accessible as $entry) {
                foreach ($this->registry->allowedDescriptors($context, $this->policy, $entry, $this->writeCapability) as $descriptor) {
                    if ($this->directory->findAccessible($context, $entry->resourceId, $descriptor->requiredPermission) !== null) {
                        $allowsWrites = true;
                        break 2;
                    }
                }
            }
        }

        $tools = [];
        foreach (OfficeOperation::cases() as $operation) {
            if (! $allowsWrites && in_array($operation, [OfficeOperation::Describe, OfficeOperation::Prepare, OfficeOperation::Commit], true)) {
                continue;
            }
            $tools[] = new OfficeTool(
                operation: $operation,
                context: $context,
                profile: $profile,
                office: $this->office,
                runs: $this->runs,
                commits: $this->commits,
                errorMapper: $this->errorMapper,
                runId: $runId,
                registry: $this->registry,
                directory: $this->directory,
                writeCapability: $this->writeCapability,
            );
        }

        return $tools;
    }

    /**
     * @param  list<OfficeRunStep>  $priorSteps
     */
    public function buildAgent(
        CallContext $context,
        ?string $runId = null,
        ?string $provider = null,
        ?string $model = null,
        array $priorSteps = [],
    ): OfficeAgent {
        $profile = $this->policy->profile($context);
        $tools = $this->toolsFor($context, $runId);
        $timeout = $this->toolTimeout();

        return new OfficeAgent(
            context: $context,
            profile: $profile,
            tools: $tools,
            config: $this->config,
            providerConnection: $provider ?? $profile->providerConnection,
            modelName: $model,
            timeoutSeconds: $timeout,
            priorSteps: $priorSteps,
        );
    }

    /**
     * Execute or resume a persisted run under a fresh lease and the latest HostProfile restrictions.
     */
    public function executeRun(CallContext $context, string $runId): OfficeRun
    {
        $lease = $this->runs->claimLease($context, $runId, $this->workerTimeout());
        if ($lease === null) {
            $this->channelChat?->syncLinkedRunEvents($context, $runId);

            return $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();
        }

        try {
            Context::add('vaulter.ai.tenant_id', $context->tenantId);
            Context::add('vaulter.ai.project_scope', $context->projectScope);
            Context::add('vaulter.ai.run_id', $runId);
            $run = $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();

            // Reconcile any previously recorded commit steps BEFORE invoking the model
            // so a crash recovery never re-prompts the model to plan another mutation.
            $priorSteps = $this->runs->listSteps($context, $runId);
            $hadCommitStep = false;
            foreach ($priorSteps as $step) {
                if ($step->operation === OfficeOperation::Commit) {
                    $hadCommitStep = true;
                    if (in_array($step->state, ['dispatched', 'waiting'], true)) {
                        $this->commits->resumeStep($context, $runId, $step->stepId);
                    }
                }
            }

            if ($hadCommitStep) {
                $stepsAfterReconcile = $this->runs->listSteps($context, $runId);
                $expectedResources = $this->expectedResourcesFromRun($run);
                $reconciledState = $this->stateReducer->reduce($stepsAfterReconcile, expectedResources: $expectedResources);
                if ($reconciledState === 'completed') {
                    $this->verifyUnverifiedCommittedSteps($context, $runId, $stepsAfterReconcile);
                    $stepsAfterReconcile = $this->runs->listSteps($context, $runId);
                    $reconciledState = $this->stateReducer->reduce($stepsAfterReconcile, expectedResources: $expectedResources);
                }

                if ($reconciledState !== 'partially_applied' || ! $this->hasUnfinishedOrConflictedResource($stepsAfterReconcile, $expectedResources)) {
                    $this->runs->finishRun($context, $runId, $lease, $reconciledState);
                    $this->channelChat?->syncLinkedRunEvents($context, $runId);

                    return $this->runs->find($context, $runId) ?? $run;
                }

                $this->verifyUnverifiedCommittedSteps($context, $runId, $stepsAfterReconcile);
                $priorSteps = $this->runs->listSteps($context, $runId);
            }

            $prompt = is_string($run->input['prompt'] ?? null) ? $run->input['prompt'] : '';
            $resourceId = is_string($run->input['resource_id'] ?? null) && $run->input['resource_id'] !== ''
                ? $run->input['resource_id']
                : null;
            $agent = new OfficeAgent(
                context: $context,
                profile: $this->policy->profile($context),
                tools: $this->toolsFor($context, $runId, $resourceId !== null ? [$resourceId] : []),
                config: $this->config,
                providerConnection: $this->policy->profile($context)->providerConnection,
                modelName: null,
                timeoutSeconds: $this->toolTimeout(),
                priorSteps: $priorSteps,
            );
            $agent->prompt($prompt);

            $steps = $this->runs->listSteps($context, $runId);
            foreach ($steps as $step) {
                if ($step->operation === OfficeOperation::Commit && $step->state === 'dispatched') {
                    $this->commits->resumeStep($context, $runId, $step->stepId);
                }
            }

            $steps = $this->runs->listSteps($context, $runId);
            $expectedResources = $this->expectedResourcesFromRun($run);
            $nextState = $this->stateReducer->reduce($steps, expectedResources: $expectedResources);
            $this->runs->finishRun($context, $runId, $lease, $nextState);
            $this->channelChat?->syncLinkedRunEvents($context, $runId);

            return $this->runs->find($context, $runId) ?? $run;
        } catch (Throwable $failure) {
            $this->runs->releaseLease($context, $runId, $lease, 'reconcile');

            throw $failure;
        }
    }

    /**
     * Resume a previously persisted commit step directly via office.status before any commit retry.
     */
    public function resumeStep(CallContext $context, string $runId, int $stepId): OfficeRunStep
    {
        return $this->commits->resumeStep($context, $runId, $stepId)['step'];
    }

    /**
     * Recompute and persist the parent run's state from current steps when no active lease holds it.
     */
    public function reconcileRunState(CallContext $context, string $runId): ?OfficeRun
    {
        $lease = $this->runs->claimLease($context, $runId, $this->workerTimeout());
        if ($lease === null) {
            return $this->runs->find($context, $runId);
        }

        try {
            $steps = $this->runs->listSteps($context, $runId);
            $nextState = $this->stateReducer->reduce($steps);
            $this->runs->finishRun($context, $runId, $lease, $nextState);
            $this->channelChat?->syncLinkedRunEvents($context, $runId);

            return $this->runs->find($context, $runId);
        } catch (Throwable $failure) {
            $this->runs->releaseLease($context, $runId, $lease, 'reconcile');

            throw $failure;
        }
    }

    public function firstRecoverableWaitingStepId(CallContext $context, string $runId): ?int
    {
        foreach ($this->runs->listSteps($context, $runId) as $step) {
            if ($step->operation === OfficeOperation::Commit
                && $step->state === 'waiting'
                && $step->safeError !== 'approval_required') {
                return $step->stepId;
            }
        }

        return null;
    }

    public function scheduleStepResume(CallContext $context, string $runId, int $stepId, int $delaySeconds = 5): void
    {
        if (! $this->canEnqueueAsync()) {
            return;
        }

        $job = $this->buildJob($context->tenantId, $runId, $stepId, $delaySeconds);
        Context::add('vaulter.ai.tenant_id', $context->tenantId);
        Context::add('vaulter.ai.project_scope', $context->projectScope);
        Context::add('vaulter.ai.run_id', $runId);
        $this->dispatcher()->dispatch($job);
    }

    /**
     * @param  list<OfficeRunStep>  $steps
     */
    private function verifyUnverifiedCommittedSteps(CallContext $context, string $runId, array $steps): void
    {
        /** @var array<string, true> $verifiedReceipts */
        $verifiedReceipts = [];
        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Verify && $step->state === 'verified' && $step->receiptId !== null) {
                $verifiedReceipts[$step->receiptId] = true;
            }
        }

        foreach ($steps as $step) {
            if ($step->operation !== OfficeOperation::Commit
                || ! in_array($step->state, ['finished', 'verified'], true)
                || $step->resourceId === null
                || $step->receiptId === null
                || isset($verifiedReceipts[$step->receiptId])) {
                continue;
            }

            $startedAt = hrtime(true);
            $response = $this->office->handle($context, new OfficeRequest(
                version: 1,
                operation: OfficeOperation::Verify,
                input: (object) [
                    'resource_id' => $step->resourceId,
                    'receipt_id' => $step->receiptId,
                    'mode' => 'at_commit',
                ],
            ));

            $passed = $response->ok
                && $response->result instanceof stdClass
                && ($response->result->outcome ?? null) === 'passed';

            $this->runs->recordStep(
                context: $context,
                runId: $runId,
                operation: OfficeOperation::Verify,
                state: $passed ? 'verified' : 'failed',
                resourceId: $step->resourceId,
                receiptId: $step->receiptId,
                compactResult: $response->toArray(),
                safeError: $passed ? null : ($response->errorCode !== null ? $response->errorCode->value : 'verification_failed'),
                responseBytes: $response->wireBytes(),
                latencyMs: max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
            );
            $verifiedReceipts[$step->receiptId] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function expectedResourcesFromRun(OfficeRun $run): array
    {
        $items = $run->input['draft_items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $resources = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['resource_id'] ?? null) && $item['resource_id'] !== '') {
                $resources[] = $item['resource_id'];
            }
        }

        return array_values(array_unique($resources));
    }

    /**
     * @param  list<OfficeRunStep>  $steps
     * @param  list<string>  $expectedResources
     */
    private function hasUnfinishedOrConflictedResource(array $steps, array $expectedResources): bool
    {
        /** @var array<string, OfficeRunStep> $latestCommitByResource */
        $latestCommitByResource = [];
        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Commit && $step->resourceId !== null) {
                $latestCommitByResource[$step->resourceId] = $step;
            }
        }

        foreach ($latestCommitByResource as $commitStep) {
            if ($commitStep->state === 'failed') {
                return true;
            }
        }

        foreach ($expectedResources as $resourceId) {
            if (! isset($latestCommitByResource[$resourceId])) {
                return true;
            }
        }

        return false;
    }

    private function canEnqueueAsync(): bool
    {
        if ($this->dispatcher() instanceof BusFake) {
            return true;
        }

        if (! $this->container->bound(QueueFactory::class)) {
            return false;
        }

        $queues = $this->container->make(QueueFactory::class);

        return ! ($queues->connection() instanceof SyncQueue);
    }

    private function dispatcher(): Dispatcher
    {
        return $this->container->bound(Dispatcher::class)
            ? $this->container->make(Dispatcher::class)
            : $this->bus;
    }

    private function buildJob(string $tenantId, string $runId, ?int $resumeStepId = null, ?int $delaySeconds = null): ExecuteOfficeRun
    {
        $job = new ExecuteOfficeRun($tenantId, $runId, $resumeStepId);
        $job->queue = $this->queueName();
        $job->timeout = $this->workerTimeout();
        $job->tries = $this->tries();
        $job->backoff = $this->backoff();
        if ($delaySeconds !== null && $delaySeconds > 0) {
            $job->delay = $delaySeconds;
        }

        return $job;
    }

    private function queueName(): string
    {
        $val = $this->config->get('vaulter-ai-laravel-ai.queue', 'office-agent');

        return is_string($val) && $val !== '' ? $val : 'office-agent';
    }

    private function workerTimeout(): int
    {
        $val = $this->config->get('vaulter-ai-laravel-ai.worker_timeout_seconds', 75);

        return is_int($val) && $val > 0 ? $val : 75;
    }

    private function toolTimeout(): int
    {
        $val = $this->config->get('vaulter-ai-laravel-ai.tool_timeout_seconds', 30);

        return is_int($val) && $val > 0 ? $val : 30;
    }

    private function tries(): int
    {
        $val = $this->config->get('vaulter-ai-laravel-ai.tries', 3);

        return is_int($val) && $val > 0 ? $val : 3;
    }

    /** @return list<int> */
    private function backoff(): array
    {
        $raw = $this->config->get('vaulter-ai-laravel-ai.backoff_seconds', [5, 15, 30]);
        if (! is_array($raw)) {
            return [5, 15, 30];
        }

        $out = [];
        foreach ($raw as $v) {
            if (is_int($v) && $v >= 0) {
                $out[] = $v;
            }
        }

        return $out !== [] ? $out : [5, 15, 30];
    }
}
