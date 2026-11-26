<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResumableOfficeExecutor;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class ResumeOfficeRun implements ShouldQueue
{
    public string $queue;

    public function __construct(public string $tenantId, public string $runId)
    {
        $this->queue = 'office-agent';
    }

    public function handle(Runs $runs, HostPolicy $policy, OfficeRegistry $registry, Dispatcher $bus, ?RunContextResolver $resolver = null): void
    {
        $context = $resolver?->resolve($this->tenantId, $this->runId);
        if ($context === null || $context->tenantId !== $this->tenantId) {
            throw OfficeException::resourceUnavailable();
        }
        $run = $runs->find($context, $this->runId) ?? throw OfficeException::resourceUnavailable();
        if (in_array($run->state, ['completed', 'rejected', 'cancelled'], true)) {
            return;
        }
        if ($run->channel === 'chat') {
            $bus->dispatch(new ExecuteChatRun($this->tenantId, $this->runId));

            return;
        }
        $profile = $policy->profile($context);
        if ($run->profileRevision !== $profile->revision) {
            throw OfficeException::preconditionFailed();
        }
        $savedExecutor = $run->input['executor'] ?? null;
        $executor = is_string($savedExecutor)
            ? $registry->executor($savedExecutor)
            : ($registry->executor($run->channel) ?? $registry->executor($profile->executor));
        if (! $executor instanceof ResumableOfficeExecutor) {
            throw OfficeException::preconditionFailed();
        }
        $executor->executeRun($context, $this->runId);
    }
}
