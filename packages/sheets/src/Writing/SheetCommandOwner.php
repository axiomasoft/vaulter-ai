<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Writing;

use DateTimeImmutable;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Contracts\SheetVerificationEvidence;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Sheets\Commands\FillFormula;
use Vaulter\Ai\Sheets\Host\DriveWorkbookOperations;
use Vaulter\Ai\Sheets\Host\SheetDocumentAccess;
use Vaulter\Ai\Sheets\Host\WorkbookProblemMapper;
use Vaulter\Ai\Sheets\Host\WorkbookSnapshot;
use Vaulter\Sheets\Data\WorkbookCommand;
use Vaulter\Sheets\Data\WorkbookProblem;

/**
 * Spreadsheet command owner delegating formula writes, status recovery, and receipt evidence
 * strictly through Vaulter Sheets' WorkbookOperations #[Api] boundary.
 */
final readonly class SheetCommandOwner implements CommandOwner, SheetVerificationEvidence
{
    public const string KEY = 'sheets.workbook@1';

    public function __construct(
        private SheetDocumentAccess $access,
        private OwnerRoutes $routes,
        private Proposals $proposals,
        private Clock $clock,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(string $command, ?string $writeMode = null): bool
    {
        return $command === FillFormula::COMMAND && ($writeMode === null || $writeMode === 'snapshot');
    }

    public static function ownerCommandIdFor(string $wireCommandId): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,255}$/D', $wireCommandId) === 1) {
            return $wireCommandId;
        }

        return 'ai-'.hash('sha256', $wireCommandId);
    }

    public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId, 'edit');
        if (($resource->writeMode ?? 'snapshot') !== 'snapshot') {
            return new CommandOutcome($scope->commandId, 'rejected', reason: ErrorCode::GenerationChanged);
        }

        $conditions = $plan->preconditions;
        if ($conditions === []) {
            return new CommandOutcome($scope->commandId, 'rejected', reason: ErrorCode::PreconditionFailed);
        }

        $workbooks = $this->access->workbooks($scope->context, $resource);
        $ownerCommandId = self::ownerCommandIdFor($scope->commandId);

        $alreadyRecorded = $this->findRecordedOutcome($scope->resourceId, $workbooks, $ownerCommandId);
        if ($alreadyRecorded === null) {
            if (new DateTimeImmutable($plan->expiresAt) <= $this->clock->now()) {
                return new CommandOutcome($scope->commandId, 'rejected', reason: ErrorCode::ProposalExpired);
            }
            if (($conditions['schema'] ?? null) !== (int) $resource->schemaRevision) {
                return new CommandOutcome($scope->commandId, 'rejected', reason: ErrorCode::SchemaChanged);
            }
        }

        $wbCommand = $this->buildWorkbookCommand($ownerCommandId, $plan, $conditions);

        if ($alreadyRecorded === null || ($alreadyRecorded['state'] ?? null) === 'running') {
            $catalog = WorkbookProblemMapper::call(static fn (): array => $workbooks->catalog($scope->resourceId));
            $liveMarker = WorkbookProblemMapper::marker($catalog['marker'] ?? null);
            if ($liveMarker->generation === $plan->base->generation && $liveMarker->position < $plan->base->position) {
                throw new OfficeException(ErrorCode::CalculationPending);
            }
        }

        try {
            $outcome = $workbooks->commit($scope->resourceId, $wbCommand);
        } catch (WorkbookProblem $problem) {
            if ($problem->problem === 'calculation_pending') {
                throw WorkbookProblemMapper::toOfficeException($problem);
            }
            if ($problem->status >= 500) {
                throw OfficeException::temporarilyUnavailable($problem->problem);
            }

            throw WorkbookProblemMapper::toOfficeException($problem, 'precondition_failed');
        } catch (\Throwable $e) {
            throw WorkbookProblemMapper::call(static function () use ($e): never {
                throw $e;
            }, 'precondition_failed');
        }

        return $this->mapOwnerOutcome($scope->commandId, $outcome);
    }

    public function status(CommandScope $scope): CommandOutcome
    {
        $resource = $this->access->resource($scope->context, $scope->resourceId);
        $workbooks = $this->access->workbooks($scope->context, $resource);
        $ownerCommandId = self::ownerCommandIdFor($scope->commandId);

        try {
            $outcome = $workbooks->status($scope->resourceId, $ownerCommandId);
        } catch (WorkbookProblem $problem) {
            if ($problem->problem === 'resource_unavailable') {
                // Distinguish revoked edit/view permission from a crash between Core route bind and owner write.
                if (! $this->access->canEdit($scope->context, $scope->resourceId)) {
                    throw OfficeException::resourceUnavailable();
                }

                return new CommandOutcome($scope->commandId, 'running');
            }

            throw WorkbookProblemMapper::toOfficeException($problem);
        }

        return $this->mapOwnerOutcome($scope->commandId, $outcome);
    }

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
    {
        $resource = $this->access->resource($context, $resourceId);
        $workbooks = $this->access->workbooks($context, $resource);
        $evidence = $this->loadReceiptEvidence($context, $resourceId, $receiptId, $workbooks);

        if ($mode === 'at_commit') {
            $computed = $this->evaluateAtCommit($evidence);

            return (object) [
                'receipt_id' => $receiptId,
                'mode' => 'at_commit',
                'outcome' => $computed['passed'] ? 'passed' : 'failed',
                'marker' => $evidence['receipt_marker']->toStdClass(),
                'checks' => $this->formatChecks(
                    $computed['row_ids'],
                    $computed['row_ids_passed'],
                    $computed['formulas'],
                    $computed['formulas_passed'],
                    $computed['values'],
                    $computed['values_passed'],
                    $evidence['receipt_marker'],
                    $computed['sum'],
                    $computed['sum_passed'],
                ),
            ];
        }

        $current = $this->evaluateCurrent($resourceId, $workbooks, $evidence);

        return (object) [
            'receipt_id' => $receiptId,
            'mode' => 'current',
            'outcome' => $current['passed'] ? 'passed' : 'failed',
            'marker' => $current['marker']->toStdClass(),
            'checks' => $this->formatChecks(
                $current['row_ids'],
                $current['row_ids_passed'],
                $current['formulas'],
                $current['formulas_passed'],
                $current['values'],
                $current['values_passed'],
                $current['marker'],
                $current['sum'],
                $current['sum_passed'],
            ),
        ];
    }

    public function expectedSheetChecks(
        CallContext $context,
        string $resourceId,
        string $receiptId,
        Marker $receiptMarker,
        Marker $verifyMarker,
        string $mode,
    ): array {
        $resource = $this->access->resource($context, $resourceId);
        $workbooks = $this->access->workbooks($context, $resource);
        $evidence = $this->loadReceiptEvidence($context, $resourceId, $receiptId, $workbooks);

        if ($mode === 'at_commit') {
            $computed = $this->evaluateAtCommit($evidence);
            if (! $computed['passed']) {
                throw OfficeException::temporarilyUnavailable('Committed formula evidence failed integrity validation.');
            }

            return [
                'row_ids' => $computed['row_ids'],
                'formulas' => $computed['formulas'],
                'calculated_values' => $computed['expected_values'],
                'calculated_at' => [
                    'generation' => $evidence['receipt_marker']->generation,
                    'position' => $evidence['receipt_marker']->position,
                ],
                'sum' => $computed['expected_sum'],
            ];
        }

        $current = $this->evaluateCurrent($resourceId, $workbooks, $evidence);
        if (! $current['passed']
            || $current['marker']->generation !== $verifyMarker->generation
            || $current['marker']->position !== $verifyMarker->position) {
            throw OfficeException::temporarilyUnavailable('Current workbook state failed formula verification.');
        }

        return [
            'row_ids' => $current['row_ids'],
            'formulas' => $current['expected_formulas'],
            'calculated_values' => $current['expected_values'],
            'calculated_at' => [
                'generation' => $current['marker']->generation,
                'position' => $current['marker']->position,
            ],
            'sum' => $current['expected_sum'],
        ];
    }

    /** @param array<string, mixed> $conditions */
    private function buildWorkbookCommand(string $ownerCommandId, PreparedPlan $plan, array $conditions): WorkbookCommand
    {
        $sheetId = $this->requireConditionString($conditions, 'sheet_id');
        $tableId = $this->requireConditionString($conditions, 'table_id');
        $scope = $this->requireConditionString($conditions, 'scope');
        $destColId = $this->requireConditionString($conditions, 'destination_column_id');
        $leftColId = $this->requireConditionString($conditions, 'left_column_id');
        $rightColId = $this->requireConditionString($conditions, 'right_column_id');
        $rowIds = $this->requireConditionStringList($conditions, 'row_ids');

        $rawExpected = $conditions['expected_destination'] ?? null;
        if (! is_array($rawExpected) || count($rawExpected) !== count($rowIds)) {
            throw OfficeException::preconditionFailed('Malformed sealed destination preconditions.');
        }
        $expectedDest = array_map(
            static fn (mixed $cell): ?stdClass => WorkbookProblemMapper::cellExpectationObject($cell),
            array_values($rawExpected),
        );

        $rawInputs = $conditions['expected_inputs'] ?? null;
        if (! is_array($rawInputs)) {
            throw OfficeException::preconditionFailed('Malformed sealed input preconditions.');
        }
        $expectedInputs = [];
        foreach ($rowIds as $rowId) {
            $rowInputs = $rawInputs[$rowId] ?? null;
            if (! is_array($rowInputs)) {
                throw OfficeException::preconditionFailed('Malformed sealed input preconditions.');
            }
            $expectedInputs[] = (object) [
                'left' => $this->buildOperandExpectation($rowInputs['left'] ?? null),
                'right' => $this->buildOperandExpectation($rowInputs['right'] ?? null),
            ];
        }

        try {
            return new WorkbookCommand(
                id: $ownerCommandId,
                policy: 'revalidate_by_id',
                generation: $plan->base->generation,
                ifMatch: null,
                commands: [
                    (object) [
                        'kind' => 'fill_formula',
                        'sheet_id' => $sheetId,
                        'table_id' => $tableId,
                        'scope' => $scope,
                        'row_ids' => $rowIds,
                        'destination_column_id' => $destColId,
                        'expression' => (object) [
                            'op' => 'subtract',
                            'left_column_id' => $leftColId,
                            'right_column_id' => $rightColId,
                        ],
                        'expected_destination' => $expectedDest,
                        'expected_inputs' => $expectedInputs,
                    ],
                ],
            );
        } catch (WorkbookProblem $problem) {
            throw WorkbookProblemMapper::toOfficeException($problem, 'precondition_failed');
        }
    }

    private function buildOperandExpectation(mixed $side): stdClass
    {
        if (! is_array($side)) {
            throw OfficeException::preconditionFailed('Malformed sealed operand expectation.');
        }
        $num = WorkbookProblemMapper::normalizeNumericValue($side['number'] ?? null);
        $formula = $side['formula'] ?? null;
        if ($num === null || ($formula !== null && (! is_string($formula) || ! str_starts_with($formula, '=')))) {
            throw OfficeException::preconditionFailed('Malformed sealed operand expectation.');
        }
        $content = $formula !== null ? (object) ['formula' => $formula] : (object) ['number' => $num];

        return (object) [
            'content' => $content,
            'value' => $num,
        ];
    }

    /** @return array<mixed>|null */
    private function findRecordedOutcome(string $resourceId, DriveWorkbookOperations $workbooks, string $ownerCommandId): ?array
    {
        try {
            return $workbooks->status($resourceId, $ownerCommandId);
        } catch (WorkbookProblem $problem) {
            if ($problem->problem === 'resource_unavailable') {
                return null;
            }
            throw WorkbookProblemMapper::toOfficeException($problem);
        }
    }

    /** @param array<mixed> $outcome */
    private function mapOwnerOutcome(string $wireCommandId, array $outcome): CommandOutcome
    {
        $state = $outcome['state'] ?? null;
        if ($state === 'committed') {
            $receiptId = $outcome['receipt_id'] ?? null;
            if (! is_string($receiptId) || $receiptId === '') {
                throw OfficeException::temporarilyUnavailable('Missing committed receipt_id from workbook owner.');
            }
            $marker = WorkbookProblemMapper::marker($outcome['marker'] ?? null);

            return new CommandOutcome($wireCommandId, 'committed', $receiptId, $marker);
        }

        if ($state === 'rejected') {
            $err = $outcome['error'] ?? null;
            $codeRaw = is_array($err) ? ($err['code'] ?? null) : (is_object($err) ? ($err->code ?? null) : null);
            $mapped = is_string($codeRaw)
                ? WorkbookProblemMapper::mapErrorCode($codeRaw, 'precondition_failed')
                : ErrorCode::PreconditionFailed;

            return new CommandOutcome($wireCommandId, 'rejected', reason: $mapped);
        }

        return new CommandOutcome($wireCommandId, 'running');
    }

    /**
     * @return array{
     *     receipt_marker: Marker,
     *     sheet_id: string,
     *     table_id: string,
     *     row_ids: list<string>,
     *     left_col_id: string,
     *     right_col_id: string,
     *     dest_col_id: string,
     *     left_letter: string,
     *     right_letter: string,
     *     expected_inputs: array<mixed>,
     *     written_cells: list<stdClass>,
     * }
     */
    private function loadReceiptEvidence(
        CallContext $context,
        string $resourceId,
        string $receiptId,
        DriveWorkbookOperations $workbooks,
    ): array {
        $route = $this->routes->byReceipt($context, $resourceId, $receiptId);
        if ($route === null || $route->ownerKey !== self::KEY) {
            throw OfficeException::resourceUnavailable();
        }

        $proposal = $this->proposals->find($context, $resourceId, $route->proposalId);
        if ($proposal === null || ! $proposal->hasPayload) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        $conditions = $proposal->plan->preconditions;
        $sheetId = $this->requireConditionString($conditions, 'sheet_id');
        $tableId = $this->requireConditionString($conditions, 'table_id');
        $rowIds = $this->requireConditionStringList($conditions, 'row_ids');
        $leftColId = $this->requireConditionString($conditions, 'left_column_id');
        $rightColId = $this->requireConditionString($conditions, 'right_column_id');
        $destColId = $this->requireConditionString($conditions, 'destination_column_id');
        $leftLetter = $this->requireConditionString($conditions, 'left_column_letter');
        $rightLetter = $this->requireConditionString($conditions, 'right_column_letter');
        $expectedInputs = is_array($conditions['expected_inputs'] ?? null) ? $conditions['expected_inputs'] : [];

        $rawOutcome = WorkbookProblemMapper::call(
            static fn (): array => $workbooks->verify($resourceId, self::ownerCommandIdFor($route->commandId)),
        );
        if (($rawOutcome['receipt_id'] ?? null) !== $receiptId) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        $receiptMarker = WorkbookProblemMapper::marker($rawOutcome['marker'] ?? null);
        $checksRaw = $rawOutcome['checks'] ?? null;
        $cellsRaw = is_object($checksRaw) ? ($checksRaw->cells ?? null) : (is_array($checksRaw) ? ($checksRaw['cells'] ?? null) : null);
        if (! is_array($cellsRaw)) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        /** @var list<stdClass> $writtenCells */
        $writtenCells = [];
        foreach ($cellsRaw as $rawCell) {
            if ($rawCell instanceof stdClass) {
                $writtenCells[] = $rawCell;
            } elseif (is_array($rawCell)) {
                $writtenCells[] = (object) $rawCell;
            }
        }

        return [
            'receipt_marker' => $receiptMarker,
            'sheet_id' => $sheetId,
            'table_id' => $tableId,
            'row_ids' => $rowIds,
            'left_col_id' => $leftColId,
            'right_col_id' => $rightColId,
            'dest_col_id' => $destColId,
            'left_letter' => $leftLetter,
            'right_letter' => $rightLetter,
            'expected_inputs' => $expectedInputs,
            'written_cells' => $writtenCells,
        ];
    }

    /**
     * @param  array{
     *     receipt_marker: Marker,
     *     sheet_id: string,
     *     table_id: string,
     *     row_ids: list<string>,
     *     left_col_id: string,
     *     right_col_id: string,
     *     dest_col_id: string,
     *     left_letter: string,
     *     right_letter: string,
     *     expected_inputs: array<mixed>,
     *     written_cells: list<stdClass>,
     * }  $evidence
     * @return array{
     *     passed: bool,
     *     row_ids: list<string>,
     *     row_ids_passed: bool,
     *     formulas: list<string>,
     *     formulas_passed: bool,
     *     values: list<string|int|float|bool|null>,
     *     expected_values: list<int|float>,
     *     values_passed: bool,
     *     sum: int|float,
     *     expected_sum: int|float,
     *     sum_passed: bool,
     * }
     */
    private function evaluateAtCommit(array $evidence): array
    {
        $rowIds = $evidence['row_ids'];
        $cells = $evidence['written_cells'];
        $leftLetter = $evidence['left_letter'];
        $rightLetter = $evidence['right_letter'];
        $expectedInputs = $evidence['expected_inputs'];

        $rowIdsPassed = count($cells) === count($rowIds) && $rowIds !== [];
        $formulas = [];
        $formulasPassed = $rowIdsPassed;
        $values = [];
        $expectedValues = [];
        $valuesPassed = $rowIdsPassed;
        $previousRow = 0;
        $actualSum = 0.0;
        $expectedSum = 0.0;
        $allActualInts = true;
        $allExpectedInts = true;

        foreach ($rowIds as $index => $rowId) {
            $cell = $cells[$index] ?? null;
            if (! $cell instanceof stdClass || ! is_int($cell->row ?? null) || $cell->row <= $previousRow) {
                $rowIdsPassed = false;
                $formulasPassed = false;
                $valuesPassed = false;
                $formulas[] = '(missing)';
                $values[] = null;

                continue;
            }
            $previousRow = $cell->row;
            $expectedFormula = "={$leftLetter}{$cell->row}-{$rightLetter}{$cell->row}";
            $actualFormula = is_string($cell->formula ?? null) && $cell->formula !== '' ? $cell->formula : '(literal)';
            $formulas[] = $actualFormula;
            if ($actualFormula !== $expectedFormula) {
                $formulasPassed = false;
            }

            $rawValue = $cell->value ?? null;
            $numeric = WorkbookProblemMapper::normalizeNumericValue($rawValue);

            $sealed = $expectedInputs[$rowId] ?? null;
            $leftNum = is_array($sealed) && is_array($sealed['left'] ?? null)
                ? WorkbookProblemMapper::normalizeNumericValue($sealed['left']['number'] ?? null)
                : null;
            $rightNum = is_array($sealed) && is_array($sealed['right'] ?? null)
                ? WorkbookProblemMapper::normalizeNumericValue($sealed['right']['number'] ?? null)
                : null;

            $computedDiff = null;
            if ($leftNum !== null && $rightNum !== null) {
                $computedDiff = is_int($leftNum) && is_int($rightNum)
                    ? ($leftNum - $rightNum)
                    : ((float) $leftNum - (float) $rightNum);
                if (! is_int($computedDiff)) {
                    $allExpectedInts = false;
                }
                $expectedValues[] = $computedDiff;
                $expectedSum += (float) $computedDiff;
            }

            if ($numeric === null) {
                $valuesPassed = false;
                $values[] = is_scalar($rawValue) || $rawValue === null ? $rawValue : null;
            } else {
                $values[] = $numeric;
                if (! is_int($numeric)) {
                    $allActualInts = false;
                }
                $actualSum += (float) $numeric;

                if ($computedDiff !== null) {
                    if (abs((float) $numeric - (float) $computedDiff) > 1e-9) {
                        $valuesPassed = false;
                    }
                } else {
                    $expectedValues[] = $numeric;
                    if (! is_int($numeric)) {
                        $allExpectedInts = false;
                    }
                    $expectedSum += (float) $numeric;
                }
            }
        }

        $normalizedActualSum = $allActualInts ? (int) round($actualSum) : $actualSum;
        $normalizedExpectedSum = $allExpectedInts ? (int) round($expectedSum) : $expectedSum;
        $sumPassed = $valuesPassed && $expectedValues !== [] && abs((float) $normalizedActualSum - (float) $normalizedExpectedSum) <= 1e-9;
        $passed = $rowIdsPassed && $formulasPassed && $valuesPassed && $sumPassed;

        return [
            'passed' => $passed,
            'row_ids' => $rowIds,
            'row_ids_passed' => $rowIdsPassed,
            'formulas' => $formulas,
            'formulas_passed' => $formulasPassed,
            'values' => $values,
            'expected_values' => $expectedValues,
            'values_passed' => $valuesPassed,
            'sum' => $normalizedActualSum,
            'expected_sum' => $normalizedExpectedSum,
            'sum_passed' => $sumPassed,
        ];
    }

    /**
     * @param  array{
     *     receipt_marker: Marker,
     *     sheet_id: string,
     *     table_id: string,
     *     row_ids: list<string>,
     *     left_col_id: string,
     *     right_col_id: string,
     *     dest_col_id: string,
     *     left_letter: string,
     *     right_letter: string,
     *     expected_inputs: array<mixed>,
     *     written_cells: list<stdClass>,
     * }  $evidence
     * @return array{
     *     passed: bool,
     *     marker: Marker,
     *     row_ids: list<string>,
     *     row_ids_passed: bool,
     *     formulas: list<string>,
     *     expected_formulas: list<string>,
     *     formulas_passed: bool,
     *     values: list<string|int|float|bool|null>,
     *     expected_values: list<int|float>,
     *     values_passed: bool,
     *     sum: int|float,
     *     expected_sum: int|float,
     *     sum_passed: bool,
     * }
     */
    private function evaluateCurrent(string $resourceId, DriveWorkbookOperations $workbooks, array $evidence): array
    {
        $receiptMarker = $evidence['receipt_marker'];
        $targetRowIds = $evidence['row_ids'];
        $leftColId = $evidence['left_col_id'];
        $rightColId = $evidence['right_col_id'];
        $destColId = $evidence['dest_col_id'];
        $neededCols = [$leftColId, $rightColId, $destColId];

        $catalog = WorkbookProblemMapper::call(static fn (): array => $workbooks->catalog($resourceId));
        $catalogMarker = WorkbookProblemMapper::marker($catalog['marker'] ?? null);
        $this->assertCurrentMarkerNotBehind($catalogMarker, $receiptMarker);

        $catalogTable = WorkbookSnapshot::findTable($catalog['workbook'] ?? null, $evidence['sheet_id'], $evidence['table_id']);
        if ($catalogTable === null) {
            throw OfficeException::temporarilyUnavailable('Committed table missing in current workbook.');
        }

        $catColMap = WorkbookSnapshot::columnMap($catalogTable);
        $catRowMap = WorkbookSnapshot::rowMap($catalogTable);
        $sliceCols = array_values(array_filter($neededCols, static fn (string $c): bool => isset($catColMap[$c])));
        $sliceRows = array_values(array_filter($targetRowIds, static fn (string $r): bool => isset($catRowMap[$r])));

        $live = WorkbookProblemMapper::call(
            static fn (): array => $workbooks->readTable($resourceId, $evidence['table_id'], $sliceCols, $sliceRows),
            unknownTargetCode: 'precondition_failed',
        );
        $currentMarker = WorkbookProblemMapper::marker($live['marker'] ?? null);
        $this->assertCurrentMarkerNotBehind($currentMarker, $receiptMarker);

        $table = $live['table'] ?? null;
        if (! $table instanceof stdClass || ($table->sheet_id ?? null) !== $evidence['sheet_id']) {
            throw OfficeException::temporarilyUnavailable('Committed table missing in current workbook.');
        }

        $colMap = WorkbookSnapshot::columnMap($table);
        $rowMap = WorkbookSnapshot::rowMap($table);
        if ($currentMarker->position !== $catalogMarker->position) {
            $liveCols = array_values(array_filter($neededCols, static fn (string $c): bool => isset($colMap[$c])));
            $liveRows = array_values(array_filter($targetRowIds, static fn (string $r): bool => isset($rowMap[$r])));
            if ($liveCols !== $sliceCols || $liveRows !== $sliceRows) {
                $live = WorkbookProblemMapper::call(
                    static fn (): array => $workbooks->readTable($resourceId, $evidence['table_id'], $liveCols, $liveRows),
                    unknownTargetCode: 'precondition_failed',
                );
                $reMarker = WorkbookProblemMapper::marker($live['marker'] ?? null);
                $this->assertCurrentMarkerNotBehind($reMarker, $receiptMarker);
                $currentMarker = $reMarker;
                $table = $live['table'] ?? null;
                if (! $table instanceof stdClass || ($table->sheet_id ?? null) !== $evidence['sheet_id']) {
                    throw OfficeException::temporarilyUnavailable('Committed table missing in current workbook.');
                }
                $colMap = WorkbookSnapshot::columnMap($table);
                $rowMap = WorkbookSnapshot::rowMap($table);
            }
        }

        $columnsExist = isset($colMap[$leftColId], $colMap[$rightColId], $colMap[$destColId]);
        $cellGrid = WorkbookSnapshot::indexCellGrid($live['ranges'] ?? null);

        $leftLetter = $columnsExist ? WorkbookProblemMapper::columnLetter($colMap[$leftColId]) : $evidence['left_letter'];
        $rightLetter = $columnsExist ? WorkbookProblemMapper::columnLetter($colMap[$rightColId]) : $evidence['right_letter'];

        $actualRowIds = [];
        $rowIdsPassed = $columnsExist && $targetRowIds !== [];
        $actualFormulas = [];
        $expectedFormulas = [];
        $formulasPassed = $columnsExist;
        $actualValues = [];
        $expectedValues = [];
        $valuesPassed = $columnsExist;
        $previousRow = 0;
        $actualSum = 0.0;
        $expectedSum = 0.0;
        $allActualInts = true;
        $allExpectedInts = true;

        foreach ($targetRowIds as $rowId) {
            $rowNum = $rowMap[$rowId] ?? null;
            if (! $columnsExist || $rowNum === null || $rowNum <= $previousRow) {
                $rowIdsPassed = false;
                $formulasPassed = false;
                $valuesPassed = false;

                continue;
            }
            $previousRow = $rowNum;
            $actualRowIds[] = $rowId;

            $destCell = $cellGrid[$rowNum][$colMap[$destColId]] ?? null;
            $leftCell = $cellGrid[$rowNum][$colMap[$leftColId]] ?? null;
            $rightCell = $cellGrid[$rowNum][$colMap[$rightColId]] ?? null;

            $expectedFormula = "={$leftLetter}{$rowNum}-{$rightLetter}{$rowNum}";
            $expectedFormulas[] = $expectedFormula;

            $destFormula = $destCell !== null && is_string($destCell->formula ?? null) && $destCell->formula !== ''
                ? $destCell->formula
                : null;
            $destRawVal = $destCell !== null ? ($destCell->value ?? null) : null;

            if ($destFormula !== null) {
                $actualFormulas[] = $destFormula;
                if ($destFormula !== $expectedFormula) {
                    $formulasPassed = false;
                }
            } else {
                $formulasPassed = false;
                $actualFormulas[] = is_scalar($destRawVal) && (string) $destRawVal !== '' ? (string) $destRawVal : '(empty)';
            }

            $destNum = WorkbookProblemMapper::normalizeNumericValue($destRawVal);
            $leftNum = WorkbookProblemMapper::normalizeNumericValue($leftCell !== null ? ($leftCell->value ?? null) : null);
            $rightNum = WorkbookProblemMapper::normalizeNumericValue($rightCell !== null ? ($rightCell->value ?? null) : null);

            if ($leftNum !== null && $rightNum !== null) {
                $computedDiffRaw = (float) $leftNum - (float) $rightNum;
                $computedDiff = is_int($leftNum) && is_int($rightNum) ? ($leftNum - $rightNum) : $computedDiffRaw;
                if (! is_int($computedDiff)) {
                    $allExpectedInts = false;
                }
                $expectedValues[] = $computedDiff;
                $expectedSum += (float) $computedDiff;

                if ($destNum === null || abs((float) $destNum - (float) $computedDiff) > 1e-9) {
                    $valuesPassed = false;
                }
            } else {
                $valuesPassed = false;
            }

            if ($destNum !== null) {
                $actualValues[] = $destNum;
                if (! is_int($destNum)) {
                    $allActualInts = false;
                }
                $actualSum += (float) $destNum;
            } else {
                $valuesPassed = false;
                $actualValues[] = is_scalar($destRawVal) || $destRawVal === null ? $destRawVal : null;
            }
        }

        if ($actualRowIds !== $targetRowIds) {
            $rowIdsPassed = false;
        }

        $normalizedActualSum = $allActualInts ? (int) round($actualSum) : $actualSum;
        $normalizedExpectedSum = $allExpectedInts ? (int) round($expectedSum) : $expectedSum;
        $sumPassed = $valuesPassed && $expectedValues !== [] && abs((float) $normalizedActualSum - (float) $normalizedExpectedSum) <= 1e-9;

        return [
            'passed' => $rowIdsPassed && $formulasPassed && $valuesPassed && $sumPassed,
            'marker' => $currentMarker,
            'row_ids' => $actualRowIds,
            'row_ids_passed' => $rowIdsPassed,
            'formulas' => $actualFormulas,
            'expected_formulas' => $expectedFormulas,
            'formulas_passed' => $formulasPassed,
            'values' => $actualValues,
            'expected_values' => $expectedValues,
            'values_passed' => $valuesPassed,
            'sum' => $normalizedActualSum,
            'expected_sum' => $normalizedExpectedSum,
            'sum_passed' => $sumPassed,
        ];
    }

    /**
     * @param  list<string>  $rowIds
     * @param  list<string>  $formulas
     * @param  list<string|int|float|bool|null>  $values
     * @return list<stdClass>
     */
    private function formatChecks(
        array $rowIds,
        bool $rowIdsPassed,
        array $formulas,
        bool $formulasPassed,
        array $values,
        bool $valuesPassed,
        Marker $calculatedAt,
        int|float $sum,
        bool $sumPassed,
    ): array {
        return [
            (object) ['name' => 'row_ids', 'passed' => $rowIdsPassed, 'actual' => $rowIds],
            (object) ['name' => 'formulas', 'passed' => $formulasPassed, 'actual' => $formulas],
            (object) ['name' => 'calculated_values', 'passed' => $valuesPassed, 'actual' => $values],
            (object) ['name' => 'calculated_at', 'passed' => true, 'actual' => $calculatedAt->toStdClass()],
            (object) ['name' => 'sum', 'passed' => $sumPassed, 'actual' => $sum],
        ];
    }

    /** @param array<string, mixed> $conditions */
    private function requireConditionString(array $conditions, string $key): string
    {
        $val = $conditions[$key] ?? null;
        if (! is_string($val) || $val === '') {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }

        return $val;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    private function requireConditionStringList(array $conditions, string $key): array
    {
        $raw = $conditions[$key] ?? null;
        if (! is_array($raw) || $raw === []) {
            throw new OfficeException(ErrorCode::EvidenceUnavailable);
        }
        $list = [];
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                throw new OfficeException(ErrorCode::EvidenceUnavailable);
            }
            $list[] = $item;
        }

        return $list;
    }

    private function assertCurrentMarkerNotBehind(Marker $current, Marker $receipt): void
    {
        if ($current->generation > $receipt->generation) {
            throw OfficeException::generationChanged('Workbook restored to a newer generation after receipt.');
        }
        if ($current->generation < $receipt->generation || $current->position < $receipt->position) {
            throw new OfficeException(ErrorCode::CalculationPending, 'Workbook state lags behind committed receipt.');
        }
    }
}
