<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\JoinClause;
use JsonException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;

/**
 * Snapshot-consistent reader of published AI text projections using single-query JOINs
 * against v_ai_text_projection_heads and v_ai_text_blocks.
 */
final readonly class TextProjectionReader
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseOptions $vaulterDbOptions,
        private PayloadCipher $cipher,
        private Clock $clock,
        private ConfigRepository $config,
    ) {}

    /**
     * @return array{generation: int, position: int, build_id: string, projector_version: int}|null
     */
    public function head(int|string $tenantKey, string $projectScope, string|int $documentId): ?array
    {
        $row = $this->database->connection()
            ->table($this->vaulterDbOptions->table('ai_text_projection_heads'))
            ->where('tenant_id', $tenantKey)
            ->where('project_scope', $projectScope)
            ->where('document_id', $documentId)
            ->first(['source_generation', 'source_position', 'build_id', 'projector_version']);

        if ($row === null) {
            return null;
        }

        return [
            'generation' => QueryRecord::int($row, 'source_generation'),
            'position' => QueryRecord::int($row, 'source_position'),
            'build_id' => QueryRecord::str($row, 'build_id'),
            'projector_version' => QueryRecord::int($row, 'projector_version'),
        ];
    }

    /**
     * Read outline / keyword-filtered entries bound to one published (generation, build_id) snapshot.
     *
     * @return array{marker: Marker, build_id: string, blocks: list<array{block_id: string, path: list<string>, snippet: string}>, truncated: bool, next_cursor: ?string}|null
     */
    public function readOutline(
        CallContext $context,
        int|string $tenantKey,
        string $projectScope,
        string|int $documentId,
        string $resourceId,
        ?string $query,
        int $limit,
        ?string $cursor,
    ): ?array {
        $head = $this->head($tenantKey, $projectScope, $documentId);
        if ($head === null) {
            return null;
        }

        $queryString = $query ?? '';
        $afterOrdinal = -1;
        if ($cursor !== null) {
            $cursorData = $this->decodeCursor($context, $cursor, $resourceId, $queryString);
            if ($cursorData['build_id'] !== $head['build_id'] || $cursorData['generation'] !== $head['generation']) {
                throw OfficeException::cursorExpired('Projection build changed; restart outline read.');
            }
            $afterOrdinal = $cursorData['last_ordinal'];
        }

        $headsTable = $this->vaulterDbOptions->table('ai_text_projection_heads');
        $blocksTable = $this->vaulterDbOptions->table('ai_text_blocks');

        $builder = $this->database->connection()
            ->table($blocksTable.' as b')
            ->join($headsTable.' as h', static function (JoinClause $join): void {
                $join->on('b.tenant_id', '=', 'h.tenant_id')
                    ->on('b.project_scope', '=', 'h.project_scope')
                    ->on('b.document_id', '=', 'h.document_id')
                    ->on('b.source_generation', '=', 'h.source_generation')
                    ->on('b.build_id', '=', 'h.build_id');
            })
            ->where('h.tenant_id', $tenantKey)
            ->where('h.project_scope', $projectScope)
            ->where('h.document_id', $documentId)
            ->where('h.build_id', $head['build_id'])
            ->where('h.source_generation', $head['generation'])
            ->where('b.ordinal', '>', $afterOrdinal)
            ->orderBy('b.ordinal');

        $trimmedQuery = trim($queryString);
        $driver = $this->database->driver();

        if ($trimmedQuery !== '' && $driver === 'pgsql') {
            $terms = $this->tokenize($trimmedQuery);
            if ($terms === []) {
                return [
                    'marker' => new Marker($head['generation'], $head['position']),
                    'build_id' => $head['build_id'],
                    'blocks' => [],
                    'truncated' => false,
                    'next_cursor' => null,
                ];
            }
            $builder->whereRaw("to_tsvector('simple'::regconfig, b.search_text) @@ plainto_tsquery('simple'::regconfig, ?)", [$trimmedQuery]);
        }

        $cols = ['b.block_id', 'b.ordinal', 'b.node_kind', 'b.heading_path', 'b.search_text', 'b.formatted_node', 'h.source_generation', 'h.source_position', 'h.build_id'];

        $entries = [];
        $lastOrdinal = $afterOrdinal;
        $hasMore = false;

        if ($trimmedQuery !== '' && $driver !== 'pgsql') {
            // SQLite does not support PG tsvector GIN; evaluate token matching deterministically over ordered blocks.
            $rows = $builder->get($cols);
            $terms = $this->tokenize($trimmedQuery);
            foreach ($rows as $row) {
                $searchLower = mb_strtolower(QueryRecord::str($row, 'search_text'));
                if (! $this->matchesAllTerms($searchLower, $terms)) {
                    continue;
                }
                if (count($entries) >= $limit) {
                    $hasMore = true;
                    break;
                }
                $entries[] = $this->formatOutlineEntry($row, $trimmedQuery);
                $lastOrdinal = QueryRecord::int($row, 'ordinal');
            }
        } else {
            $rows = $builder->limit($limit + 1)->get($cols);
            foreach ($rows as $row) {
                if (count($entries) >= $limit) {
                    $hasMore = true;
                    break;
                }
                $entries[] = $this->formatOutlineEntry($row, $trimmedQuery);
                $lastOrdinal = QueryRecord::int($row, 'ordinal');
            }
        }

        $nextCursor = $hasMore
            ? $this->encodeCursor($context, $resourceId, $queryString, $head['generation'], $head['build_id'], $lastOrdinal)
            : null;

        return [
            'marker' => new Marker($head['generation'], $head['position']),
            'build_id' => $head['build_id'],
            'blocks' => $entries,
            'truncated' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * Read a single formatted block atomically joined to the active published head.
     *
     * @return array{marker: Marker, block: array<string, mixed>, segments: list<array{text: string, marks: list<string>}>}|null
     */
    public function readBlock(
        int|string $tenantKey,
        string $projectScope,
        string|int $documentId,
        string $blockId,
    ): ?array {
        $headsTable = $this->vaulterDbOptions->table('ai_text_projection_heads');
        $blocksTable = $this->vaulterDbOptions->table('ai_text_blocks');

        $row = $this->database->connection()
            ->table($blocksTable.' as b')
            ->join($headsTable.' as h', static function (JoinClause $join): void {
                $join->on('b.tenant_id', '=', 'h.tenant_id')
                    ->on('b.project_scope', '=', 'h.project_scope')
                    ->on('b.document_id', '=', 'h.document_id')
                    ->on('b.source_generation', '=', 'h.source_generation')
                    ->on('b.build_id', '=', 'h.build_id');
            })
            ->where('h.tenant_id', $tenantKey)
            ->where('h.project_scope', $projectScope)
            ->where('h.document_id', $documentId)
            ->where('b.block_id', $blockId)
            ->first(['b.formatted_node', 'h.source_generation', 'h.source_position']);

        if ($row === null) {
            return null;
        }

        $decoded = json_decode(QueryRecord::str($row, 'formatted_node'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $segments = RichTextBlockExtractor::wireSegmentsFromFormattedNode($decoded);

        return [
            'marker' => new Marker(QueryRecord::int($row, 'source_generation'), QueryRecord::int($row, 'source_position')),
            'block' => $decoded,
            'segments' => $segments,
        ];
    }

    /**
     * Return matching document IDs from v_ai_text_blocks for search enrichment within a scope.
     *
     * @return list<string|int>
     */
    public function searchMatchingDocumentIds(
        int|string $tenantKey,
        string $projectScope,
        string $query,
        int $limit,
    ): array {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $headsTable = $this->vaulterDbOptions->table('ai_text_projection_heads');
        $blocksTable = $this->vaulterDbOptions->table('ai_text_blocks');

        $builder = $this->database->connection()
            ->table($blocksTable.' as b')
            ->join($headsTable.' as h', static function (JoinClause $join): void {
                $join->on('b.tenant_id', '=', 'h.tenant_id')
                    ->on('b.project_scope', '=', 'h.project_scope')
                    ->on('b.document_id', '=', 'h.document_id')
                    ->on('b.source_generation', '=', 'h.source_generation')
                    ->on('b.build_id', '=', 'h.build_id');
            })
            ->where('h.tenant_id', $tenantKey)
            ->where('h.project_scope', $projectScope);

        if ($this->database->driver() === 'pgsql') {
            $terms = $this->tokenize($trimmed);
            if ($terms === []) {
                return [];
            }

            $plucked = $builder
                ->whereRaw("to_tsvector('simple'::regconfig, b.search_text) @@ plainto_tsquery('simple'::regconfig, ?)", [$trimmed])
                ->select('h.document_id')
                ->distinct()
                ->limit($limit)
                ->pluck('h.document_id')
                ->all();

            $ids = [];
            foreach ($plucked as $docId) {
                if (is_int($docId) || is_string($docId)) {
                    $ids[] = $docId;
                }
            }

            return $ids;
        }

        $terms = $this->tokenize($trimmed);
        if ($terms === []) {
            return [];
        }

        $rows = $builder->get(['h.document_id', 'b.search_text']);
        $matched = [];
        foreach ($rows as $row) {
            if ($this->matchesAllTerms(mb_strtolower(QueryRecord::str($row, 'search_text')), $terms)) {
                $docId = QueryRecord::raw($row, 'document_id');
                if (is_int($docId) || is_string($docId)) {
                    $matched[(string) $docId] = $docId;
                }
                if (count($matched) >= $limit) {
                    break;
                }
            }
        }

        return array_values($matched);
    }

    /**
     * @return array{block_id: string, path: list<string>, snippet: string}
     */
    private function formatOutlineEntry(object $row, string $query): array
    {
        $headingRaw = json_decode(QueryRecord::str($row, 'heading_path', '[]'), true, 512, JSON_THROW_ON_ERROR);
        $path = [];
        if (is_array($headingRaw)) {
            foreach ($headingRaw as $item) {
                if (is_string($item) && $item !== '') {
                    $path[] = $item;
                }
            }
        }

        $nodeRaw = json_decode(QueryRecord::str($row, 'formatted_node', '{}'), true, 512, JSON_THROW_ON_ERROR);
        $plain = '';
        if (is_array($nodeRaw)) {
            /** @var array<string, mixed> $nodeRaw */
            $segments = RichTextBlockExtractor::wireSegmentsFromFormattedNode($nodeRaw);
            foreach ($segments as $segment) {
                $plain .= $segment['text'];
            }
        }

        $fallbackSearch = QueryRecord::str($row, 'search_text');

        return [
            'block_id' => QueryRecord::str($row, 'block_id'),
            'path' => $path,
            'snippet' => $this->buildSnippet($plain !== '' ? $plain : $fallbackSearch, $query),
        ];
    }

    private function buildSnippet(string $text, string $query): string
    {
        $maxChars = $this->config->get('vaulter-ai-documents.read.snippet_max_chars', 140);
        $limit = is_int($maxChars) && $maxChars > 10 ? $maxChars : 140;
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $limit - 1)).'…';
    }

    /** @return list<string> */
    private function tokenize(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_values(array_unique($parts)) : [];
    }

    /** @param list<string> $terms */
    private function matchesAllTerms(string $haystackLower, array $terms): bool
    {
        foreach ($terms as $term) {
            if (! str_contains($haystackLower, $term)) {
                return false;
            }
        }

        return $terms !== [];
    }

    private function encodeCursor(
        CallContext $context,
        string $resourceId,
        string $query,
        int $generation,
        string $buildId,
        int $lastOrdinal,
    ): string {
        $ttl = $this->config->get('vaulter-ai.limits.read_token_ttl_seconds', 900);
        $ttlSeconds = is_int($ttl) && $ttl > 0 ? $ttl : 900;
        $exp = $this->clock->now()->getTimestamp() + $ttlSeconds;

        $payload = $this->b64uEncode(json_encode([
            'v' => 1,
            'g' => $generation,
            'b' => $buildId,
            'o' => $lastOrdinal,
            'q' => hash('sha256', $query),
            'exp' => $exp,
        ], JSON_THROW_ON_ERROR));

        $mac = $this->cipher->signMac($this->macInput($context, $resourceId, $payload));

        return $payload.'.'.$this->b64uEncode($mac);
    }

    /**
     * @return array{generation: int, build_id: string, last_ordinal: int}
     */
    private function decodeCursor(
        CallContext $context,
        string $cursor,
        string $resourceId,
        string $query,
    ): array {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OfficeException::cursorExpired();
        }

        [$payload, $encodedMac] = $parts;
        $mac = $this->b64uDecode($encodedMac);
        $expectedMac = $this->cipher->signMac($this->macInput($context, $resourceId, $payload));
        if ($mac === null || ! hash_equals($expectedMac, $mac)) {
            throw OfficeException::cursorExpired();
        }

        $json = $this->b64uDecode($payload);
        if ($json === null) {
            throw OfficeException::cursorExpired();
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OfficeException::cursorExpired();
        }

        if (
            ! is_array($data)
            || ($data['v'] ?? null) !== 1
            || ! is_int($data['g'] ?? null)
            || ! is_string($data['b'] ?? null)
            || $data['b'] === ''
            || ! is_int($data['o'] ?? null)
            || ! is_string($data['q'] ?? null)
            || ! hash_equals($data['q'], hash('sha256', $query))
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] <= $this->clock->now()->getTimestamp()
        ) {
            throw OfficeException::cursorExpired();
        }

        return [
            'generation' => $data['g'],
            'build_id' => $data['b'],
            'last_ordinal' => $data['o'],
        ];
    }

    private function macInput(CallContext $context, string $resourceId, string $payload): string
    {
        return implode("\0", [
            'text-outline-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $resourceId,
        ]);
    }

    private function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64uDecode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }
        $pad = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode(strtr($encoded.str_repeat('=', $pad), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
