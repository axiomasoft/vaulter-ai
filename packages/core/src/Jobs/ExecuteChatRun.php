<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Workspace\ChatTurn;

final class ExecuteChatRun implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'office-agent';

    // Pending human approval is bounded by the session's retention deadline.
    public int $tries = 0;

    public int $maxExceptions = 5;

    public int $timeout = 75;

    public int $backoff = 30;

    public function __construct(public readonly string $tenantId, public readonly string $runId) {}

    public function handle(ChatTurn $turn, ?RunContextResolver $resolver = null, ?LlmPort $llm = null): void
    {
        if ($llm === null) {
            return;
        }
        $context = $resolver?->resolve($this->tenantId, $this->runId);
        if ($context === null || $context->tenantId !== $this->tenantId) {
            throw OfficeException::resourceUnavailable();
        }
        $retry = $turn->execute($context, $this->runId, $llm);
        if ($retry !== null && $this->job !== null && ! $this->job instanceof SyncJob) {
            $this->release($retry);
        }
    }
}
