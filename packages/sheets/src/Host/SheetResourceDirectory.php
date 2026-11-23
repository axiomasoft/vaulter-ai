<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Host;

use Generator;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\SearchOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Vaulter;

/**
 * Standalone ResourceDirectory resolving authorized Vaulter spreadsheet resources when vaulter-ai-sheets
 * is installed without vaulter-ai-documents (ADR-0030 compliant).
 */
final class SheetResourceDirectory implements ResourceDirectory
{
    /** @var array<string, array{tenant_id: string, project_scope: string}> */
    private array $driveScopes = [];

    public function __construct(
        private readonly Vaulter $vaulter,
        private readonly VaulterDatabase $vaulterDb,
        private readonly IdsOptions $ids,
        private readonly ConfigRepository $config,
    ) {}

    public function bindDriveScope(string $driveId, int|string $tenantId, string $projectScope): void
    {
        $this->driveScopes[$driveId] = [
            'tenant_id' => $this->normalizeTenantString((string) $tenantId),
            'project_scope' => $projectScope,
        ];
    }

    /** @return list<ResourceEntry> */
    public function searchCandidates(
        CallContext $context,
        string $query,
        ?ResourceType $resourceType,
        int $maxCandidates,
    ): array {
        if ($resourceType !== null && $resourceType !== ResourceType::Sheet) {
            return [];
        }

        $actor = $context->actor;
        if (! $actor instanceof Authorizable) {
            return [];
        }

        /** @var array<string, ResourceEntry> $found */
        $found = [];
        $trimmed = trim($query);
        $isPgsql = $this->vaulterDb->driver() === 'pgsql';

        foreach ($this->candidateDrives($context, $actor) as $drive) {
            $acting = $drive->actingAs($actor);

            $cursor = null;
            do {
                try {
                    $page = $acting->nodes()->search(
                        $query,
                        SearchOptions::make()->perPage(min(200, max(20, $maxCandidates)))->cursor($cursor),
                    );
                } catch (\Throwable) {
                    break;
                }

                foreach ($page->items as $node) {
                    /** @var NodeSnapshot $node */
                    $entry = $this->toEntry($node);
                    if ($entry !== null) {
                        $found[$entry->resourceId] = $entry;
                        if (count($found) >= $maxCandidates) {
                            return array_values($found);
                        }
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            if ($trimmed !== '' && ! $isPgsql) {
                $queryLower = mb_strtolower($trimmed);
                foreach ($this->visibleDocuments($acting) as $node) {
                    if (isset($found[$node->id])) {
                        continue;
                    }
                    if (! str_contains(mb_strtolower($node->name), $queryLower)) {
                        continue;
                    }
                    $entry = $this->toEntry($node);
                    if ($entry !== null) {
                        $found[$entry->resourceId] = $entry;
                        if (count($found) >= $maxCandidates) {
                            return array_values($found);
                        }
                    }
                }
            }
        }

        return array_values($found);
    }

    public function findAccessible(
        CallContext $context,
        string $resourceId,
        string $permission = 'view',
    ): ?ResourceEntry {
        $actor = $context->actor;
        if (! $actor instanceof Authorizable) {
            return null;
        }

        foreach ($this->candidateDrives($context, $actor) as $drive) {
            $acting = $drive->actingAs($actor);

            try {
                $node = $acting->nodes()->find($resourceId);
            } catch (NodeNotFoundException|PermissionDeniedException|DriveArchivedException) {
                continue;
            }

            $entry = $this->toEntry($node);
            if ($entry === null) {
                return null;
            }

            if ($permission !== Ability::View->value) {
                $abilities = $acting->nodes()->abilities($node);
                if (! ($abilities->flags[$permission] ?? false)) {
                    return null;
                }
            }

            return $entry;
        }

        return null;
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

    /** @return list<DriveHandle> */
    private function candidateDrives(CallContext $context, Authorizable $actor): array
    {
        if ($context->driveId !== null && $context->driveId !== '') {
            $drive = $this->vaulter->findDriveById($context->driveId);
            if ($drive === null || ! $this->matchesScope($context, $drive)) {
                return [];
            }

            return [$drive];
        }

        $drives = [];
        $cursor = null;
        do {
            $page = $this->vaulter->drives($actor, ListOptions::make()->perPage(50)->cursor($cursor));
            foreach ($page->items as $snapshot) {
                $handle = $this->vaulter->findDriveById($snapshot->id);
                if ($handle !== null && $this->matchesScope($context, $handle)) {
                    $drives[] = $handle;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return $drives;
    }

    private function matchesScope(CallContext $context, DriveHandle $drive): bool
    {
        $snapshot = $drive->snapshot();
        $callerTenant = $this->normalizeTenantString($context->tenantId);
        if (isset($this->driveScopes[$snapshot->id])) {
            $bound = $this->driveScopes[$snapshot->id];

            return $bound['tenant_id'] === $callerTenant && $bound['project_scope'] === $context->projectScope;
        }

        $driveTenant = $snapshot->tenantId !== null
            ? $this->normalizeTenantString($snapshot->tenantId)
            : $this->defaultTenantString();

        return $driveTenant === $callerTenant && $this->defaultProjectScope() === $context->projectScope;
    }

    private function normalizeTenantString(string $raw): string
    {
        if ($this->ids->hostKeys === 'bigint' && preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw OfficeException::resourceUnavailable();
        }

        return $raw;
    }

    private function defaultTenantString(): string
    {
        $configured = $this->config->get('vaulter-ai-sheets.default_tenant_id')
            ?? $this->config->get('vaulter-ai-documents.default_tenant_id');
        if (is_int($configured) || (is_string($configured) && $configured !== '')) {
            return (string) $configured;
        }

        return $this->ids->hostKeys === 'bigint' ? '0' : 'default';
    }

    private function defaultProjectScope(): string
    {
        $configured = $this->config->get('vaulter-ai-sheets.default_project_scope')
            ?? $this->config->get('vaulter-ai-documents.default_project_scope', 'default');

        return is_string($configured) && $configured !== '' ? $configured : 'default';
    }

    /** @return Generator<int, NodeSnapshot> */
    private function visibleDocuments(DriveHandle $actingDrive): Generator
    {
        $queue = [null];
        while ($queue !== []) {
            $folderId = array_shift($queue);
            $cursor = null;
            do {
                try {
                    $page = $actingDrive->nodes()->children(
                        $folderId,
                        ListOptions::make()->perPage(100)->cursor($cursor),
                    );
                } catch (NodeNotFoundException|PermissionDeniedException|DriveArchivedException) {
                    break;
                }
                foreach ($page->items as $node) {
                    /** @var NodeSnapshot $node */
                    if ($node->kind === NodeKind::Folder) {
                        $queue[] = $node->id;

                        continue;
                    }
                    if ($node->kind === NodeKind::Document && $node->document !== null) {
                        yield $node;
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        }
    }

    private function toEntry(NodeSnapshot $node): ?ResourceEntry
    {
        if ($node->kind !== NodeKind::Document || $node->document === null || $node->trashedAt !== null) {
            return null;
        }
        if ($node->document->type !== 'vaulter/spreadsheet') {
            return null;
        }

        return new ResourceEntry(
            resourceId: $node->id,
            resourceType: ResourceType::Sheet,
            title: $node->name,
            writeMode: null,
            schemaRevision: (string) $node->document->schemaVersion,
            driveId: $node->driveId,
        );
    }
}
