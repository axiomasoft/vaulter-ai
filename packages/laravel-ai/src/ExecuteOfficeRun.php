<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Queue job carrying only tenant + run IDs; re-resolves trusted CallContext and HostProfile on each attempt.
 */
final class ExecuteOfficeRun implements ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public string $queue = 'office-agent';

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

    public DateTimeInterface|DateInterval|int|null $delay = null;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $runId,
        public readonly ?int $resumeStepId = null,
    ) {}

    public function handle(
        LaravelOfficeExecutor $executor,
        ?RunContextResolver $resolver = null,
    ): void {
        $context = $resolver?->resolve($this->tenantId, $this->runId);
        if ($context === null || $context->tenantId !== $this->tenantId) {
            throw OfficeException::resourceUnavailable();
        }

        if ($this->resumeStepId !== null) {
            $step = $executor->resumeStep($context, $this->runId, $this->resumeStepId);
            if ($step->state === 'waiting') {
                $executor->reconcileRunState($context, $this->runId);
                if ($step->safeError !== 'approval_required') {
                    $this->keepRecoveryScheduled($executor, $context, $this->resumeStepId);
                }

                return;
            }

            $run = $executor->executeRun($context, $this->runId);
            if ($run->state === 'reconcile') {
                $waitingStepId = $executor->firstRecoverableWaitingStepId($context, $this->runId);
                if ($waitingStepId !== null) {
                    $this->keepRecoveryScheduled($executor, $context, $waitingStepId);
                }
            }

            return;
        }

        $run = $executor->executeRun($context, $this->runId);
        if ($run->state === 'reconcile') {
            $waitingStepId = $executor->firstRecoverableWaitingStepId($context, $this->runId);
            if ($waitingStepId !== null) {
                $this->keepRecoveryScheduled($executor, $context, $waitingStepId);
            }
        }
    }

    private function keepRecoveryScheduled(LaravelOfficeExecutor $executor, CallContext $context, int $stepId): void
    {
        if ($this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < $this->tries) {
            $this->release(5);

            return;
        }

        $executor->scheduleStepResume($context, $this->runId, $stepId, delaySeconds: 5);
    }
}
