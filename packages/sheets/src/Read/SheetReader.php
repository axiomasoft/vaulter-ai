<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Read;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Sheets\Host\DriveWorkbookOperations;
use Vaulter\Ai\Sheets\Host\SheetDocumentAccess;
use Vaulter\Ai\Sheets\Host\WorkbookProblemMapper;
use Vaulter\Ai\Sheets\Host\WorkbookSnapshot;
use Vaulter\Ai\Sheets\Writing\SheetCommandOwner;

/**
 * Authorized ResourceReader for Vaulter spreadsheets supporting sheet_catalog and sheet_table selectors
 * with stable ID resolution, table-order sorting, bounded pagination, and after_receipt barriers.
 */
final readonly class SheetReader implements ResourceReader
{
    public function __construct(
        private SheetDocumentAccess $access,
        private OwnerRoutes $routes,
        private PayloadCipher $cipher,
        private Clock $clock,
        private ConfigRepository $config,
    ) {}

    public function resourceType(): ResourceType
    {
        return ResourceType::Sheet;
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
        if (! is_string($kind) || ! in_array($kind, ['sheet_catalog', 'sheet_table'], true)) {
            throw OfficeException::invalidInput('SheetReader only accepts sheet_catalog or sheet_table selectors.');
        }

        $workbooks = $this->access->workbooks($context, $resource);
        $requiredMarker = $this->resolveAfterReceipt($context, $resource->resourceId, $workbooks, $afterReceipt);

        if ($kind === 'sheet_catalog') {
            return $this->readCatalog($context, $resource, $workbooks, $selector, $limit, $cursor, $requiredMarker);
        }

        return $this->readTableSlice($context, $resource, $workbooks, $selector, $limit, $cursor, $requiredMarker);
    }

    private function readCatalog(
        CallContext $context,
        ResourceEntry $resource,
        DriveWorkbookOperations $workbooks,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?Marker $requiredMarker,
    ): ReadPage {
        if (array_diff(array_keys(get_object_vars($selector)), ['kind']) !== []) {
            throw OfficeException::invalidInput('sheet_catalog accepts only kind.');
        }

        $catalog = WorkbookProblemMapper::call(static fn (): array => $workbooks->catalog($resource->resourceId));
        $marker = WorkbookProblemMapper::marker($catalog['marker'] ?? null);
        $this->assertMarkerSatisfiesReceipt($requiredMarker, $marker);

        $workbook = $catalog['workbook'] ?? null;
        $rawTables = is_object($workbook) && is_array($workbook->tables ?? null) ? $workbook->tables : [];

        $tables = [];
        foreach ($rawTables as $table) {
            if (! $table instanceof stdClass || ! is_string($table->sheet_id ?? null) || ! is_string($table->table_id ?? null)) {
                continue;
            }
            $sheetObj = WorkbookSnapshot::sheetObject($workbook, $table->sheet_id);
            $sheetTitle = $sheetObj !== null && is_string($sheetObj->name ?? null) ? $sheetObj->name : $table->sheet_id;
            $cellData = WorkbookSnapshot::sheetCellData($workbook, $table->sheet_id);
            $headerRow = is_int($table->header_row ?? null) ? $table->header_row : 1;

            $columns = is_array($table->columns ?? null) ? $table->columns : [];
            usort($columns, static fn (mixed $a, mixed $b): int => ((is_object($a) && is_int($a->column ?? null)) ? $a->column : 0)
                <=> ((is_object($b) && is_int($b->column ?? null)) ? $b->column : 0));

            $colEntries = [];
            foreach ($columns as $col) {
                if (! $col instanceof stdClass || ! is_string($col->column_id ?? null) || ! is_int($col->column ?? null)) {
                    continue;
                }
                $headerCell = WorkbookSnapshot::rawCell($cellData, $headerRow, $col->column);
                $headerVal = $headerCell !== null ? ($headerCell->v ?? null) : null;
                $colTitle = is_scalar($headerVal) && (string) $headerVal !== '' ? (string) $headerVal : $col->column_id;
                $colEntries[] = (object) [
                    'column_id' => $col->column_id,
                    'title' => $colTitle,
                ];
            }

            $tables[] = (object) [
                'sheet_id' => $table->sheet_id,
                'sheet_title' => $sheetTitle,
                'table_id' => $table->table_id,
                'table_title' => $table->table_id,
                'columns' => $colEntries,
            ];
        }

        $scopeHash = hash('sha256', 'sheet_catalog');
        $offset = $cursor !== null
            ? $this->decodeCursor($context, $resource->resourceId, $marker, $scopeHash, $cursor, count($tables))
            : 0;

        $effectiveLimit = $limit ?? $this->defaultRowLimit();
        $slice = array_slice($tables, $offset, $effectiveLimit);
        $nextOffset = $offset + count($slice);
        $hasMore = $nextOffset < count($tables);
        $nextCursor = $hasMore
            ? $this->encodeCursor($context, $resource->resourceId, $marker, $scopeHash, $nextOffset)
            : null;

        return new ReadPage(
            marker: $marker,
            data: (object) [
                'kind' => 'sheet_catalog',
                'tables' => $slice,
            ],
            truncated: $hasMore,
            nextCursor: $nextCursor,
            selectorKind: 'sheet_catalog',
            schemaRevision: $resource->schemaRevision,
            completeScope: ! $hasMore && $cursor === null,
            selectorDependencies: ['scope' => 'sheet_catalog'],
        );
    }

    private function readTableSlice(
        CallContext $context,
        ResourceEntry $resource,
        DriveWorkbookOperations $workbooks,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?Marker $requiredMarker,
    ): ReadPage {
        $extraKeys = array_diff(array_keys(get_object_vars($selector)), ['kind', 'sheet_id', 'table_id', 'columns', 'row_ids']);
        if ($extraKeys !== []) {
            throw OfficeException::invalidInput('Unsupported field in sheet_table selector.');
        }

        $sheetId = $selector->sheet_id ?? null;
        $tableId = $selector->table_id ?? null;
        $rawColumns = $selector->columns ?? null;
        if (! is_string($sheetId) || $sheetId === '' || ! is_string($tableId) || $tableId === '' || ! is_array($rawColumns) || $rawColumns === []) {
            throw OfficeException::invalidInput('sheet_table requires non-empty sheet_id, table_id, and columns.');
        }

        $columns = [];
        foreach ($rawColumns as $colId) {
            if (! is_string($colId) || $colId === '' || in_array($colId, $columns, true)) {
                throw OfficeException::invalidInput('sheet_table.columns must contain unique non-empty strings.');
            }
            $columns[] = $colId;
        }

        $requestedRowIds = null;
        if (property_exists($selector, 'row_ids')) {
            if (! is_array($selector->row_ids) || $selector->row_ids === []) {
                throw OfficeException::invalidInput('sheet_table.row_ids must be a non-empty array of unique strings.');
            }
            $requestedRowIds = [];
            foreach ($selector->row_ids as $rowId) {
                if (! is_string($rowId) || $rowId === '' || in_array($rowId, $requestedRowIds, true)) {
                    throw OfficeException::invalidInput('sheet_table.row_ids must contain unique non-empty strings.');
                }
                $requestedRowIds[] = $rowId;
            }
        }

        // Verify sheet_id, table_id, columns, and row_ids before fetching cell ranges so unknown targets return uniform resource_unavailable.
        $catalog = WorkbookProblemMapper::call(static fn (): array => $workbooks->catalog($resource->resourceId));
        $catalogMarker = WorkbookProblemMapper::marker($catalog['marker'] ?? null);
        $this->assertMarkerSatisfiesReceipt($requiredMarker, $catalogMarker);

        $workbook = $catalog['workbook'] ?? null;
        $table = $this->findTable($workbook, $sheetId, $tableId);

        $columnMap = WorkbookSnapshot::columnMap($table);
        foreach ($columns as $colId) {
            if (! isset($columnMap[$colId])) {
                throw OfficeException::resourceUnavailable();
            }
        }

        $orderedBindings = WorkbookSnapshot::orderedRowBindings($table);
        $rowLookup = WorkbookSnapshot::rowMap($table);

        if ($requestedRowIds !== null) {
            foreach ($requestedRowIds as $rowId) {
                if (! isset($rowLookup[$rowId])) {
                    throw OfficeException::resourceUnavailable();
                }
            }
            $requestedSet = array_flip($requestedRowIds);
            $orderedBindings = array_values(array_filter(
                $orderedBindings,
                static fn (stdClass $b): bool => is_string($b->row_id ?? null) && isset($requestedSet[$b->row_id]),
            ));
        }

        $readResult = WorkbookProblemMapper::call(static fn (): array => $workbooks->readTable($resource->resourceId, $tableId));
        $marker = WorkbookProblemMapper::marker($readResult['marker'] ?? null);
        $this->assertMarkerSatisfiesReceipt($requiredMarker, $marker);

        if ($marker->generation !== $catalogMarker->generation) {
            throw OfficeException::generationChanged();
        }
        if ($marker->position !== $catalogMarker->position) {
            // Re-resolve table bindings from readTable result so row membership/coordinates match the read marker atomically.
            $liveTable = $readResult['table'] ?? null;
            if (! $liveTable instanceof stdClass || ($liveTable->sheet_id ?? null) !== $sheetId) {
                throw OfficeException::resourceUnavailable();
            }
            $columnMap = WorkbookSnapshot::columnMap($liveTable);
            foreach ($columns as $colId) {
                if (! isset($columnMap[$colId])) {
                    throw OfficeException::resourceUnavailable();
                }
            }
            $orderedBindings = WorkbookSnapshot::orderedRowBindings($liveTable);
            $rowLookup = WorkbookSnapshot::rowMap($liveTable);
            if ($requestedRowIds !== null) {
                foreach ($requestedRowIds as $rowId) {
                    if (! isset($rowLookup[$rowId])) {
                        throw OfficeException::resourceUnavailable();
                    }
                }
                $requestedSet = array_flip($requestedRowIds);
                $orderedBindings = array_values(array_filter(
                    $orderedBindings,
                    static fn (stdClass $b): bool => is_string($b->row_id ?? null) && isset($requestedSet[$b->row_id]),
                ));
            }
        }

        $cellGrid = WorkbookSnapshot::indexCellGrid($readResult['ranges'] ?? null);
        $scopeHash = hash('sha256', json_encode([
            'sheet_id' => $sheetId,
            'table_id' => $tableId,
            'columns' => $columns,
            'row_ids' => $requestedRowIds,
        ], JSON_THROW_ON_ERROR));

        $offset = $cursor !== null
            ? $this->decodeCursor($context, $resource->resourceId, $marker, $scopeHash, $cursor, count($orderedBindings))
            : 0;

        $effectiveLimit = $limit ?? $this->defaultRowLimit();
        $slicedBindings = array_slice($orderedBindings, $offset, $effectiveLimit);
        $nextOffset = $offset + count($slicedBindings);
        $hasMore = $nextOffset < count($orderedBindings);
        $nextCursor = $hasMore
            ? $this->encodeCursor($context, $resource->resourceId, $marker, $scopeHash, $nextOffset)
            : null;

        $rows = [];
        $resolvedRowIds = [];
        $rowValues = [];
        foreach ($slicedBindings as $binding) {
            if (! is_int($binding->row ?? null) || ! is_string($binding->row_id ?? null)) {
                continue;
            }
            $rowNum = $binding->row;
            $rowId = $binding->row_id;
            $values = new stdClass;
            $rowScalarMap = [];
            foreach ($columns as $colId) {
                $colNum = $columnMap[$colId];
                $cell = $cellGrid[$rowNum][$colNum] ?? null;
                $rawVal = $cell !== null ? ($cell->value ?? null) : null;
                $normalized = WorkbookProblemMapper::normalizeScalarCell($rawVal);
                $values->{$colId} = $normalized;
                $rowScalarMap[$colId] = $normalized;
            }
            $rows[] = (object) [
                'row_id' => $rowId,
                'values' => $values,
            ];
            $resolvedRowIds[] = $rowId;
            $rowValues[$rowId] = $rowScalarMap;
        }

        return new ReadPage(
            marker: $marker,
            data: (object) [
                'kind' => 'sheet_table',
                'sheet_id' => $sheetId,
                'table_id' => $tableId,
                'rows' => $rows,
            ],
            truncated: $hasMore,
            nextCursor: $nextCursor,
            selectorKind: 'sheet_table',
            schemaRevision: $resource->schemaRevision,
            completeScope: ! $hasMore && $cursor === null,
            selectorDependencies: [
                'sheet_id' => $sheetId,
                'table_id' => $tableId,
                'columns' => $columns,
                'filtered' => $requestedRowIds !== null,
                'row_ids' => $resolvedRowIds,
                'row_values' => $rowValues,
                'schema_version' => $resource->schemaRevision,
            ],
        );
    }

    private function resolveAfterReceipt(
        CallContext $context,
        string $resourceId,
        DriveWorkbookOperations $workbooks,
        ?string $afterReceipt,
    ): ?Marker {
        if ($afterReceipt === null) {
            return null;
        }

        $route = $this->routes->byReceipt($context, $resourceId, $afterReceipt);
        if ($route === null || $route->ownerKey !== SheetCommandOwner::KEY) {
            throw OfficeException::resourceUnavailable();
        }

        $outcome = WorkbookProblemMapper::call(
            static fn (): array => $workbooks->status($resourceId, SheetCommandOwner::ownerCommandIdFor($route->commandId)),
        );
        if (($outcome['state'] ?? null) !== 'committed' || ! isset($outcome['marker'])) {
            throw OfficeException::resourceUnavailable();
        }

        return WorkbookProblemMapper::marker($outcome['marker']);
    }

    private function assertMarkerSatisfiesReceipt(?Marker $required, Marker $actual): void
    {
        if ($required === null) {
            return;
        }

        if ($actual->generation > $required->generation) {
            throw OfficeException::generationChanged('Workbook generation moved after receipt.');
        }

        if ($actual->generation < $required->generation || $actual->position < $required->position) {
            throw new OfficeException(ErrorCode::CalculationPending, 'Workbook calculation has not reached receipt position yet.');
        }
    }

    private function findTable(mixed $workbook, string $sheetId, string $tableId): stdClass
    {
        if (! $workbook instanceof stdClass || ! is_array($workbook->tables ?? null)) {
            throw OfficeException::resourceUnavailable();
        }

        foreach ($workbook->tables as $candidate) {
            if ($candidate instanceof stdClass
                && ($candidate->table_id ?? null) === $tableId
                && ($candidate->sheet_id ?? null) === $sheetId) {
                return $candidate;
            }
        }

        throw OfficeException::resourceUnavailable();
    }

    private function defaultRowLimit(): int
    {
        $val = $this->config->get('vaulter-ai.limits.read_default_limit', 20);

        return is_int($val) && $val > 0 ? $val : 20;
    }

    private function encodeCursor(
        CallContext $context,
        string $resourceId,
        Marker $marker,
        string $scopeHash,
        int $offset,
    ): string {
        $ttl = $this->config->get('vaulter-ai.limits.read_token_ttl_seconds', 900);
        $ttlSeconds = is_int($ttl) && $ttl > 0 ? $ttl : 900;

        $payload = rtrim(strtr(base64_encode(json_encode([
            'v' => 1,
            'g' => $marker->generation,
            'p' => $marker->position,
            'o' => $offset,
            's' => $scopeHash,
            'exp' => $this->clock->now()->getTimestamp() + $ttlSeconds,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $mac = rtrim(strtr(base64_encode($this->cipher->signMac(implode("\0", [
            'sheet-read-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $resourceId,
        ]))), '+/', '-_'), '=');

        return $payload.'.'.$mac;
    }

    private function decodeCursor(
        CallContext $context,
        string $resourceId,
        Marker $marker,
        string $scopeHash,
        string $cursor,
        int $totalCount,
    ): int {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw OfficeException::cursorExpired();
        }

        [$payload, $encodedMac] = $parts;
        $expectedMac = rtrim(strtr(base64_encode($this->cipher->signMac(implode("\0", [
            'sheet-read-cursor-v1',
            $payload,
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $resourceId,
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
            || ($data['g'] ?? null) !== $marker->generation
            || ($data['p'] ?? null) !== $marker->position
            || ! is_int($data['o'] ?? null)
            || $data['o'] < 0
            || $data['o'] > $totalCount
            || ! is_string($data['s'] ?? null)
            || ! hash_equals($data['s'], $scopeHash)
            || ! is_int($data['exp'] ?? null)
            || $data['exp'] <= $this->clock->now()->getTimestamp()
        ) {
            throw OfficeException::cursorExpired();
        }

        return $data['o'];
    }
}
