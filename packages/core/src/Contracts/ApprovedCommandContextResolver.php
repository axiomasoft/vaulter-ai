<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;

/** Host-authenticated lookup of a proposal's original execution context for a queue worker. */
interface ApprovedCommandContextResolver
{
    public function resolve(string $tenantId, string $proposalId): ?CallContext;
}
