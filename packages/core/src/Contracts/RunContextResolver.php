<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;

/** Host-authenticated lookup of a background run's original execution context for a queue worker. */
interface RunContextResolver
{
    public function resolve(string $tenantId, string $runId): ?CallContext;
}
