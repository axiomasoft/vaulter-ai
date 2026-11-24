<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Read;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ContentProtectionPolicy;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Schema\EditorContextSchema;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Contracts\LiveTextJournal;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Ai\Documents\Projection\ExtractedTextBlock;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Content\TipTap\TipTapSchema;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Vaulter;
use Vaulter\Support\Identity;

/**
 * Authorized ResourceReader for rich-text documents supporting text_outline and text_block selectors
 * with projection snapshot consistency, restore generation fencing, and after_receipt checks.
 */
final readonly class TextReader implements ResourceReader
{
    public function __construct(
        private Vaulter $vaulter,
        private DocumentsManager $documents,
        private DocumentScopeResolver $scopes,
        private TextProjectionReader $projection,
        private RichTextBlockExtractor $extractor,
        private TextCommands $commands,
        private PayloadCipher $cipher,
        private Clock $clock,
        private ConfigRepository $config,
        private ?LiveTextJournal $live = null,
        private ?OfficeRegistry $registry = null,
    ) {}

    public function resourceType(): ResourceType
    {
        return ResourceType::Text;
    }

    public function read(
        CallContext $context,
        ResourceEntry $resource,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?string $afterReceipt,
    ): ReadPage {
        $kind = property_exists($selector, 'kind') ? $selector->kind : null;
        if (! is_string($kind) || ! in_array($kind, ['text_outline', 'text_block'], true)) {
            throw OfficeException::invalidInput('TextReader only accepts text_outline or text_block selectors.');
        }

        $actor = $context->actor;
        if (! $actor instanceof Authorizable) {
            throw OfficeException::authenticationRequired();
        }

        $driveId = $resource->driveId ?? $context->driveId;
        if (! is_string($driveId) || $driveId === '') {
            throw OfficeException::resourceUnavailable();
        }

        $drive = $this->vaulter->findDriveById($driveId);
        if ($drive === null) {
            throw OfficeException::resourceUnavailable();
        }

        $actingDrive = $drive->actingAs($actor);

        try {
            $meta = $this->documents->in($actingDrive)->metadata($resource->resourceId);
        } catch (NodeNotFoundException|PermissionDeniedException|DriveArchivedException) {
            throw OfficeException::resourceUnavailable();
        } catch (DocumentTypeUnavailableException) {
            throw OfficeException::unsupportedContent();
        }

        if ($meta->type !== 'vaulter/rich-text') {
            throw OfficeException::unsupportedContent('Document type does not support addressable rich text.');
        }

        $loadLiveSnapshotAndBlocks = function () use ($actingDrive, $resource): array {
            try {
                $snapshot = $this->documents->in($actingDrive)->content($resource->resourceId);
            } catch (NodeNotFoundException|PermissionDeniedException|DriveArchivedException) {
                throw OfficeException::resourceUnavailable();
            } catch (DocumentStatePendingException) {
                throw OfficeException::projectionPending();
            } catch (DocumentTypeUnavailableException|DocumentContentDegradedException) {
                throw OfficeException::unsupportedContent();
            }

            if ($snapshot->type !== 'vaulter/rich-text' || ! $snapshot->content instanceof TipTapContent) {
                throw OfficeException::unsupportedContent('Document type does not support addressable rich text.');
            }

            // Validate document block identity invariants on read (never writing).
            $liveBlocks = $this->extractor->extract($snapshot->content->toArray());

            return [$snapshot, $liveBlocks];
        };

        $live = $this->live ?? $this->registry?->ownerFor(ReplaceExact::COMMAND, 'journal');
        if ($live instanceof LiveTextJournal) {
            $liveReceipt = $afterReceipt === null ? null : $live->receiptMarker($context, $resource, $afterReceipt);
            if ($liveReceipt !== null && $liveReceipt->generation !== $meta->generation) {
                throw OfficeException::generationChanged();
            }
            $projection = $live->projected($context, $resource, $meta->generation, $meta->stateVersion);
            if ($projection !== null && ($afterReceipt === null || $liveReceipt !== null)) {
                [$snapshot, $liveBlocks] = $loadLiveSnapshotAndBlocks();

                return $this->serveFromLiveSnapshot($context, $snapshot, $liveBlocks, $kind, $selector, $limit, $cursor, $liveReceipt, $projection);
            }
            if ($liveReceipt !== null) {
                throw OfficeException::projectionPending();
            }
        }

        $scope = $this->scopes->forContext($context, $driveId);
        $documentKey = Identity::key($meta->documentId);
        if ($documentKey === null) {
            throw OfficeException::resourceUnavailable();
        }

        $requiredMarker = null;
        if ($afterReceipt !== null) {
            $receipt = $this->commands->findCommittedReceipt($context, $resource->resourceId, $afterReceipt);
            if ($receipt === null) {
                throw OfficeException::resourceUnavailable();
            }
            $requiredMarker = $receipt['marker'];
            if ($meta->generation > $requiredMarker->generation) {
                throw OfficeException::generationChanged('Document generation moved after receipt.');
            }
            if ($meta->generation < $requiredMarker->generation || $meta->stateVersion < $requiredMarker->position) {
                throw OfficeException::temporarilyUnavailable();
            }
        }

        $head = $this->projection->head($scope['tenant_id'], $scope['project_scope'], $documentKey);
        $useLiveFallback = (bool) $this->config->get('vaulter-ai-documents.read.live_fallback_on_lag', false);

        if ($head === null || $head['generation'] !== $meta->generation) {
            if ($useLiveFallback) {
                [$snapshot, $liveBlocks] = $loadLiveSnapshotAndBlocks();

                return $this->serveFromLiveSnapshot($context, $snapshot, $liveBlocks, $kind, $selector, $limit, $cursor, $requiredMarker);
            }
            throw OfficeException::projectionPending();
        }

        if ($requiredMarker !== null && $head['position'] < $requiredMarker->position) {
            if ($useLiveFallback) {
                [$snapshot, $liveBlocks] = $loadLiveSnapshotAndBlocks();

                return $this->serveFromLiveSnapshot($context, $snapshot, $liveBlocks, $kind, $selector, $limit, $cursor, $requiredMarker);
            }
            throw OfficeException::projectionPending();
        }

        return $this->serveFromProjection($context, $scope['tenant_id'], $scope['project_scope'], $documentKey, $resource, $meta->schemaVersion, $kind, $selector, $limit, $cursor, $requiredMarker);
    }

    private function serveFromProjection(
        CallContext $context,
        int|string $tenantKey,
        string $projectScope,
        int|string $documentKey,
        ResourceEntry $resource,
        int $schemaVersion,
        string $kind,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?Marker $requiredMarker,
    ): ReadPage {
        $schemaRevision = (string) $schemaVersion;

        if ($kind === 'text_outline') {
            $query = property_exists($selector, 'query') && is_string($selector->query) ? $selector->query : null;
            $effectiveLimit = $limit ?? $this->defaultOutlineLimit();
            $protection = ContentProtectionPolicy::resolve($context, $resource);
            $hiddenIds = $protection->isEmpty() ? [] : $this->hiddenBlockIdsForResource($context, $resource, $protection);

            $visibleBlocks = [];
            $pageCursor = $cursor;
            $latestMarker = null;
            $truncated = false;
            $nextCursor = null;
            $iterations = 0;

            do {
                $outline = $this->projection->readOutline(
                    context: $context,
                    tenantKey: $tenantKey,
                    projectScope: $projectScope,
                    documentId: $documentKey,
                    resourceId: $resource->resourceId,
                    query: $query,
                    limit: $effectiveLimit,
                    cursor: $pageCursor,
                );
                if ($outline === null) {
                    throw OfficeException::projectionPending();
                }

                $this->assertMarkerSatisfiesReceipt($requiredMarker, $outline['marker']);
                $latestMarker = $outline['marker'];
                $truncated = $outline['truncated'];
                $nextCursor = $outline['next_cursor'];

                foreach ($outline['blocks'] as $block) {
                    if ($hiddenIds !== [] && isset($hiddenIds[$block['block_id']])) {
                        continue;
                    }
                    $visibleBlocks[] = $block;
                    if (count($visibleBlocks) >= $effectiveLimit) {
                        break;
                    }
                }

                $pageCursor = $nextCursor;
                $iterations++;
            } while ($hiddenIds !== [] && count($visibleBlocks) < $effectiveLimit && $truncated && $nextCursor !== null && $iterations < 20);

            $blocksData = array_map(
                static fn (array $b): stdClass => (object) [
                    'block_id' => $b['block_id'],
                    'path' => $b['path'],
                    'snippet' => $b['snippet'],
                ],
                $visibleBlocks,
            );

            return new ReadPage(
                marker: $latestMarker,
                data: (object) [
                    'kind' => 'text_outline',
                    'blocks' => $blocksData,
                ],
                truncated: $truncated,
                nextCursor: $nextCursor,
                selectorKind: 'text_outline',
                schemaRevision: $schemaRevision,
                completeScope: ! $truncated && $cursor === null,
                selectorDependencies: ['query' => $query],
            );
        }

        $blockId = property_exists($selector, 'block_id') && is_string($selector->block_id) ? $selector->block_id : '';
        if ($blockId === '') {
            throw OfficeException::invalidInput('text_block.block_id is required.');
        }

        $found = $this->projection->readBlock($tenantKey, $projectScope, $documentKey, $blockId);
        if ($found === null) {
            if ($requiredMarker !== null) {
                $latestHead = $this->projection->head($tenantKey, $projectScope, $documentKey);
                if ($latestHead !== null && $latestHead['generation'] > $requiredMarker->generation) {
                    throw OfficeException::generationChanged('Document generation moved after receipt.');
                }
            }
            throw OfficeException::resourceUnavailable();
        }

        $this->assertMarkerSatisfiesReceipt($requiredMarker, $found['marker']);

        $protection = ContentProtectionPolicy::resolve($context, $resource);
        if (! $protection->isEmpty()) {
            if ($protection->modeForNode($found['block']) === 'hidden') {
                throw OfficeException::resourceUnavailable();
            }
            if ($protection->mayMatchContainers()) {
                $hiddenIds = $this->hiddenBlockIdsForResource($context, $resource, $protection);
                if (isset($hiddenIds[$blockId])) {
                    throw OfficeException::resourceUnavailable();
                }
            }
        }

        $segmentObjects = array_map(
            static fn (array $seg): stdClass => (object) [
                'text' => $seg['text'],
                'marks' => $seg['marks'],
            ],
            $found['segments'],
        );

        return new ReadPage(
            marker: $found['marker'],
            data: (object) [
                'kind' => 'text_block',
                'block_id' => $blockId,
                'segments' => $segmentObjects,
            ],
            truncated: false,
            nextCursor: null,
            selectorKind: 'text_block',
            schemaRevision: $schemaRevision,
            completeScope: true,
            selectorDependencies: [
                'block_id' => $blockId,
                'formatted_node' => $found['block'],
                'schema_version' => $schemaVersion,
            ],
        );
    }

    /**
     * Serve authorized blocks directly from current Documents snapshot when host enables live fallback on lag.
     *
     * @param  list<ExtractedTextBlock>  $blocks
     */
    private function serveFromLiveSnapshot(
        CallContext $context,
        DocumentSnapshot $snapshot,
        array $blocks,
        string $kind,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?Marker $requiredMarker,
        ?Marker $position = null,
    ): ReadPage {
        $marker = $position ?? new Marker($snapshot->epoch, $snapshot->stateVersion);
        $this->assertMarkerSatisfiesReceipt($requiredMarker, $marker);
        $schemaRevision = (string) $snapshot->schemaVersion;
        $protection = ContentProtectionPolicy::resolve($context);
        $hiddenModes = $protection->isEmpty()
            ? []
            : array_filter(
                RichTextBlockExtractor::protectedBlockModes($snapshot->content->toArray(), $protection),
                static fn (string $mode): bool => $mode === 'hidden',
            );

        if ($kind === 'text_outline') {
            $query = property_exists($selector, 'query') && is_string($selector->query) ? trim($selector->query) : '';
            $splitTerms = $query === '' ? [] : preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
            $terms = is_array($splitTerms) ? array_values(array_unique($splitTerms)) : [];

            $matched = [];
            foreach ($blocks as $block) {
                if (isset($hiddenModes[$block->blockId])) {
                    continue;
                }
                if ($query !== '') {
                    if ($terms === []) {
                        continue;
                    }
                    $haystack = mb_strtolower($block->searchText);
                    $ok = true;
                    foreach ($terms as $t) {
                        if (! str_contains($haystack, $t)) {
                            $ok = false;
                            break;
                        }
                    }
                    if (! $ok) {
                        continue;
                    }
                }
                $matched[] = $block;
            }

            $afterOrdinal = -1;
            if ($cursor !== null) {
                $afterOrdinal = $this->decodeLiveCursor($context, $snapshot, $query, $cursor);
            }

            $filtered = array_values(array_filter(
                $matched,
                static fn (ExtractedTextBlock $b): bool => $b->ordinal > $afterOrdinal,
            ));

            $effectiveLimit = $limit ?? $this->defaultOutlineLimit();
            $hasMore = count($filtered) > $effectiveLimit;
            $slice = array_slice($filtered, 0, $effectiveLimit);

            $maxChars = $this->config->get('vaulter-ai-documents.read.snippet_max_chars', 140);
            $snippetLimit = is_int($maxChars) && $maxChars > 10 ? $maxChars : 140;

            $outlineBlocks = [];
            $lastOrdinal = $afterOrdinal;
            foreach ($slice as $b) {
                $rawText = trim((string) preg_replace('/\s+/u', ' ', $b->plainText !== '' ? $b->plainText : $b->searchText));
                $snippet = mb_strlen($rawText) <= $snippetLimit
                    ? $rawText
                    : rtrim(mb_substr($rawText, 0, $snippetLimit - 1)).'…';

                $outlineBlocks[] = (object) [
                    'block_id' => $b->blockId,
                    'path' => $b->headingPath,
                    'snippet' => $snippet,
                ];
                $lastOrdinal = $b->ordinal;
            }

            $nextCursor = $hasMore ? $this->encodeLiveCursor($context, $snapshot, $query, $lastOrdinal) : null;

            return new ReadPage(
                marker: $marker,
                data: (object) [
                    'kind' => 'text_outline',
                    'blocks' => $outlineBlocks,
                ],
                truncated: $hasMore,
                nextCursor: $nextCursor,
                selectorKind: 'text_outline',
                schemaRevision: $schemaRevision,
                completeScope: ! $hasMore && $cursor === null,
                selectorDependencies: ['query' => $query === '' ? null : $query],
            );
        }

        $blockId = property_exists($selector, 'block_id') && is_string($selector->block_id) ? $selector->block_id : '';
        if ($blockId === '') {
            throw OfficeException::invalidInput('text_block.block_id is required.');
        }
        if (isset($hiddenModes[$blockId])) {
            throw OfficeException::resourceUnavailable();
        }

        $target = null;
        foreach ($blocks as $block) {
            if ($block->blockId === $blockId) {
                $target = $block;
                break;
            }
        }

        if ($target === null) {
            throw OfficeException::resourceUnavailable();
        }

        $segmentObjects = array_map(
            static fn (array $seg): stdClass => (object) [
                'text' => $seg['text'],
                'marks' => $seg['marks'],
            ],
            $target->wireSegments(),
        );

        return new ReadPage(
            marker: $marker,
            data: (object) [
                'kind' => 'text_block',
                'block_id' => $blockId,
                'segments' => $segmentObjects,
            ],
            truncated: false,
            nextCursor: null,
            selectorKind: 'text_block',
            schemaRevision: $schemaRevision,
            completeScope: true,
            selectorDependencies: [
                'block_id' => $blockId,
                'formatted_node' => $target->formattedNode,
                'schema_version' => $snapshot->schemaVersion,
            ],
        );
    }

    private function assertMarkerSatisfiesReceipt(?Marker $requiredMarker, Marker $actualMarker): void
    {
        if ($requiredMarker === null) {
            return;
        }

        if ($actualMarker->generation > $requiredMarker->generation) {
            throw OfficeException::generationChanged('Document generation moved after receipt.');
        }

        if ($actualMarker->generation < $requiredMarker->generation || $actualMarker->position < $requiredMarker->position) {
            throw OfficeException::projectionPending();
        }
    }

    private function defaultOutlineLimit(): int
    {
        $configured = $this->config->get('vaulter-ai-documents.read.default_outline_limit', 20);

        return is_int($configured) && $configured > 0 ? $configured : 20;
    }

    private function encodeLiveCursor(CallContext $context, DocumentSnapshot $snapshot, string $query, int $lastOrdinal): string
    {
        $ttl = $this->config->get('vaulter-ai.limits.read_token_ttl_seconds', 900);
        $ttlSeconds = is_int($ttl) && $ttl > 0 ? $ttl : 900;
        $payload = rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'g' => $snapshot->epoch,
            'p' => $snapshot->stateVersion,
            'o' => $lastOrdinal,
            'q' => hash('sha256', $query),
            'exp' => $this->clock->now()->getTimestamp() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $mac = rtrim(strtr(base64_encode($this->cipher->signMac(implode("\0", [
            'live-outline-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $snapshot->nodeId,
        ]))), '+/', '-_'), '=');

        return $payload.'.'.$mac;
    }

    private function decodeLiveCursor(CallContext $context, DocumentSnapshot $snapshot, string $query, string $cursor): int
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OfficeException::cursorExpired();
        }

        [$payload, $encodedMac] = $parts;
        $expectedMac = rtrim(strtr(base64_encode($this->cipher->signMac(implode("\0", [
            'live-outline-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $snapshot->nodeId,
        ]))), '+/', '-_'), '=');

        if (! hash_equals($expectedMac, $encodedMac)) {
            throw OfficeException::cursorExpired();
        }

        $pad = (4 - (strlen($payload) % 4)) % 4;
        $decoded = base64_decode(strtr($payload.str_repeat('=', $pad), '-_', '+/'), true);
        if ($decoded === false) {
            throw OfficeException::cursorExpired();
        }

        $data = json_decode($decoded, true);
        if (
            ! is_array($data)
            || ($data['g'] ?? null) !== $snapshot->epoch
            || ($data['p'] ?? null) !== $snapshot->stateVersion
            || ! is_int($data['o'] ?? null)
            || ! is_string($data['q'] ?? null)
            || ! hash_equals($data['q'], hash('sha256', $query))
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] <= $this->clock->now()->getTimestamp()
        ) {
            throw OfficeException::cursorExpired();
        }

        return $data['o'];
    }

    /**
     * Build a versioned EditorContextSchema (getEditorContext contract) for a rich-text resource,
     * stripping DOM hooks and filtering hidden nodes.
     */
    public function editorContext(
        CallContext $context,
        ResourceEntry $resource,
        ?ContentProtectionPolicy $override = null,
    ): EditorContextSchema {
        $protection = ContentProtectionPolicy::resolve($context, $resource, $override);
        $schema = TipTapSchema::richText();

        $nodeSpecs = [];
        foreach ($schema->nodeTypes() as $nodeType) {
            $attrs = [];
            foreach ($schema->attributes($nodeType) as $attrName) {
                $attrs[$attrName] = ['type' => 'string', 'nullable' => true];
            }
            $nodeSpecs[$nodeType] = [
                'attrs' => $attrs,
            ];
        }

        $markSpecs = [];
        foreach ($schema->markTypes() as $markType) {
            $attrs = [];
            foreach ($schema->attributes($markType) as $attrName) {
                $attrs[$attrName] = ['type' => 'string', 'nullable' => true];
            }
            $markSpecs[$markType] = [
                'attrs' => $attrs,
            ];
        }

        $snapshot = $this->snapshotForResource($context, $resource);
        $items = RichTextBlockExtractor::sanitizedItemsForContext($snapshot->content->toArray(), $protection);

        return EditorContextSchema::fromSpecs($nodeSpecs, $markSpecs, $protection, $items);
    }

    /**
     * @return array<string, true>
     */
    private function hiddenBlockIdsForResource(
        CallContext $context,
        ResourceEntry $resource,
        ContentProtectionPolicy $protection,
    ): array {
        $snapshot = $this->snapshotForResource($context, $resource);
        $modes = RichTextBlockExtractor::protectedBlockModes($snapshot->content->toArray(), $protection);
        $hidden = [];
        foreach ($modes as $id => $mode) {
            if ($mode === 'hidden') {
                $hidden[$id] = true;
            }
        }

        return $hidden;
    }

    private function snapshotForResource(CallContext $context, ResourceEntry $resource): DocumentSnapshot
    {
        $driveId = $resource->driveId ?? $context->driveId;
        $drive = $driveId !== null ? $this->vaulter->findDriveById($driveId) : null;
        if (! $context->actor instanceof Authorizable || $drive === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $this->documents->in($drive->actingAs($context->actor))->content($resource->resourceId);
    }
}
