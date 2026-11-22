<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Collection;
use JsonException;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Snapshot store and MAC-authenticated cursor handler for paginated office.search results.
 */
final readonly class SearchPages
{
    public const int SORT_VERSION = 1;

    public function __construct(
        private OfficeDatabase $db,
        private PayloadCipher $cipher,
        private Clock $clock,
    ) {}

    /**
     * Create a new immutable search snapshot and return its first authorized page.
     *
     * @param  list<ResourceEntry>  $sortedCandidates
     * @return array{items: list<ResourceEntry>, truncated: bool, next_cursor: ?string}
     */
    public function createAndReadFirstPage(
        CallContext $context,
        string $queryHashBinary,
        array $sortedCandidates,
        int $limit,
        int $ttlSeconds,
        ResourceDirectory $directory,
    ): array {
        $now = $this->clock->now();
        $expiresAt = $now->modify('+'.$ttlSeconds.' seconds');
        $snapshotId = $this->db->newUuid();

        $this->db->transaction(function () use ($context, $snapshotId, $queryHashBinary, $sortedCandidates, $now, $expiresAt): void {
            $this->db->query('ai_search_pages')->insert([
                'snapshot_id' => $snapshotId,
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'effective_subject' => $context->effectiveSubject,
                'query_hash' => $this->db->binaryLiteral($queryHashBinary),
                'sort_version' => self::SORT_VERSION,
                'issued_at' => $this->db->utcTimestamp($now),
                'expires_at' => $this->db->utcTimestamp($expiresAt),
            ]);

            if ($sortedCandidates !== []) {
                $rows = [];
                foreach ($sortedCandidates as $ordinal => $entry) {
                    $rows[] = [
                        'snapshot_id' => $snapshotId,
                        'ordinal' => $ordinal,
                        'resource_id' => $entry->resourceId,
                        'resource_type' => $entry->resourceType->value,
                    ];
                }

                foreach (array_chunk($rows, 200) as $chunk) {
                    $this->db->query('ai_search_items')->insert($chunk);
                }
            }
        }, tenantId: $context->tenantId);

        return $this->sliceSnapshot(
            context: $context,
            snapshotId: $snapshotId,
            afterOrdinal: -1,
            queryHashBinary: $queryHashBinary,
            expiresAt: $expiresAt,
            limit: $limit,
            directory: $directory,
        );
    }

    /**
     * Read subsequent page from an existing snapshot cursor, verifying scope, expiry, and live ACL.
     *
     * @return array{items: list<ResourceEntry>, truncated: bool, next_cursor: ?string}
     */
    public function readPageByCursor(
        CallContext $context,
        string $cursor,
        string $queryHashBinary,
        int $limit,
        ResourceDirectory $directory,
    ): array {
        $decoded = $this->decodeCursor($context, $cursor, $queryHashBinary);
        $now = $this->clock->now();

        $pageRow = $this->db->transaction(
            fn (): ?object => $this->db->query('ai_search_pages')
                ->where('snapshot_id', $decoded['snapshot_id'])
                ->where('tenant_id', $context->tenantId)
                ->where('project_scope', $context->projectScope)
                ->where('effective_subject', $context->effectiveSubject)
                ->first(['snapshot_id', 'query_hash', 'expires_at']),
            tenantId: $context->tenantId,
        );

        if ($pageRow === null) {
            throw OfficeException::cursorExpired();
        }

        $storedHash = $this->db->decodeBinary(QueryRecord::raw($pageRow, 'query_hash'));
        if (! hash_equals($storedHash, $queryHashBinary)) {
            throw OfficeException::cursorExpired();
        }

        $expiresAt = $this->db->parseUtcTimestamp(QueryRecord::raw($pageRow, 'expires_at'));
        if ($expiresAt === null || $expiresAt <= $now) {
            throw OfficeException::cursorExpired();
        }

        return $this->sliceSnapshot(
            context: $context,
            snapshotId: $decoded['snapshot_id'],
            afterOrdinal: $decoded['last_ordinal'],
            queryHashBinary: $queryHashBinary,
            expiresAt: $expiresAt,
            limit: $limit,
            directory: $directory,
        );
    }

    /**
     * @return array{items: list<ResourceEntry>, truncated: bool, next_cursor: ?string}
     */
    private function sliceSnapshot(
        CallContext $context,
        string $snapshotId,
        int $afterOrdinal,
        string $queryHashBinary,
        DateTimeImmutable $expiresAt,
        int $limit,
        ResourceDirectory $directory,
    ): array {
        $items = [];
        $lastEmittedOrdinal = $afterOrdinal;
        $cursorOrdinal = $afterOrdinal;
        $hasMoreAccessible = false;
        $chunkSize = max($limit * 2, 20);

        while (true) {
            /** @var Collection<int, stdClass> $rows */
            $rows = $this->db->transaction(
                fn (): Collection => $this->db->query('ai_search_items')
                    ->where('snapshot_id', $snapshotId)
                    ->where('ordinal', '>', $cursorOrdinal)
                    ->orderBy('ordinal')
                    ->limit($chunkSize)
                    ->get(['ordinal', 'resource_id', 'resource_type']),
                tenantId: $context->tenantId,
            );

            if ($rows->isEmpty()) {
                break;
            }

            $ids = [];
            $rowList = [];
            foreach ($rows as $row) {
                $rid = QueryRecord::str($row, 'resource_id');
                if ($rid !== '') {
                    $ids[] = $rid;
                }
                $rowList[] = $row;
                $cursorOrdinal = QueryRecord::int($row, 'ordinal');
            }

            $accessible = $directory->filterAccessible($context, $ids, 'view');
            foreach ($rowList as $row) {
                $rid = QueryRecord::str($row, 'resource_id');
                if (! isset($accessible[$rid])) {
                    continue;
                }

                $entry = $accessible[$rid];
                if ($entry->resourceType !== ResourceType::from(QueryRecord::str($row, 'resource_type'))) {
                    continue;
                }

                if (count($items) >= $limit) {
                    $hasMoreAccessible = true;
                    break 2;
                }

                $items[] = $entry;
                $lastEmittedOrdinal = QueryRecord::int($row, 'ordinal');
            }

            if (count($rowList) < $chunkSize) {
                break;
            }
        }

        $nextCursor = $hasMoreAccessible
            ? $this->encodeCursor($context, $snapshotId, $lastEmittedOrdinal, $queryHashBinary, $expiresAt)
            : null;

        return [
            'items' => $items,
            'truncated' => $hasMoreAccessible,
            'next_cursor' => $nextCursor,
        ];
    }

    private function encodeCursor(
        CallContext $context,
        string $snapshotId,
        int $lastOrdinal,
        string $queryHashBinary,
        DateTimeImmutable $expiresAt,
    ): string {
        $payload = $this->base64UrlEncode(json_encode([
            'v' => 1,
            's' => $snapshotId,
            'o' => $lastOrdinal,
            'q' => bin2hex($queryHashBinary),
            'exp' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        $mac = $this->cipher->signMac($this->macInput($context, $payload));

        return $payload.'.'.$this->base64UrlEncode($mac);
    }

    /**
     * @return array{snapshot_id: string, last_ordinal: int}
     */
    private function decodeCursor(CallContext $context, string $cursor, string $queryHashBinary): array
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OfficeException::cursorExpired();
        }

        [$payload, $encodedMac] = $parts;
        $mac = $this->base64UrlDecode($encodedMac);
        $expectedMac = $this->cipher->signMac($this->macInput($context, $payload));
        if ($mac === null || ! hash_equals($expectedMac, $mac)) {
            throw OfficeException::cursorExpired();
        }

        $rawJson = $this->base64UrlDecode($payload);
        if ($rawJson === null) {
            throw OfficeException::cursorExpired();
        }

        try {
            $data = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OfficeException::cursorExpired();
        }

        if (
            ! is_array($data)
            || ($data['v'] ?? null) !== 1
            || ! is_string($data['s'] ?? null)
            || $data['s'] === ''
            || ! is_int($data['o'] ?? null)
            || $data['o'] < -1
            || ! is_string($data['q'] ?? null)
            || ! hash_equals($data['q'], bin2hex($queryHashBinary))
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] <= $this->clock->now()->getTimestamp()
        ) {
            throw OfficeException::cursorExpired();
        }

        return [
            'snapshot_id' => $data['s'],
            'last_ordinal' => $data['o'],
        ];
    }

    private function macInput(CallContext $context, string $payload): string
    {
        return implode("\0", [
            'search-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
        ]);
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $pad = (4 - (strlen($encoded) % 4)) % 4;
        $decoded = base64_decode(strtr($encoded.str_repeat('=', $pad), '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
