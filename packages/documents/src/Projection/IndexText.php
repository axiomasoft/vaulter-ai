<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use JsonException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Vaulter;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\Tenancy;

/**
 * Builds and atomically publishes addressable text projections into v_ai_text_projection_heads
 * and v_ai_text_blocks, fencing stale generations, deduplicating identical positions, and
 * catching up missed updates from authoritative Vaulter Documents state.
 */
final readonly class IndexText
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseOptions $vaulterDbOptions,
        private RichTextBlockExtractor $extractor,
        private DocumentScopeResolver $scopes,
        private OfficeDatabase $officeDb,
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private ConfigRepository $config,
    ) {}

    public function handle(DocumentCreated|DocumentContentUpdated|DocumentRestored|NodeRestored $event): void
    {
        if (! (bool) $this->config->get('vaulter-ai-documents.projection.auto_index', true)) {
            return;
        }

        match (true) {
            $event instanceof DocumentCreated => $this->handleDocumentCreated($event),
            $event instanceof DocumentContentUpdated => $this->handleDocumentContentUpdated($event),
            $event instanceof DocumentRestored => $this->handleDocumentRestored($event),
            $event instanceof NodeRestored => $this->handleNodeRestored($event),
        };
    }

    public function handleDocumentCreated(DocumentCreated $event): void
    {
        $scope = $this->scopes->forDriveEvent($event->scope()->tenantId, $event->node->driveId);
        $this->projectNodeWithCurrentContext(
            driveId: $event->node->driveId,
            nodeId: $event->node->id,
            tenantKey: $scope['tenant_id'],
            projectScope: $scope['project_scope'],
            minGeneration: $event->epoch,
        );
    }

    public function handleDocumentContentUpdated(DocumentContentUpdated $event): void
    {
        $scope = $this->scopes->forDriveEvent($event->scope()->tenantId, $event->node->driveId);
        $this->projectNodeWithCurrentContext(
            driveId: $event->node->driveId,
            nodeId: $event->node->id,
            tenantKey: $scope['tenant_id'],
            projectScope: $scope['project_scope'],
            minGeneration: $event->epoch,
        );
    }

    public function handleDocumentRestored(DocumentRestored $event): void
    {
        $scope = $this->scopes->forDriveEvent($event->scope()->tenantId, $event->node->driveId);
        $this->projectNodeWithCurrentContext(
            driveId: $event->node->driveId,
            nodeId: $event->node->id,
            tenantKey: $scope['tenant_id'],
            projectScope: $scope['project_scope'],
            minGeneration: $event->epoch,
        );
    }

    public function handleNodeRestored(NodeRestored $event): void
    {
        if ($event->node->kind !== NodeKind::Document) {
            return;
        }

        $scope = $this->scopes->forDriveEvent($event->scope()->tenantId, $event->node->driveId);
        $this->projectNodeWithCurrentContext(
            driveId: $event->node->driveId,
            nodeId: $event->node->id,
            tenantKey: $scope['tenant_id'],
            projectScope: $scope['project_scope'],
        );
    }

    /**
     * Index or catch up a single document node via Vaulter's public Documents API (admin/CLI path).
     */
    public function projectNode(
        string|int $driveId,
        string|int $nodeId,
        int|string $tenantKey,
        string $projectScope,
        ?int $minGeneration = null,
    ): ?string {
        $snapshot = Tenancy::without(fn (): ?DocumentSnapshot => $this->loadSnapshot($driveId, $nodeId));

        if ($snapshot === null) {
            return null;
        }

        return $this->publishSnapshot($tenantKey, $projectScope, $snapshot, $minGeneration);
    }

    public function projectRestoredNode(
        string|int $driveId,
        string|int $nodeId,
        int|string $tenantKey,
        string $projectScope,
    ): int {
        return Tenancy::without(function () use ($driveId, $nodeId, $tenantKey, $projectScope): int {
            $drive = $this->vaulter->findDriveById($driveId);
            if ($drive === null) {
                return 0;
            }
            $systemDrive = $drive->asSystem('vaulter-ai.index-text');

            try {
                $node = $systemDrive->nodes()->find($nodeId);
            } catch (NodeNotFoundException|DriveArchivedException) {
                return 0;
            }

            if ($node->kind === NodeKind::Document) {
                $snapshot = $this->loadSnapshotFromSystemDrive($systemDrive, $node->id);
                if ($snapshot === null) {
                    return 0;
                }

                return $this->publishSnapshot($tenantKey, $projectScope, $snapshot) !== null ? 1 : 0;
            }

            if ($node->kind === NodeKind::Folder) {
                return $this->indexSubtree($systemDrive, $node->id, $tenantKey, $projectScope, force: false);
            }

            return 0;
        });
    }

    private function projectNodeWithCurrentContext(
        string|int $driveId,
        string|int $nodeId,
        int|string $tenantKey,
        string $projectScope,
        ?int $minGeneration = null,
    ): ?string {
        $snapshot = $this->loadSnapshot($driveId, $nodeId);

        if ($snapshot === null) {
            return null;
        }

        return $this->publishSnapshot($tenantKey, $projectScope, $snapshot, $minGeneration);
    }

    private function loadSnapshot(string|int $driveId, string|int $nodeId): ?DocumentSnapshot
    {
        $drive = $this->vaulter->findDriveById($driveId);
        if ($drive === null) {
            return null;
        }

        return $this->loadSnapshotFromSystemDrive($drive->asSystem('vaulter-ai.index-text'), $nodeId);
    }

    private function loadSnapshotFromSystemDrive(DriveHandle $systemDrive, string|int $nodeId): ?DocumentSnapshot
    {
        try {
            return $this->documents->in($systemDrive)->content($nodeId);
        } catch (
            NodeNotFoundException
            |NotADocumentException
            |DriveArchivedException
            |DocumentTypeUnavailableException
            |DocumentContentDegradedException
        ) {
            return null;
        }
    }

    /**
     * Atomically publish a pre-fetched DocumentSnapshot if its (generation, position) advances
     * the currently published projection head without regressing across a restore generation boundary.
     */
    public function publishSnapshot(
        int|string $tenantKey,
        string $projectScope,
        DocumentSnapshot $snapshot,
        ?int $eventGeneration = null,
        bool $forceRebuild = false,
    ): ?string {
        if ($snapshot->type !== 'vaulter/rich-text' || ! $snapshot->content instanceof TipTapContent) {
            return null;
        }

        $blocks = $this->extractor->extract($snapshot->content->toArray());
        $projectorVersion = $this->projectorVersion();
        $buildId = (string) Str::uuid7();
        $now = $this->officeDb->utcTimestamp(new DateTimeImmutable('now', new DateTimeZone('UTC')), $this->database->driver());

        $headsTable = $this->vaulterDbOptions->table('ai_text_projection_heads');
        $blocksTable = $this->vaulterDbOptions->table('ai_text_blocks');
        $documentKey = Identity::key($snapshot->documentId);
        if ($documentKey === null) {
            return null;
        }

        return $this->database->transaction(function () use (
            $tenantKey,
            $projectScope,
            $snapshot,
            $documentKey,
            $eventGeneration,
            $forceRebuild,
            $blocks,
            $projectorVersion,
            $buildId,
            $now,
            $headsTable,
            $blocksTable,
        ): ?string {
            $conn = $this->database->connection();
            $this->database->advisoryLock("vaulter-ai:text-projection:{$tenantKey}:{$projectScope}:{$snapshot->documentId}");

            $head = $conn->table($headsTable)
                ->where('tenant_id', $tenantKey)
                ->where('project_scope', $projectScope)
                ->where('document_id', $documentKey)
                ->lockForUpdate()
                ->first(['source_generation', 'source_position', 'build_id', 'projector_version']);

            if ($head !== null) {
                $headGen = QueryRecord::int($head, 'source_generation');
                $headPos = QueryRecord::int($head, 'source_position');
                $headProjVersion = QueryRecord::int($head, 'projector_version');

                if ($eventGeneration !== null && $headGen > $eventGeneration) {
                    return null;
                }

                if ($headGen > $snapshot->epoch) {
                    return null;
                }

                if ($headGen === $snapshot->epoch) {
                    if ($headPos > $snapshot->stateVersion) {
                        return null;
                    }

                    if (
                        ! $forceRebuild
                        && $headPos === $snapshot->stateVersion
                        && $headProjVersion >= $projectorVersion
                    ) {
                        return null;
                    }
                }
            }

            $incremental = $head !== null
                && QueryRecord::int($head, 'source_generation') === $snapshot->epoch
                && ! $forceRebuild;

            if ($incremental) {
                $buildId = QueryRecord::str($head, 'build_id');
                $this->syncBlocksIncremental(
                    conn: $conn,
                    blocksTable: $blocksTable,
                    tenantKey: $tenantKey,
                    projectScope: $projectScope,
                    documentKey: $documentKey,
                    generation: $snapshot->epoch,
                    buildId: $buildId,
                    blocks: $blocks,
                    now: $now,
                );
            } elseif ($blocks !== []) {
                $rows = [];
                foreach ($blocks as $block) {
                    $rows[] = $this->encodeBlockRow(
                        $tenantKey,
                        $projectScope,
                        $documentKey,
                        $snapshot->epoch,
                        $buildId,
                        $block,
                        $now,
                    );
                }

                foreach (array_chunk($rows, 200) as $chunk) {
                    $conn->table($blocksTable)->insert($chunk);
                }
            }

            $key = [
                'tenant_id' => $tenantKey,
                'project_scope' => $projectScope,
                'document_id' => $documentKey,
            ];
            $values = [
                'source_generation' => $snapshot->epoch,
                'source_position' => $snapshot->stateVersion,
                'build_id' => $buildId,
                'projector_version' => $projectorVersion,
                'published_at' => $now,
            ];

            if ($head === null) {
                $conn->table($headsTable)->insert($key + $values);
            } else {
                $conn->table($headsTable)->where($key)->update($values);
            }

            $conn->table($blocksTable)
                ->where('tenant_id', $tenantKey)
                ->where('project_scope', $projectScope)
                ->where('document_id', $documentKey)
                ->where(static function (Builder $q) use ($snapshot, $buildId): void {
                    $q->where('source_generation', '!=', $snapshot->epoch)
                        ->orWhere('build_id', '!=', $buildId);
                })
                ->delete();

            return $buildId;
        });
    }

    /**
     * @param  list<ExtractedTextBlock>  $blocks
     */
    private function syncBlocksIncremental(
        ConnectionInterface $conn,
        string $blocksTable,
        int|string $tenantKey,
        string $projectScope,
        int|string $documentKey,
        int $generation,
        string $buildId,
        array $blocks,
        string $now,
    ): void {
        $baseScope = [
            'tenant_id' => $tenantKey,
            'project_scope' => $projectScope,
            'document_id' => $documentKey,
            'source_generation' => $generation,
            'build_id' => $buildId,
        ];

        $existingRows = $conn->table($blocksTable)
            ->where($baseScope)
            ->get(['block_id', 'parent_id', 'ordinal', 'node_kind', 'heading_path', 'source_hash']);

        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[QueryRecord::str($row, 'block_id')] = $row;
        }

        $incomingById = [];
        foreach ($blocks as $block) {
            $incomingById[$block->blockId] = $block;
        }

        $deleteIds = [];
        foreach (array_keys($existingById) as $existingId) {
            if (! isset($incomingById[$existingId])) {
                $deleteIds[] = $existingId;
            }
        }

        $inPlaceUpdates = [];
        $toInsert = [];
        foreach ($blocks as $block) {
            $row = $this->encodeBlockRow($tenantKey, $projectScope, $documentKey, $generation, $buildId, $block, $now);
            $existing = $existingById[$block->blockId] ?? null;
            if ($existing === null) {
                $toInsert[] = $row;

                continue;
            }

            $sameOrdinal = QueryRecord::int($existing, 'ordinal') === $block->ordinal;
            $sameParent = QueryRecord::raw($existing, 'parent_id') === $block->parentId;
            $sameKind = QueryRecord::str($existing, 'node_kind') === $block->nodeKind;
            $sameHeading = QueryRecord::str($existing, 'heading_path') === $row['heading_path'];
            $sameHash = $this->officeDb->decodeBinary(QueryRecord::raw($existing, 'source_hash')) === $block->sourceHashBinary;

            if ($sameOrdinal && $sameParent && $sameKind && $sameHeading && $sameHash) {
                continue;
            }

            if ($sameOrdinal) {
                $inPlaceUpdates[$block->blockId] = [
                    'parent_id' => $row['parent_id'],
                    'node_kind' => $row['node_kind'],
                    'heading_path' => $row['heading_path'],
                    'search_text' => $row['search_text'],
                    'formatted_node' => $row['formatted_node'],
                    'source_hash' => $row['source_hash'],
                    'created_at' => $row['created_at'],
                ];
            } else {
                // Delete and re-insert ordinal-shifted rows to avoid UNIQUE(..., ordinal) collisions.
                $deleteIds[] = $block->blockId;
                $toInsert[] = $row;
            }
        }

        foreach (array_chunk($deleteIds, 200) as $chunk) {
            $conn->table($blocksTable)->where($baseScope)->whereIn('block_id', $chunk)->delete();
        }

        foreach ($inPlaceUpdates as $blockId => $values) {
            $conn->table($blocksTable)
                ->where($baseScope)
                ->where('block_id', $blockId)
                ->update($values);
        }

        foreach (array_chunk($toInsert, 200) as $chunk) {
            $conn->table($blocksTable)->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeBlockRow(
        int|string $tenantKey,
        string $projectScope,
        int|string $documentKey,
        int $generation,
        string $buildId,
        ExtractedTextBlock $block,
        string $now,
    ): array {
        try {
            $headingJson = json_encode($block->headingPath, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $nodeJson = json_encode($block->formattedNode, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to encode projected text block JSON.', 0, $e);
        }

        return [
            'tenant_id' => $tenantKey,
            'project_scope' => $projectScope,
            'document_id' => $documentKey,
            'source_generation' => $generation,
            'build_id' => $buildId,
            'block_id' => $block->blockId,
            'parent_id' => $block->parentId,
            'ordinal' => $block->ordinal,
            'node_kind' => $block->nodeKind,
            'heading_path' => $headingJson,
            'search_text' => $block->searchText,
            'formatted_node' => $nodeJson,
            'source_hash' => $this->officeDb->binaryLiteral($block->sourceHashBinary),
            'created_at' => $now,
        ];
    }

    /**
     * Rebuild projections for every visible rich-text document in a drive subtree.
     */
    public function rebuildDrive(
        string|int $driveId,
        int|string $tenantKey,
        string $projectScope,
        bool $force = true,
    ): int {
        return Tenancy::without(function () use ($driveId, $tenantKey, $projectScope, $force): int {
            $drive = $this->vaulter->findDriveById($driveId);
            if ($drive === null) {
                return 0;
            }

            return $this->indexSubtree($drive->asSystem('vaulter-ai.rebuild-text-index'), null, $tenantKey, $projectScope, $force);
        });
    }

    private function indexSubtree(
        DriveHandle $systemDrive,
        string|int|null $rootFolderId,
        int|string $tenantKey,
        string $projectScope,
        bool $force,
    ): int {
        $indexed = 0;
        $queue = [$rootFolderId];
        while ($queue !== []) {
            $folderId = array_shift($queue);
            $cursor = null;
            do {
                $page = $systemDrive->nodes()->children(
                    $folderId,
                    ListOptions::make()->perPage(100)->cursor($cursor),
                );
                foreach ($page->items as $node) {
                    /** @var NodeSnapshot $node */
                    if ($node->kind === NodeKind::Folder) {
                        $queue[] = $node->id;

                        continue;
                    }
                    if ($node->kind === NodeKind::Document) {
                        $snapshot = $this->loadSnapshotFromSystemDrive($systemDrive, $node->id);
                        if ($snapshot !== null && $snapshot->type === 'vaulter/rich-text') {
                            if ($this->publishSnapshot($tenantKey, $projectScope, $snapshot, null, $force) !== null) {
                                $indexed++;
                            }
                        }
                    }
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
        }

        return $indexed;
    }

    private function projectorVersion(): int
    {
        $configured = $this->config->get('vaulter-ai-documents.projector_version', 1);

        return is_int($configured) && $configured > 0 ? $configured : 1;
    }
}
