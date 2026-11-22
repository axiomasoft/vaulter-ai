<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Contracts;

use Vaulter\Ai\Core\Data\CallContext;

/**
 * Maps trusted CallContext and Vaulter event scopes to stable projection partition keys.
 */
interface DocumentScopeResolver
{
    /**
     * Resolve storage keys for an active authorized request context.
     *
     * @return array{tenant_id: int|string, project_scope: string}
     */
    public function forContext(CallContext $context, ?string $driveId = null): array;

    /**
     * Resolve storage keys when projecting from a Vaulter domain event.
     *
     * @return array{tenant_id: int|string, project_scope: string}
     */
    public function forDriveEvent(int|string|null $eventTenantId, string $driveId): array;
}
