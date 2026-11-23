<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Commands;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Sheets\Host\SheetDocumentAccess;
use Vaulter\Ai\Sheets\Host\WorkbookProblemMapper;
use Vaulter\Ai\Sheets\Host\WorkbookSnapshot;

/**
 * Prepares sheet.fill_formula@1 proposals bound to complete-scope sheet_table read tokens,
 * validating operand types, table scope rules, and sealing destination + input cell preconditions.
 */
final readonly class FillFormula implements OfficeCommand
{
    public const string COMMAND = 'sheet.fill_formula@1';

    public function __construct(
        private SheetDocumentAccess $access,
        private OfficeRegistry $registry,
        private HostPolicy $policy,
        private Clock $clock,
        private ConfigRepository $config,
    ) {}

    public function descriptor(): CommandDescriptor
    {
        return new CommandDescriptor(
            command: self::COMMAND,
            name: 'sheet.fill_formula',
            version: 1,
            resourceType: ResourceType::Sheet,
            effect: 'single_resource_write',
            inputSchema: (object) [
                'type' => 'object',
                'required' => ['sheet_id', 'table_id', 'scope', 'destination_column_id', 'expression'],
                'properties' => (object) [
                    'sheet_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'table_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'scope' => (object) ['type' => 'string', 'enum' => ['fixed_members', 'current_rows']],
                    'destination_column_id' => (object) ['type' => 'string', 'minLength' => 1],
                    'expression' => (object) [
                        'type' => 'object',
                        'required' => ['op', 'left_column_id', 'right_column_id'],
                        'properties' => (object) [
                            'op' => (object) ['const' => 'subtract'],
                            'left_column_id' => (object) ['type' => 'string', 'minLength' => 1],
                            'right_column_id' => (object) ['type' => 'string', 'minLength' => 1],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'additionalProperties' => false,
            ],
            verification: 'formula_and_values',
            requiredPermission: 'edit',
        );
    }

    public function prepare(
        CallContext $context,
        ResourceEntry $resource,
        ReadTokenRecord $token,
        stdClass $arguments,
    ): PreparedPlan {
        if (array_diff(array_keys(get_object_vars($arguments)), ['sheet_id', 'table_id', 'scope', 'destination_column_id', 'expression']) !== []) {
            throw OfficeException::invalidInput('Unexpected argument in sheet.fill_formula@1.');
        }

        $sheetId = $arguments->sheet_id ?? null;
        $tableId = $arguments->table_id ?? null;
        $scope = $arguments->scope ?? null;
        $destColId = $arguments->destination_column_id ?? null;
        $expr = $arguments->expression ?? null;
        if (! is_string($sheetId) || $sheetId === '' || ! is_string($tableId) || $tableId === ''
            || ! is_string($scope) || ! in_array($scope, ['fixed_members', 'current_rows'], true)
            || ! is_string($destColId) || $destColId === '' || ! $expr instanceof stdClass
            || array_diff(array_keys(get_object_vars($expr)), ['op', 'left_column_id', 'right_column_id']) !== []) {
            throw OfficeException::invalidInput('Invalid sheet.fill_formula@1 arguments.');
        }

        $op = $expr->op ?? null;
        $leftColId = $expr->left_column_id ?? null;
        $rightColId = $expr->right_column_id ?? null;
        if ($op !== 'subtract' || ! is_string($leftColId) || $leftColId === '' || ! is_string($rightColId) || $rightColId === '') {
            throw OfficeException::invalidInput('Unsupported or invalid formula expression.');
        }
        if ($destColId === $leftColId || $destColId === $rightColId) {
            throw OfficeException::invalidInput('Destination column cannot overlap expression operands.');
        }

        if (! $token->completeScope || $token->selectorKind !== 'sheet_table') {
            throw OfficeException::preconditionFailed('Read the complete target table slice first.');
        }

        if (($token->dependencies['sheet_id'] ?? null) !== $sheetId
            || ($token->dependencies['table_id'] ?? null) !== $tableId) {
            throw OfficeException::preconditionFailed('Read token does not match requested sheet or table.');
        }

        if ($scope === 'current_rows' && ($token->dependencies['filtered'] ?? false) === true) {
            throw OfficeException::invalidInput('current_rows scope requires an unfiltered full-table read token without row_ids.');
        }

        $readColumns = $token->dependencies['columns'] ?? null;
        if (! is_array($readColumns) || ! in_array($leftColId, $readColumns, true) || ! in_array($rightColId, $readColumns, true)) {
            throw OfficeException::preconditionFailed('Both expression operand columns must be included in read_token.');
        }

        $rawRowIds = $token->dependencies['row_ids'] ?? null;
        if (! is_array($rawRowIds) || $rawRowIds === []) {
            throw OfficeException::preconditionFailed('Target rows are empty.');
        }
        /** @var list<string> $targetRowIds */
        $targetRowIds = [];
        foreach ($rawRowIds as $id) {
            if (! is_string($id) || $id === '') {
                throw OfficeException::preconditionFailed('Malformed row_id in read_token.');
            }
            $targetRowIds[] = $id;
        }

        $maxTargetsVal = $this->config->get('vaulter-ai.limits.prepare_max_targets', 20);
        $maxTargets = is_int($maxTargetsVal) && $maxTargetsVal > 0 ? $maxTargetsVal : 20;
        if (count($targetRowIds) > $maxTargets) {
            throw OfficeException::limitExceeded('Target row count exceeds prepare_max_targets.');
        }

        $workbooks = $this->access->workbooks($context, $resource);
        $catalog = WorkbookProblemMapper::call(static fn (): array => $workbooks->catalog($resource->resourceId));
        $prepared = WorkbookProblemMapper::call(
            static fn (): array => $workbooks->prepareFormula($resource->resourceId, $tableId, $destColId, $leftColId, $rightColId, $scope),
            unknownTargetCode: 'precondition_failed',
        );
        $readResult = WorkbookProblemMapper::call(
            static fn (): array => $workbooks->readTable($resource->resourceId, $tableId),
            unknownTargetCode: 'precondition_failed',
        );

        $liveMarker = WorkbookProblemMapper::marker($prepared['marker'] ?? null);
        $readMarker = WorkbookProblemMapper::marker($readResult['marker'] ?? null);
        if ($liveMarker->generation !== $token->marker->generation || $readMarker->generation !== $token->marker->generation) {
            throw OfficeException::generationChanged();
        }
        if ($resource->schemaRevision !== $token->schemaRevision) {
            throw OfficeException::schemaChanged();
        }
        if ($liveMarker->position < $token->marker->position || $readMarker->position < $token->marker->position) {
            throw new OfficeException(ErrorCode::CalculationPending);
        }
        if ($liveMarker->position !== $readMarker->position) {
            throw OfficeException::preconditionFailed('Workbook changed while preparing proposal.');
        }

        $command = $prepared['command'] ?? null;
        if (! $command instanceof stdClass || ($command->sheet_id ?? null) !== $sheetId) {
            throw OfficeException::preconditionFailed('Table sheet mismatch.');
        }

        $allRowIds = is_array($command->row_ids ?? null) ? $command->row_ids : [];
        $allExpected = is_array($command->expected_destination ?? null) ? $command->expected_destination : [];
        if (count($allRowIds) !== count($allExpected)) {
            throw OfficeException::temporarilyUnavailable('Malformed prepareFormula response.');
        }

        if ($scope === 'current_rows') {
            $liveTableObj = $readResult['table'] ?? null;
            $orderedAll = $liveTableObj instanceof stdClass ? WorkbookSnapshot::orderedRowIds($liveTableObj) : [];
            if ($orderedAll !== $targetRowIds) {
                throw OfficeException::scopeChanged();
            }
        }

        $expectedByRow = [];
        foreach ($allRowIds as $idx => $rId) {
            if (is_string($rId)) {
                $expectedByRow[$rId] = $allExpected[$idx];
            }
        }

        $targetExpected = [];
        foreach ($targetRowIds as $rowId) {
            if (! array_key_exists($rowId, $expectedByRow)) {
                throw OfficeException::preconditionFailed("Target row [{$rowId}] no longer exists.");
            }
            $targetExpected[] = $expectedByRow[$rowId];
        }

        $table = $readResult['table'] ?? null;
        $workbook = $catalog['workbook'] ?? null;
        if (! $table instanceof stdClass || ! $workbook instanceof stdClass) {
            throw OfficeException::temporarilyUnavailable();
        }

        $colMap = WorkbookSnapshot::columnMap($table);
        $rowMap = WorkbookSnapshot::rowMap($table);
        if (! isset($colMap[$leftColId], $colMap[$rightColId], $colMap[$destColId])) {
            throw OfficeException::preconditionFailed('Target columns missing from table bindings.');
        }

        $leftCol = $colMap[$leftColId];
        $rightCol = $colMap[$rightColId];
        $destCol = $colMap[$destColId];
        $cellGrid = WorkbookSnapshot::indexCellGrid($readResult['ranges'] ?? null);
        $rawCellData = WorkbookSnapshot::sheetCellData($workbook, $sheetId);

        $tokenRowValues = is_array($token->dependencies['row_values'] ?? null) ? $token->dependencies['row_values'] : [];

        $expectedInputs = [];
        foreach ($targetRowIds as $rowId) {
            if (! isset($rowMap[$rowId])) {
                throw OfficeException::preconditionFailed("Target row [{$rowId}] missing from table.");
            }
            $rowNum = $rowMap[$rowId];
            $leftCell = $cellGrid[$rowNum][$leftCol] ?? null;
            $rightCell = $cellGrid[$rowNum][$rightCol] ?? null;
            $leftRaw = WorkbookSnapshot::rawCell($rawCellData, $rowNum, $leftCol);
            $rightRaw = WorkbookSnapshot::rawCell($rawCellData, $rowNum, $rightCol);
            $destRaw = WorkbookSnapshot::rawCell($rawCellData, $rowNum, $destCol);

            $this->assertSupportedRawCell($leftRaw);
            $this->assertSupportedRawCell($rightRaw);
            $this->assertSupportedRawCell($destRaw);

            $leftVal = $leftCell !== null ? ($leftCell->value ?? null) : null;
            $rightVal = $rightCell !== null ? ($rightCell->value ?? null) : null;
            $leftNum = WorkbookProblemMapper::normalizeNumericValue($leftVal);
            $rightNum = WorkbookProblemMapper::normalizeNumericValue($rightVal);
            if ($leftNum === null || $rightNum === null) {
                throw OfficeException::unsupportedContent('Formula operand cell must evaluate to a finite number.');
            }

            $readRow = $tokenRowValues[$rowId] ?? null;
            if (is_array($readRow)) {
                foreach ([[$leftColId, $leftNum], [$rightColId, $rightNum]] as [$colId, $liveNum]) {
                    if (array_key_exists($colId, $readRow)) {
                        $readNum = WorkbookProblemMapper::normalizeNumericValue($readRow[$colId]);
                        if ($readNum === null || abs((float) $readNum - (float) $liveNum) > 1e-9) {
                            throw OfficeException::preconditionFailed('Formula operand cell changed since read.');
                        }
                    }
                }
            }

            $expectedInputs[$rowId] = [
                'left' => $this->cellFingerprint($leftRaw, $leftNum),
                'right' => $this->cellFingerprint($rightRaw, $rightNum),
            ];
        }

        $writeMode = $resource->writeMode ?? 'snapshot';
        $owner = $this->registry->ownerFor(self::COMMAND, $writeMode) ?? throw OfficeException::unsupportedContent();

        $leftLetter = WorkbookProblemMapper::columnLetter($leftCol);
        $rightLetter = WorkbookProblemMapper::columnLetter($rightCol);
        $destLetter = WorkbookProblemMapper::columnLetter($destCol);

        $ttl = $this->config->get('vaulter-ai.limits.proposal_ttl_seconds', 3600);
        $expires = $this->clock->now()->modify('+'.(is_int($ttl) && $ttl > 0 ? $ttl : 3600).' seconds');

        $count = count($targetRowIds);
        $summary = $scope === 'fixed_members'
            ? "Записать формулы ({$destLetter} = {$leftLetter} - {$rightLetter}) в {$count} выбранные строки"
            : "Записать формулы ({$destLetter} = {$leftLetter} - {$rightLetter}) во все {$count} строк";

        return new PreparedPlan(
            proposalId: (string) Str::uuid7(),
            command: self::COMMAND,
            resourceId: $resource->resourceId,
            base: $token->marker,
            approval: $this->policy->profile($context)->approval,
            summary: $summary,
            targets: $targetRowIds,
            expiresAt: $expires->format('Y-m-d\TH:i:s\Z'),
            digest: '',
            ownerKey: $owner->key(),
            preconditions: [
                'schema' => (int) $resource->schemaRevision,
                'write_mode' => $writeMode,
                'sheet_id' => $sheetId,
                'table_id' => $tableId,
                'scope' => $scope,
                'row_ids' => $targetRowIds,
                'destination_column_id' => $destColId,
                'left_column_id' => $leftColId,
                'right_column_id' => $rightColId,
                'left_column_letter' => $leftLetter,
                'right_column_letter' => $rightLetter,
                'destination_column_letter' => $destLetter,
                'expected_destination' => array_map(
                    static fn (mixed $item): ?array => $item instanceof stdClass ? get_object_vars($item) : null,
                    $targetExpected,
                ),
                'expected_inputs' => $expectedInputs,
            ],
        );
    }

    private function assertSupportedRawCell(?stdClass $cell): void
    {
        if ($cell === null) {
            return;
        }
        if (property_exists($cell, 'p') && $cell->p !== null) {
            throw OfficeException::unsupportedContent('Rich text cell content is not supported for formula operations.');
        }
        if (property_exists($cell, 'f') && is_string($cell->f) && is_string($cell->v ?? null) && str_starts_with($cell->v, '#')) {
            throw OfficeException::unsupportedContent('Formula error cell is not supported.');
        }
    }

    /** @return array{formula: ?string, number: int|float} */
    private function cellFingerprint(?stdClass $rawCell, int|float $numericValue): array
    {
        $formula = $rawCell !== null && is_string($rawCell->f ?? null) ? $rawCell->f : null;

        return [
            'formula' => $formula,
            'number' => $numericValue,
        ];
    }
}
