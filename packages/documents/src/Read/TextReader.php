<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Read;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Ai\Documents\Projection\ExtractedTextBlock;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Documents\Content\TipTap\TipTapContent;
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

        $scope = $this->scopes->forContext($context, $driveId);
        $documentKey = Identity::key($snapshot->documentId);
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
            if ($snapshot->epoch > $requiredMarker->generation) {
                throw OfficeException::generationChanged('Document generation moved after receipt.');
            }
            if ($snapshot->epoch < $requiredMarker->generation || $snapshot->stateVersion < $requiredMarker->position) {
                throw OfficeException::temporarilyUnavailable();
            }
        }

        $head = $this->projection->head($scope['tenant_id'], $scope['project_scope'], $documentKey);
        $useLiveFallback = (bool) $this->config->get('vaulter-ai-documents.read.live_fallback_on_lag', false);

        if ($head === null || $head['generation'] !== $snapshot->epoch) {
            if ($useLiveFallback) {
                return $this->serveFromLiveSnapshot($context, $snapshot, $liveBlocks, $kind, $selector, $limit, $cursor);
            }
            throw OfficeException::projectionPending();
        }

        if ($requiredMarker !== null && $head['position'] < $requiredMarker->position) {
            if ($useLiveFallback) {
                return $this->serveFromLiveSnapshot($context, $snapshot, $liveBlocks, $kind, $selector, $limit, $cursor);
            }
            throw OfficeException::projectionPending();
        }

        return $this->serveFromProjection($context, $scope['tenant_id'], $scope['project_scope'], $documentKey, $resource, $snapshot, $kind, $selector, $limit, $cursor);
    }

    private function serveFromProjection(
        CallContext $context,
        int|string $tenantKey,
        string $projectScope,
        int|string $documentKey,
        ResourceEntry $resource,
        DocumentSnapshot $snapshot,
        string $kind,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
    ): ReadPage {
        $schemaRevision = (string) $snapshot->schemaVersion;

        if ($kind === 'text_outline') {
            $query = property_exists($selector, 'query') && is_string($selector->query) ? $selector->query : null;
            $effectiveLimit = $limit ?? $this->defaultOutlineLimit();
            $outline = $this->projection->readOutline(
                context: $context,
                tenantKey: $tenantKey,
                projectScope: $projectScope,
                documentId: $documentKey,
                resourceId: $resource->resourceId,
                query: $query,
                limit: $effectiveLimit,
                cursor: $cursor,
            );
            if ($outline === null) {
                throw OfficeException::projectionPending();
            }

            $blocksData = array_map(
                static fn (array $b): stdClass => (object) [
                    'block_id' => $b['block_id'],
                    'path' => $b['path'],
                    'snippet' => $b['snippet'],
                ],
                $outline['blocks'],
            );

            return new ReadPage(
                marker: $outline['marker'],
                data: (object) [
                    'kind' => 'text_outline',
                    'blocks' => $blocksData,
                ],
                truncated: $outline['truncated'],
                nextCursor: $outline['next_cursor'],
                selectorKind: 'text_outline',
                schemaRevision: $schemaRevision,
                completeScope: ! $outline['truncated'] && $cursor === null,
                selectorDependencies: ['query' => $query],
            );
        }

        $blockId = property_exists($selector, 'block_id') && is_string($selector->block_id) ? $selector->block_id : '';
        if ($blockId === '') {
            throw OfficeException::invalidInput('text_block.block_id is required.');
        }

        $found = $this->projection->readBlock($tenantKey, $projectScope, $documentKey, $blockId);
        if ($found === null) {
            throw OfficeException::resourceUnavailable();
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
                'schema_version' => $snapshot->schemaVersion,
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
    ): ReadPage {
        $marker = new Marker($snapshot->epoch, $snapshot->stateVersion);
        $schemaRevision = (string) $snapshot->schemaVersion;

        if ($kind === 'text_outline') {
            $query = property_exists($selector, 'query') && is_string($selector->query) ? trim($selector->query) : '';
            $splitTerms = $query === '' ? [] : preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
            $terms = is_array($splitTerms) ? array_values(array_unique($splitTerms)) : [];

            $matched = [];
            foreach ($blocks as $block) {
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
}
