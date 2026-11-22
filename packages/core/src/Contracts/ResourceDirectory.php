<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;

/**
 * Resolves authorized resource candidates, metadata, and per-page ACL filtering via host ports.
 */
interface ResourceDirectory
{
    /**
     * Search authorized candidates in the caller's tenant/project scope.
     *
     * @return list<ResourceEntry>
     */
    public function searchCandidates(
        CallContext $context,
        string $query,
        ?ResourceType $resourceType,
        int $maxCandidates,
    ): array;

    /**
     * Resolve a single accessible resource if the caller currently holds $permission; null otherwise.
     */
    public function findAccessible(
        CallContext $context,
        string $resourceId,
        string $permission = 'view',
    ): ?ResourceEntry;

    /**
     * Batch-verify current access across candidate resource IDs for paginated output.
     *
     * @param  list<string>  $resourceIds
     * @return array<string, ResourceEntry> keyed by resourceId
     */
    public function filterAccessible(
        CallContext $context,
        array $resourceIds,
        string $permission = 'view',
    ): array;
}
