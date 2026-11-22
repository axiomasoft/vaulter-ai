<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;

/**
 * Execution adapter contract for background agent runtimes (e.g., Laravel AI, AgenTools).
 */
interface OfficeExecutor
{
    public function key(): string;

    /** @param array<string, mixed> $payload */
    public function dispatch(CallContext $context, array $payload): void;
}
