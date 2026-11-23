<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use stdClass;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Documents\Writing\DriveDocumentMutation;
use Vaulter\Ai\Sheets\Host\DriveWorkbookOperations;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Sheets\Content\WorkbookCodec;
use Vaulter\Sheets\Data\WorkbookCommand;

/**
 * End-to-end spreadsheet command test fixture over real Vaulter Drive, Documents, and Sheets ports.
 */
final readonly class SheetCommandFixture
{
    public CallContext $context;

    public TestActor $actor;

    public DriveHandle $drive;

    public NodeSnapshot $node;

    private string $grantId;

    /**
     * @param  array<string, mixed>|null  $seed
     * @param  array<string, array{formula?: string, number?: int|float, text?: string}>  $initialDeltaOverrides
     */
    public function __construct(
        private Application $app,
        bool $automatic = true,
        ?array $seed = null,
        array $initialDeltaOverrides = [],
    ) {
        Config::set('vaulter-ai-documents.default_tenant_id', '61');
        Config::set('vaulter-ai-sheets.default_tenant_id', '61');
        Config::set('vaulter-ai.profiles.sheets.approval', $automatic ? 'automatic' : 'human');

        $this->actor = TestActor::query()->create(['name' => 'Sheet Editor', 'email' => 'sheet-editor+'.Str::uuid().'@example.test']);
        $owner = TestOwner::query()->create(['name' => 'Sheet Workspace']);
        $this->drive = Vaulter::drive($owner)->asSystem('seed-sheet-test');
        $grant = $this->drive->access()->grant($this->drive->root()->id, new GrantInput(Subject::ofModel($this->actor), RoleRegistry::WRITER));
        $this->grantId = $grant->id;

        $workbookPayload = self::buildWorkbookPayload($seed, $initialDeltaOverrides);
        $this->node = Documents::in($this->drive->actingAs($this->actor))
            ->create('Бюджет', 'vaulter/spreadsheet', $workbookPayload);

        $this->context = new CallContext(
            tenantId: '61',
            projectScope: 'default',
            effectiveSubject: 'user/'.$this->actor->getKey(),
            profile: 'sheets',
            driveId: $this->drive->snapshot()->id,
            actor: $this->actor,
        );

        if ($seed !== null && isset($seed['marker']) && is_array($seed['marker'])) {
            $this->advanceTo($seed['marker']);
        }
    }

    /** @param array<string, mixed> $input */
    public function call(OfficeOperation $operation, array $input, ?CallContext $context = null): OfficeResponse
    {
        $payload = $operation === OfficeOperation::Search
            ? $input
            : ['resource_id' => $this->node->id] + $input;

        return $this->app->make(Office::class)->handle(
            $context ?? $this->context,
            new OfficeRequest(1, $operation, (object) $payload),
        );
    }

    public function authorizeHttp(?CallContext $context = null): void
    {
        $bound = $context ?? $this->context;
        $this->app->instance(CallContextResolver::class, new class($bound) implements CallContextResolver
        {
            public function __construct(public ?CallContext $current) {}

            public function resolve(Request $request): ?CallContext
            {
                return $request->bearerToken() === 'scenario-test-token' ? $this->current : null;
            }
        });
    }

    public function revoke(): void
    {
        $this->drive->access()->revoke($this->drive->root()->id, $this->grantId);
    }

    public function downgradeToReader(): string
    {
        $this->revoke();
        $grant = $this->drive->access()->grant($this->drive->root()->id, new GrantInput(Subject::ofModel($this->actor), RoleRegistry::READER));

        return $grant->id;
    }

    public function snapshot(): DocumentSnapshot
    {
        return Documents::in($this->drive->actingAs($this->actor))->content($this->node->id);
    }

    public function catalog(): array
    {
        $driveCtx = $this->drive->actingAs($this->actor)->extension(DriveWorkbookOperations::class);

        return $driveCtx->catalog($this->node->id);
    }

    /**
     * Insert a row via real Vaulter Sheets WorkbookOperations before the target row_id and populate its columns.
     *
     * @param  array{plan: int|float, actual: int|float}  $values
     * @return array{marker: array{generation: int, position: int}, inserted_row_id: string}
     */
    public function humanInsertRowBefore(string $sheetId, string $tableId, string $beforeRowId, array $values): array
    {
        $ops = $this->drive->actingAs($this->actor)->extension(DriveWorkbookOperations::class);
        $catalog = $ops->catalog($this->node->id);
        $marker = $catalog['marker'];
        $etag = $catalog['etag'];

        $table = null;
        foreach ($catalog['workbook']->tables as $t) {
            if ($t->table_id === $tableId && $t->sheet_id === $sheetId) {
                $table = $t;
                break;
            }
        }
        expect($table)->not->toBeNull();

        $beforeRow = null;
        foreach ($table->rows as $r) {
            if ($r->row_id === $beforeRowId) {
                $beforeRow = $r->row;
                break;
            }
        }
        expect($beforeRow)->toBeInt();

        $cols = array_column($table->columns, 'column', 'column_id');
        $planCol = $cols['plan'];
        $actualCol = $cols['actual'];
        $minCol = min($planCol, $actualCol);

        $beforeIds = array_column($table->rows, 'row_id');

        $outcome = $ops->commit($this->node->id, new WorkbookCommand(
            id: 'human-insert-'.Str::uuid7(),
            policy: 'exact_base',
            generation: $marker->generation,
            ifMatch: $etag,
            commands: [
                (object) [
                    'kind' => 'insert_row_before',
                    'sheet_id' => $sheetId,
                    'row' => $beforeRow,
                    'count' => 1,
                ],
                (object) [
                    'kind' => 'set_values',
                    'sheet_id' => $sheetId,
                    'row' => $beforeRow,
                    'column' => $minCol,
                    'expected' => [[null, null]],
                    'values' => [[
                        (object) ['number' => $values['plan']],
                        (object) ['number' => $values['actual']],
                    ]],
                ],
            ],
        ));
        expect($outcome['state'])->toBe('committed');

        $afterCatalog = $ops->catalog($this->node->id);
        $afterIds = [];
        foreach ($afterCatalog['workbook']->tables as $t) {
            if ($t->table_id === $tableId && $t->sheet_id === $sheetId) {
                $afterIds = array_column($t->rows, 'row_id');
                break;
            }
        }
        $added = array_values(array_diff($afterIds, $beforeIds));
        expect($added)->toHaveCount(1);

        $newMarker = $afterCatalog['marker'];

        return [
            'marker' => ['generation' => $newMarker->generation, 'position' => $newMarker->position],
            'inserted_row_id' => (string) $added[0],
        ];
    }

    public function humanSetNumber(string $sheetId, int $row, int $column, mixed $expected, int|float $value): array
    {
        $ops = $this->drive->actingAs($this->actor)->extension(DriveWorkbookOperations::class);
        $catalog = $ops->catalog($this->node->id);

        return $ops->commit($this->node->id, new WorkbookCommand(
            id: 'human-set-num-'.Str::uuid7(),
            policy: 'exact_base',
            generation: $catalog['marker']->generation,
            ifMatch: $catalog['etag'],
            commands: [
                (object) [
                    'kind' => 'set_number',
                    'sheet_id' => $sheetId,
                    'row' => $row,
                    'column' => $column,
                    'expected' => $expected,
                    'value' => $value,
                ],
            ],
        ));
    }

    /** @return list<stdClass> */
    public function readTableCells(string $tableId = 'costs'): array
    {
        $res = $this->drive->actingAs($this->actor)->extension(DriveWorkbookOperations::class)->readTable($this->node->id, $tableId);

        return $res['ranges'];
    }

    public function removeTableRow(string $sheetId, string $tableId, string $rowId): void
    {
        $codec = $this->app->make(WorkbookCodec::class);
        $mutation = $this->drive->actingAs($this->actor)->extension(DriveDocumentMutation::class);
        $mutation->run($this->node->id, static function (LockedDocumentMutation $locked) use ($codec, $sheetId, $tableId, $rowId): void {
            $snap = $locked->snapshot();
            $raw = json_decode(json_encode($codec->snapshot($snap->content), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            foreach ($raw['tables'] as &$table) {
                if (($table['sheet_id'] ?? null) === $sheetId && ($table['table_id'] ?? null) === $tableId) {
                    $table['rows'] = array_values(array_filter(
                        $table['rows'],
                        static fn (array $r): bool => ($r['row_id'] ?? null) !== $rowId,
                    ));
                }
            }
            unset($table);
            $locked->replace($codec->decode($raw, 2), $snap->position);
        });
    }

    /**
     * @param  array<string, mixed>|null  $seed
     * @param  array<string, array{formula?: string, number?: int|float, text?: string}>  $initialDeltaOverrides
     * @return array<string, mixed>
     */
    private static function buildWorkbookPayload(?array $seed, array $initialDeltaOverrides): array
    {
        $sheetId = $seed['sheet_id'] ?? 'expenses';
        $tableId = $seed['table_id'] ?? 'costs';
        $firstDataRow = $seed['first_data_row'] ?? 2;
        $headerRow = $firstDataRow - 1;
        $rows = $seed['rows'] ?? [
            ['row_id' => 'r-telecom', 'plan' => 100, 'actual' => 120, 'delta' => null],
            ['row_id' => 'r-travel', 'plan' => 200, 'actual' => 180, 'delta' => null],
            ['row_id' => 'r-fees', 'plan' => 10, 'actual' => 20, 'delta' => 77],
        ];

        $cellData = [
            (string) ($headerRow - 1) => [
                '1' => ['t' => 1, 'v' => 'План'],
                '2' => ['t' => 1, 'v' => 'Факт'],
                '3' => ['t' => 1, 'v' => 'Отклонение'],
            ],
        ];

        $rowBindings = [];
        foreach ($rows as $idx => $rowSpec) {
            $oneBasedRow = $firstDataRow + $idx;
            $zeroRow = (string) ($oneBasedRow - 1);
            $rowBindings[] = ['row_id' => $rowSpec['row_id'], 'row' => $oneBasedRow];

            $rowCells = [
                '0' => ['t' => 1, 'v' => $rowSpec['row_id']],
            ];
            if (is_int($rowSpec['plan']) || is_float($rowSpec['plan'])) {
                $rowCells['1'] = ['t' => 2, 'v' => $rowSpec['plan']];
            } elseif (is_string($rowSpec['plan'])) {
                $rowCells['1'] = ['t' => 1, 'v' => $rowSpec['plan']];
            }

            if (is_int($rowSpec['actual']) || is_float($rowSpec['actual'])) {
                $rowCells['2'] = ['t' => 2, 'v' => $rowSpec['actual']];
            } elseif (is_string($rowSpec['actual'])) {
                $rowCells['2'] = ['t' => 1, 'v' => $rowSpec['actual']];
            }

            $override = $initialDeltaOverrides[$rowSpec['row_id']] ?? null;
            if ($override !== null) {
                $deltaCell = [];
                if (isset($override['formula'])) {
                    $deltaCell['f'] = $override['formula'];
                    $deltaCell['t'] = 2;
                    $deltaCell['v'] = $override['number'] ?? 0;
                } elseif (isset($override['number'])) {
                    $deltaCell['t'] = 2;
                    $deltaCell['v'] = $override['number'];
                } elseif (isset($override['text'])) {
                    $deltaCell['t'] = 1;
                    $deltaCell['v'] = $override['text'];
                }
                if ($deltaCell !== []) {
                    $rowCells['3'] = $deltaCell;
                }
            } elseif (isset($rowSpec['delta'])) {
                if (is_int($rowSpec['delta']) || is_float($rowSpec['delta'])) {
                    $rowCells['3'] = ['t' => 2, 'v' => $rowSpec['delta']];
                } elseif (is_string($rowSpec['delta'])) {
                    $rowCells['3'] = ['t' => 1, 'v' => $rowSpec['delta']];
                }
            }

            $cellData[$zeroRow] = $rowCells;
        }

        $profile = json_decode(json_encode(WorkbookCodec::profile(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return [
            'format_version' => 2,
            'profile' => $profile,
            'native' => [
                'id' => 'budget-8-native',
                'name' => 'Бюджет',
                'appVersion' => '1.5.2',
                'locale' => 'ruRU',
                'styles' => [],
                'resources' => [
                    ['name' => 'SHEET_DEFINED_NAME_PLUGIN', 'data' => ''],
                ],
                'sheetOrder' => [$sheetId],
                'sheets' => [
                    $sheetId => [
                        'id' => $sheetId,
                        'name' => 'Расходы',
                        'rowCount' => 100,
                        'columnCount' => 20,
                        'cellData' => $cellData,
                    ],
                ],
            ],
            'tables' => [
                [
                    'table_id' => $tableId,
                    'sheet_id' => $sheetId,
                    'header_row' => $headerRow,
                    'columns' => [
                        ['column_id' => 'plan', 'column' => 2],
                        ['column_id' => 'actual', 'column' => 3],
                        ['column_id' => 'delta', 'column' => 4],
                    ],
                    'rows' => $rowBindings,
                ],
            ],
        ];
    }

    /** @param array{generation: int, position: int} $marker */
    private function advanceTo(array $marker): void
    {
        $codec = $this->app->make(WorkbookCodec::class);
        $acting = $this->drive->actingAs($this->actor);
        $documents = Documents::in($acting);

        $state = $this->snapshot();
        $revision = $state->headRevisionId;
        for ($epoch = $state->epoch; $epoch < $marker['generation']; $epoch++) {
            $state = $documents->restore($this->node->id, $revision, $state->stateVersion);
        }

        $stableArray = json_decode(
            json_encode($codec->snapshot($state->content), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $mutation = $acting->extension(DriveDocumentMutation::class);
        while ($state->stateVersion < $marker['position']) {
            $previousVersion = $state->stateVersion;
            $targetArray = $stableArray;
            if ($state->stateVersion + 1 < $marker['position']) {
                $targetArray['native']['sheets'][$stableArray['native']['sheetOrder'][0]]['cellData']['98']['18'] = [
                    't' => 2,
                    'v' => $state->stateVersion,
                ];
            }
            $decoded = $codec->decode($targetArray, 2);
            $mutation->run($this->node->id, function (LockedDocumentMutation $locked) use ($decoded): void {
                $snap = $locked->snapshot();
                $locked->replace($decoded, $snap->position);
            });
            $state = $this->snapshot();
            if ($state->stateVersion <= $previousVersion) {
                throw new \RuntimeException("Workbook state_version did not advance from {$previousVersion} toward {$marker['position']}.");
            }
        }

        expect([$state->epoch, $state->stateVersion])->toBe([$marker['generation'], $marker['position']]);
    }
}
