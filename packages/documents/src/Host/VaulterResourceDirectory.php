<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Host;

use Generator;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\DocumentsManager;
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
use Vaulter\Support\Identity;

/**
 * Resolves authorized Vaulter Drive document resources for AI SDK search, read, and describe operations
 * strictly through Vaulter's public #[Api] boundary (ADR-0030 compliant).
 */
final readonly class VaulterResourceDirectory implements ResourceDirectory
{
    public function __construct(
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private DocumentScopeResolver $scopes,
        private TextProjectionReader $projection,
        private VaulterDatabase $vaulterDb,
    ) {}

    /**
     * @return list<ResourceEntry>
     */
    public function searchCandidates(
        CallContext $context,
        string $query,
        ?ResourceType $resourceType,
        int $maxCandidates,
    ): array {
        $actor = $context->actor;
        if (! $actor instanceof Authorizable) {
            return [];
        }

        /** @var array<string, ResourceEntry> $found */
        $found = [];
        $trimmed = trim($query);
        $isPgsql = $this->vaulterDb->driver() === 'pgsql';
        $searchTextProjection = $resourceType === null || $resourceType === ResourceType::Text;

        foreach ($this->candidateDrives($context, $actor) as $drive) {
            $acting = $drive->actingAs($actor);

            // 1. Filename / title matches through Drive public search API.
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
                    if ($entry !== null && ($resourceType === null || $entry->resourceType === $resourceType)) {
                        $found[$entry->resourceId] = $entry;
                        if (count($found) >= $maxCandidates) {
                            return array_values($found);
                        }
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);

            // 2. Full-text projection matches (+ Unicode case-insensitive title fallback on non-PG drivers)
            // over authorized visible documents.
            if ($trimmed !== '') {
                $scope = $this->scopes->forContext($context, $drive->snapshot()->id);
                $matchingDocIds = $searchTextProjection
                    ? $this->projection->searchMatchingDocumentIds(
                        $scope['tenant_id'],
                        $scope['project_scope'],
                        $trimmed,
                    )
                    : [];

                if ($matchingDocIds !== [] || ! $isPgsql) {
                    $docSet = [];
                    foreach ($matchingDocIds as $docId) {
                        $docSet[(string) $docId] = true;
                    }
                    $remainingDocs = $docSet;
                    $queryLower = mb_strtolower($trimmed);

                    foreach ($this->visibleDocuments($acting) as $node) {
                        if (isset($found[$node->id])) {
                            continue;
                        }

                        $entry = $this->toEntry($node);
                        if ($entry === null || ($resourceType !== null && $entry->resourceType !== $resourceType)) {
                            continue;
                        }

                        if (! $isPgsql && str_contains(mb_strtolower($node->name), $queryLower)) {
                            $found[$entry->resourceId] = $entry;
                            if (count($found) >= $maxCandidates) {
                                return array_values($found);
                            }

                            continue;
                        }

                        if ($docSet === []) {
                            continue;
                        }

                        // Only the document id is needed to match the projection hits; the
                        // metadata read skips the stored state and the content egress gate.
                        try {
                            $metadata = $this->documents->in($acting)->metadata($node->id);
                        } catch (\Throwable) {
                            continue;
                        }

                        $docKey = (string) (Identity::key($metadata->documentId) ?? $metadata->documentId);
                        if (isset($docSet[$docKey])) {
                            unset($remainingDocs[$docKey]);
                            $found[$entry->resourceId] = $entry;
                            if (count($found) >= $maxCandidates) {
                                return array_values($found);
                            }
                            if ($isPgsql && $remainingDocs === []) {
                                break;
                            }
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

        return $this->findIn($this->candidateDrives($context, $actor), $actor, $resourceId, $permission);
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
        $actor = $context->actor;
        if (! $actor instanceof Authorizable || $resourceIds === []) {
            return [];
        }

        // Resolve the caller's drives once for the whole batch, not once per id.
        $drives = $this->candidateDrives($context, $actor);
        $result = [];
        foreach ($resourceIds as $resourceId) {
            $entry = $this->findIn($drives, $actor, $resourceId, $permission);
            if ($entry !== null) {
                $result[$resourceId] = $entry;
            }
        }

        return $result;
    }

    /** @param  list<DriveHandle>  $drives */
    private function findIn(array $drives, Authorizable $actor, string $resourceId, string $permission): ?ResourceEntry
    {
        foreach ($drives as $drive) {
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
     * @return list<DriveHandle>
     */
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
        $driveScope = $this->scopes->forDriveEvent($snapshot->tenantId, $snapshot->id);
        $callerScope = $this->scopes->forContext($context, $snapshot->id);

        return (string) $driveScope['tenant_id'] === (string) $callerScope['tenant_id']
            && $driveScope['project_scope'] === $callerScope['project_scope'];
    }

    /**
     * @return Generator<int, NodeSnapshot>
     */
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

        $type = match ($node->document->type) {
            'vaulter/rich-text' => ResourceType::Text,
            'vaulter/spreadsheet' => ResourceType::Sheet,
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return new ResourceEntry(
            resourceId: $node->id,
            resourceType: $type,
            title: $node->name,
            writeMode: null,
            schemaRevision: (string) $node->document->schemaVersion,
            driveId: $node->driveId,
        );
    }
}
