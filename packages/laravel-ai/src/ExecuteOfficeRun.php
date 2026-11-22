<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Queue job carrying only tenant + run IDs; re-resolves trusted CallContext and HostProfile on each attempt.
 */
final class ExecuteOfficeRun implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'office-agent';

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [5, 15, 30];

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
            $executor->resumeStep($context, $this->runId, $this->resumeStepId);

            return;
        }

        $executor->executeRun($context, $this->runId);
    }
}
