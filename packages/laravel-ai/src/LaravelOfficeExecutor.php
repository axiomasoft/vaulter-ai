<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Context;
use Throwable;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\OfficeExecutor;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRun;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;

/**
 * Laravel AI executor binding all seven Office tools from the core catalog into an isolated OfficeAgent
 * and restoring interrupted runs via v_ai_runs / v_ai_run_steps.
 */
final readonly class LaravelOfficeExecutor implements OfficeExecutor
{
    public const string KEY = 'laravel-ai';

    public function __construct(
        private Office $office,
        private HostPolicy $policy,
        private Runs $runs,
        private StepCommitCoordinator $commits,
        private WireErrorMapper $errorMapper,
        private ConfigRepository $config,
        private Dispatcher $bus,
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

        Context::add('vaulter.ai.tenant_id', $context->tenantId);
        Context::add('vaulter.ai.project_scope', $context->projectScope);
        Context::add('vaulter.ai.run_id', $run->runId);

        $job = new ExecuteOfficeRun($context->tenantId, $run->runId);
        $job->queue = $this->queueName();
        $job->timeout = $this->workerTimeout();
        $job->tries = $this->tries();
        $job->backoff = $this->backoff();

        $this->bus->dispatch($job);
    }

    /**
     * Create all seven OfficeTool instances for the active CallContext and current HostProfile.
     *
     * @return list<OfficeTool>
     */
    public function toolsFor(CallContext $context, ?string $runId = null): array
    {
        $profile = $this->policy->profile($context);
        $tools = [];
        foreach (OfficeOperation::cases() as $operation) {
            $tools[] = new OfficeTool(
                operation: $operation,
                context: $context,
                profile: $profile,
                office: $this->office,
                runs: $this->runs,
                commits: $this->commits,
                errorMapper: $this->errorMapper,
                runId: $runId,
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
            return $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();
        }

        try {
            Context::add('vaulter.ai.tenant_id', $context->tenantId);
            Context::add('vaulter.ai.project_scope', $context->projectScope);
            Context::add('vaulter.ai.run_id', $runId);
            $run = $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();
            $priorSteps = $this->runs->listSteps($context, $runId);
            $prompt = is_string($run->input['prompt'] ?? null) ? $run->input['prompt'] : '';
            $agent = $this->buildAgent($context, $runId, priorSteps: $priorSteps);

            $agent->prompt($prompt);

            $steps = $this->runs->listSteps($context, $runId);
            foreach ($steps as $step) {
                if ($step->operation === OfficeOperation::Commit && $step->state === 'dispatched') {
                    $this->commits->resumeStep($context, $runId, $step->stepId);
                }
            }

            $steps = $this->runs->listSteps($context, $runId);
            $nextState = $this->resolveRunState($steps);
            $this->runs->finishRun($context, $runId, $lease, $nextState);

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
     * @param  list<OfficeRunStep>  $steps
     * @return 'queued'|'running'|'awaiting_approval'|'partially_applied'|'completed'|'rejected'|'reconcile'
     */
    private function resolveRunState(array $steps): string
    {
        $hasCommit = false;
        $hasWaitingApproval = false;
        $hasCommitFailed = false;
        $hasFinishedOrVerified = false;

        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Commit) {
                $hasCommit = true;
                if ($step->state === 'waiting') {
                    if ($step->safeError === 'approval_required') {
                        $hasWaitingApproval = true;
                    }
                } elseif ($step->state === 'failed') {
                    $hasCommitFailed = true;
                } elseif ($step->state === 'finished' || $step->state === 'verified') {
                    $hasFinishedOrVerified = true;
                }
            }
        }

        if ($hasWaitingApproval) {
            return 'awaiting_approval';
        }
        if ($hasCommitFailed && ! $hasFinishedOrVerified) {
            return 'rejected';
        }
        if ($hasCommit && ! $hasFinishedOrVerified) {
            return 'reconcile';
        }

        return 'completed';
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
