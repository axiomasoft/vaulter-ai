<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Events\Nodes\NodeRestored;
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
        $systemDrive = $drive->asSystem('vaulter-ai.index-text');

        try {
            return $this->documents->in($systemDrive)->content($nodeId);
        } catch (\Throwable) {
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

                if (
                    ! $forceRebuild
                    && $headGen === $snapshot->epoch
                    && $headPos >= $snapshot->stateVersion
                    && $headProjVersion >= $projectorVersion
                ) {
                    return null;
                }
            }

            if ($blocks !== []) {
                $rows = [];
                foreach ($blocks as $block) {
                    try {
                        $headingJson = json_encode($block->headingPath, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $nodeJson = json_encode($block->formattedNode, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (JsonException $e) {
                        throw new \RuntimeException('Failed to encode projected text block JSON.', 0, $e);
                    }

                    $rows[] = [
                        'tenant_id' => $tenantKey,
                        'project_scope' => $projectScope,
                        'document_id' => $documentKey,
                        'source_generation' => $snapshot->epoch,
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
            $systemDrive = $drive->asSystem('vaulter-ai.rebuild-text-index');
            $indexed = 0;

            $queue = [null];
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
                            $snapshot = $this->documents->in($systemDrive)->content($node->id);
                            if ($snapshot->type === 'vaulter/rich-text') {
                                $this->publishSnapshot($tenantKey, $projectScope, $snapshot, null, $force);
                                $indexed++;
                            }
                        }
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
            }

            return $indexed;
        });
    }

    private function projectorVersion(): int
    {
        $configured = $this->config->get('vaulter-ai-documents.projector_version', 1);

        return is_int($configured) && $configured > 0 ? $configured : 1;
    }
}
