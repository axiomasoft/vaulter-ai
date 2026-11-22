<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;

/**
 * Stateful in-memory ResourceDirectory fixture for Core-only Registry/Search/ReadToken tests.
 */
final class InMemoryResourceDirectory implements ResourceDirectory
{
    /** @var array<string, array{entry: ResourceEntry, tenant_id: string, project_scope: string, acl: array<string, list<string>>}> */
    private array $items = [];

    /**
     * @param  list<string>  $permissions
     */
    public function put(
        string $tenantId,
        string $projectScope,
        string $subject,
        ResourceEntry $entry,
        array $permissions = ['view', 'edit'],
    ): void {
        $key = $tenantId."\0".$projectScope."\0".$entry->resourceId;
        if (! isset($this->items[$key])) {
            $this->items[$key] = [
                'entry' => $entry,
                'tenant_id' => $tenantId,
                'project_scope' => $projectScope,
                'acl' => [],
            ];
        }
        $this->items[$key]['entry'] = $entry;
        $this->items[$key]['acl'][$subject] = $permissions;
    }

    public function revoke(string $tenantId, string $projectScope, string $subject, string $resourceId): void
    {
        $key = $tenantId."\0".$projectScope."\0".$resourceId;
        unset($this->items[$key]['acl'][$subject]);
    }

    /** @return list<ResourceEntry> */
    public function searchCandidates(
        CallContext $context,
        string $query,
        ?ResourceType $resourceType,
        int $maxCandidates,
    ): array {
        $matches = [];
        $qLower = mb_strtolower(trim($query));

        foreach ($this->items as $record) {
            if ($record['tenant_id'] !== $context->tenantId || $record['project_scope'] !== $context->projectScope) {
                continue;
            }
            $perms = $record['acl'][$context->effectiveSubject] ?? [];
            if (! in_array('view', $perms, true)) {
                continue;
            }
            $entry = $record['entry'];
            if ($resourceType !== null && $entry->resourceType !== $resourceType) {
                continue;
            }
            if ($qLower !== '' && ! str_contains(mb_strtolower($entry->title), $qLower)) {
                continue;
            }
            $matches[] = $entry;
            if (count($matches) >= $maxCandidates) {
                break;
            }
        }

        return $matches;
    }

    public function findAccessible(
        CallContext $context,
        string $resourceId,
        string $permission = 'view',
    ): ?ResourceEntry {
        $key = $context->tenantId."\0".$context->projectScope."\0".$resourceId;
        $record = $this->items[$key] ?? null;
        if ($record === null) {
            return null;
        }
        $perms = $record['acl'][$context->effectiveSubject] ?? [];

        return in_array($permission, $perms, true) ? $record['entry'] : null;
    }

    /**
     * @param  list<string>  $resourceIds
     * @return array<string, ResourceEntry>
     */
    public function filterAccessible(
        CallContext $context,
        array $resourceIds,
        string $permission = 'view',
    ): array {
        $result = [];
        foreach ($resourceIds as $resourceId) {
            $entry = $this->findAccessible($context, $resourceId, $permission);
            if ($entry !== null) {
                $result[$resourceId] = $entry;
            }
        }

        return $result;
    }
}
