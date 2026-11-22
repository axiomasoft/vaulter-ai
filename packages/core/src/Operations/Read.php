<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use stdClass;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\ReadTokens;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Implements office.read: enforces resource ACL, delegates bounded read to registered ResourceReader,
 * validates response byte limits, and issues an encrypted scoped read_token.
 */
final readonly class Read
{
    public function __construct(
        private ResourceDirectory $directory,
        private OfficeRegistry $registry,
        private ReadTokens $tokens,
        private ConfigRepository $config,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $resourceId = $input->resource_id ?? null;
        $selector = $input->selector ?? null;

        if (! is_string($resourceId) || $resourceId === '' || ! $selector instanceof stdClass) {
            throw OfficeException::invalidInput('read.resource_id and read.selector are required.');
        }

        $limit = null;
        $maxRows = $this->intLimit('read_max_rows', 20);
        if (property_exists($input, 'limit')) {
            if (! is_int($input->limit) || $input->limit < 1) {
                throw OfficeException::invalidInput('read.limit must be a positive integer.');
            }
            if ($input->limit > $maxRows) {
                throw OfficeException::limitExceeded('Requested read.limit exceeds configured maximum rows.');
            }
            $limit = $input->limit;
        }

        $cursor = null;
        if (property_exists($input, 'cursor')) {
            if (! is_string($input->cursor) || $input->cursor === '') {
                throw OfficeException::invalidInput('read.cursor must be a non-empty string.');
            }
            $cursor = $input->cursor;
        }

        $afterReceipt = null;
        if (property_exists($input, 'after_receipt')) {
            if (! is_string($input->after_receipt) || $input->after_receipt === '') {
                throw OfficeException::invalidInput('read.after_receipt must be a non-empty string.');
            }
            $afterReceipt = $input->after_receipt;
        }

        $entry = $this->directory->findAccessible($context, $resourceId, 'view');
        if ($entry === null) {
            throw OfficeException::resourceUnavailable();
        }

        $reader = $this->registry->reader($entry->resourceType);
        if ($reader === null) {
            throw OfficeException::operationUnknown();
        }

        $page = $reader->read(
            context: $context,
            resource: $entry,
            selector: $selector,
            limit: $limit ?? $this->intLimit('read_default_limit', 20),
            cursor: $cursor,
            afterReceipt: $afterReceipt,
        );

        $dataBytes = strlen(json_encode($page->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $maxBytes = $this->intLimit('read_max_bytes', 16384);
        if ($dataBytes > $maxBytes) {
            throw OfficeException::limitExceeded('Read payload exceeds configured byte limit; narrow selector.');
        }

        $ttlSeconds = $this->intLimit('read_token_ttl_seconds', 900);
        $readToken = $this->tokens->issue($context, $entry->resourceId, $page, $ttlSeconds);

        $result = (object) [
            'marker' => $page->marker->toStdClass(),
            'read_token' => $readToken,
            'data' => $page->data,
            'truncated' => $page->truncated,
            'next_cursor' => $page->nextCursor,
        ];

        $resultBytes = strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($resultBytes > $maxBytes) {
            throw OfficeException::limitExceeded('Read result exceeds configured byte limit; narrow selector.');
        }

        return OfficeResponse::success(OfficeOperation::Read, $result);
    }

    private function intLimit(string $key, int $default): int
    {
        $val = $this->config->get('vaulter-ai.limits.'.$key, $default);

        return is_int($val) && $val > 0 ? $val : $default;
    }
}
