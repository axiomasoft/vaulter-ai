<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use JsonException;
use stdClass;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\SearchPages;

/**
 * Implements office.search with deterministic snapshot freezing and per-page ACL re-checking.
 */
final readonly class Search
{
    public function __construct(
        private ResourceDirectory $directory,
        private SearchPages $pages,
        private ConfigRepository $config,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $query = $input->query ?? null;
        if (! is_string($query)) {
            throw OfficeException::invalidInput('search.query must be a string.');
        }

        $resourceType = null;
        if (property_exists($input, 'resource_type')) {
            if (! is_string($input->resource_type)) {
                throw OfficeException::invalidInput('search.resource_type must be a string.');
            }
            $resourceType = ResourceType::tryFrom($input->resource_type);
            if ($resourceType === null) {
                throw OfficeException::invalidInput('Unsupported search resource_type.');
            }
        }

        $defaultLimit = $this->intLimit('search_default_limit', 5);
        $maxLimit = $this->intLimit('search_max_limit', 50);
        $limit = $defaultLimit;
        if (property_exists($input, 'limit')) {
            if (! is_int($input->limit) || $input->limit < 1) {
                throw OfficeException::invalidInput('search.limit must be a positive integer.');
            }
            if ($input->limit > $maxLimit) {
                throw OfficeException::limitExceeded('Requested search.limit exceeds allowed maximum.');
            }
            $limit = $input->limit;
        }

        $cursor = null;
        if (property_exists($input, 'cursor')) {
            if (! is_string($input->cursor) || $input->cursor === '') {
                throw OfficeException::invalidInput('search.cursor must be a non-empty string.');
            }
            $cursor = $input->cursor;
        }

        try {
            $queryHashBinary = hash('sha256', json_encode([
                'query' => $query,
                'resource_type' => $resourceType?->value,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), true);
        } catch (JsonException $e) {
            throw new OfficeException(ErrorCode::InvalidInput, 'Invalid UTF-8 in search query.', $e);
        }

        $maxCandidates = $this->intLimit('search_max_candidates', 500);
        $ttlSeconds = $this->intLimit('search_page_ttl_seconds', 900);

        if ($cursor !== null) {
            $page = $this->pages->readPageByCursor(
                context: $context,
                cursor: $cursor,
                queryHashBinary: $queryHashBinary,
                limit: $limit,
                directory: $this->directory,
            );
        } else {
            $candidates = $this->directory->searchCandidates(
                context: $context,
                query: $query,
                resourceType: $resourceType,
                maxCandidates: $maxCandidates + 1,
            );

            if (count($candidates) > $maxCandidates) {
                throw OfficeException::limitExceeded('Search matches exceed maximum snapshot candidates; narrow query.');
            }

            $sorted = $this->sortDeterministically($candidates);
            $page = $this->pages->createAndReadFirstPage(
                context: $context,
                queryHashBinary: $queryHashBinary,
                sortedCandidates: $sorted,
                limit: $limit,
                ttlSeconds: $ttlSeconds,
                directory: $this->directory,
            );
        }

        $items = array_map(
            static fn (ResourceEntry $entry): stdClass => (object) [
                'resource_id' => $entry->resourceId,
                'resource_type' => $entry->resourceType->value,
                'title' => $entry->title,
            ],
            $page['items'],
        );

        $result = (object) [
            'items' => $items,
            'truncated' => $page['truncated'],
            'next_cursor' => $page['next_cursor'],
        ];

        $this->assertWithinResponseBytes($result);

        return OfficeResponse::success(OfficeOperation::Search, $result);
    }

    /**
     * Sort by optional host relevance key and terminate strictly with unique resource_id.
     *
     * @param  list<ResourceEntry>  $entries
     * @return list<ResourceEntry>
     */
    private function sortDeterministically(array $entries): array
    {
        usort($entries, static function (ResourceEntry $a, ResourceEntry $b): int {
            $keyA = $a->sortKey ?? $a->title;
            $keyB = $b->sortKey ?? $b->title;
            $cmp = strcmp($keyA, $keyB);

            return $cmp !== 0 ? $cmp : strcmp($a->resourceId, $b->resourceId);
        });

        return $entries;
    }

    private function assertWithinResponseBytes(stdClass $result): void
    {
        $maxBytes = $this->intLimit('read_max_bytes', 16384);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > $maxBytes) {
            throw OfficeException::limitExceeded('Search result exceeds response byte limit; narrow limit or query.');
        }
    }

    private function intLimit(string $key, int $default): int
    {
        $val = $this->config->get('vaulter-ai.limits.'.$key, $default);

        return is_int($val) && $val > 0 ? $val : $default;
    }
}
