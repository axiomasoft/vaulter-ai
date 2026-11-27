<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Laravel\Ai\Responses\Data\ToolCall;
use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Contracts\SheetVerificationEvidence;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Sheets\Writing\SheetCommandOwner;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Schema\SchemaHelpers;
use Vaulter\Sheets\Contracts\WorkbookOperations;
use Vaulter\Sheets\Contracts\WorkbookRuntime;
use Vaulter\Sheets\Data\WorkbookCommand;
use Vaulter\Sheets\Data\WorkbookMarker;
use Vaulter\Sheets\Data\WorkbookProblem;

uses(LaravelAiTestCase::class);

test('verify adapter refuses all schema-valid spreadsheet proof negatives including stale marker, literal formulas, swapped formulas, swapped values, and foreign receipt', function (Closure $mutate): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);

    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $prop = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $read->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $fixture->humanInsertRowBefore('expenses', 'costs', 'r-travel', ['plan' => 40, 'actual' => 55]);
    $commit = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'calc-neg-1',
        'proposal_id' => $prop->proposal_id,
        'digest' => $prop->digest,
    ]);
    expect($commit->ok)->toBeTrue();
    $receipt = $commit->result->receipt_id;

    $input = ['receipt_id' => $receipt, 'mode' => 'at_commit'];
    expect($fixture->call(OfficeOperation::Verify, $input)->result->outcome)->toBe('passed');

    $real = $this->app->make(OfficeRegistry::class)->owner(SheetCommandOwner::KEY);
    $validator = $this->app->make(WireValidator::class);

    $proxy = new class($real, $mutate, $validator) implements CommandOwner, SheetVerificationEvidence
    {
        public function __construct(
            private readonly SheetCommandOwner $owner,
            private readonly Closure $mutate,
            private readonly WireValidator $validator,
        ) {}

        public function key(): string
        {
            return $this->owner->key();
        }

        public function supports(string $command, ?string $writeMode = null): bool
        {
            return $this->owner->supports($command, $writeMode);
        }

        public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome
        {
            return $this->owner->commit($scope, $plan);
        }

        public function status(CommandScope $scope): CommandOutcome
        {
            return $this->owner->status($scope);
        }

        public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass
        {
            $result = $this->owner->verify($context, $resourceId, $receiptId, $mode);
            expect($result->outcome)->toBe('passed');
            ($this->mutate)($result);
            expect($this->validator->isValidMessage(OfficeResponse::success(OfficeOperation::Verify, $result)->toStdClass()))
                ->toBeTrue('Mutated verify payload must remain schema-valid so only adapter proof rejects it.');

            return $result;
        }

        public function expectedSheetChecks(CallContext $context, string $resourceId, string $receiptId, Marker $receiptMarker, Marker $verifyMarker, string $mode): array
        {
            return $this->owner->expectedSheetChecks($context, $resourceId, $receiptId, $receiptMarker, $verifyMarker, $mode);
        }
    };

    $this->freshRegistry()->registerOwner('sheet.fill_formula@1', 'snapshot', $proxy);
    $refused = $fixture->call(OfficeOperation::Verify, $input);
    expect($refused->ok)->toBeFalse()->and($refused->errorCode)->toBe(ErrorCode::TemporarilyUnavailable);
})->with([
    'stale calculation marker' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'calculated_at') {
                $check->actual = (object) ['generation' => $result->marker->generation, 'position' => $result->marker->position - 1];
            }
        }
    }],
    'verify marker before receipt' => [static function (stdClass $result): void {
        $result->marker->position--;
        foreach ($result->checks as $check) {
            if ($check->name === 'calculated_at') {
                $check->actual = (object) ['generation' => $result->marker->generation, 'position' => $result->marker->position];
            }
        }
    }],
    'literal formulas' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'formulas') {
                $check->actual = ['=20', '=-20'];
            }
        }
    }],
    'formulas of the other rows' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'formulas') {
                $check->actual = ['=C4-B4', '=C2-B2'];
            }
        }
    }],
    'swapped values with the same sum' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'calculated_values') {
                $check->actual = [-20, 20];
            }
        }
    }],
    'other receipt' => [static function (stdClass $result): void {
        $result->receipt_id = 'receipt-sheet-foreign';
    }],
    'wrong sum' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'sum') {
                $check->actual = 999;
            }
        }
    }],
    'swapped row_ids with matching formulas and values' => [static function (stdClass $result): void {
        foreach ($result->checks as $check) {
            if ($check->name === 'row_ids') {
                $check->actual = ['r-travel', 'r-telecom'];
            } elseif ($check->name === 'formulas') {
                $check->actual = ['=C4-B4', '=C2-B2'];
            } elseif ($check->name === 'calculated_values') {
                $check->actual = [-20, 20];
            }
        }
    }],
]);

test('corrupted cache 999 is recalculated on read/verify and literal constant replacement fails verify(current) while at_commit stays passed', function (): void {
    $runtime = new class($this->app->make(WorkbookRuntime::class)) implements WorkbookRuntime
    {
        public bool $corruptRead = false;

        public function __construct(private readonly WorkbookRuntime $inner) {}

        public function exchange(stdClass $request): stdClass
        {
            $resp = $this->inner->exchange($request);
            if ($this->corruptRead && ($request->operation ?? null) === 'read') {
                foreach ($resp->data->ranges ?? [] as $r) {
                    foreach ($r->cells ?? [] as $cell) {
                        if ($cell->a1 === 'D2') {
                            $cell->value = 999;
                        }
                    }
                }
            }

            return $resp;
        }
    };
    $this->app->instance(WorkbookRuntime::class, $runtime);

    // Seed workbook with pre-existing formula =C2-B2 and corrupted cache v = 999!
    $fixture = new SheetCommandFixture(
        $this->app,
        automatic: true,
        initialDeltaOverrides: [
            'r-telecom' => ['formula' => '=C2-B2', 'number' => 999],
        ],
    );

    // 1. Live calculation barrier recalculates cached 999 to true value 20.
    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual', 'delta'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    expect($read->ok)->toBeTrue()
        ->and($read->result->data->rows[0]->values->delta)->toBe(20);

    // 2. Commit fill_formula on a fresh workbook, then replace D2 with constant 20 (same number, no formula).
    $fresh = new SheetCommandFixture($this->app, automatic: true);
    $r = $fresh->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $p = $fresh->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $r->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $c = $fresh->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-const-check',
        'proposal_id' => $p->proposal_id,
        'digest' => $p->digest,
    ]);
    $receipt = $c->result->receipt_id;

    // Overwrite D2 formula =C2-B2 with literal number 20 (same numeric value and same sum = 0!).
    $fresh->humanSetNumber('expenses', 2, 4, (object) ['formula' => '=C2-B2'], 20);

    $currentFailed = $fresh->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'current']);
    expect($currentFailed->ok)->toBeTrue()
        ->and($currentFailed->result->outcome)->toBe('failed');
    $byName = array_column($currentFailed->result->checks, null, 'name');
    expect($byName['formulas']->passed)->toBeFalse()
        ->and($byName['calculated_values']->passed)->toBeTrue()
        ->and($byName['sum']->actual)->toBe(0);

    // Historical at_commit still passes!
    expect($fresh->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'at_commit'])->result->outcome)
        ->toBe('passed');

    // 3. Inject stale runtime cache 999 fault during current verification -> verify(current) detects value mismatch and returns outcome=failed.
    // Test on a workbook where D2 still holds the real formula:
    $freshForCache = new SheetCommandFixture($this->app, automatic: true);
    $r2 = $freshForCache->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $p2 = $freshForCache->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $r2->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;
    $c2 = $freshForCache->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-cache-999',
        'proposal_id' => $p2->proposal_id,
        'digest' => $p2->digest,
    ]);
    expect($c2->ok)->toBeTrue();

    // A ready projection serves reads without the runtime. Remove this workbook's head to exercise the fallback.
    $schema = $this->app->make(SchemaHelpers::class);
    $schema->dbConnection()->table($schema->table('sheets_projection_heads'))
        ->where('document_id', $freshForCache->snapshot()->documentId)->delete();
    $runtime->corruptRead = true;
    $cacheFail = $freshForCache->call(OfficeOperation::Verify, ['receipt_id' => $c2->result->receipt_id, 'mode' => 'current']);
    expect($cacheFail->ok)->toBeTrue()
        ->and($cacheFail->result->outcome)->toBe('failed');
    $cacheChecks = array_column($cacheFail->result->checks, null, 'name');
    expect($cacheChecks['calculated_values']->actual)->toBe([999, -20])
        ->and($cacheChecks['calculated_values']->passed)->toBeFalse()
        ->and($cacheChecks['sum']->passed)->toBeFalse();
});

test('runtime calculation timeout returns calculation_pending on commit without mutating workbook, recovers on retry, and rejects if inputs changed before retry', function (): void {
    $runtime = new class($this->app->make(WorkbookRuntime::class)) implements WorkbookRuntime
    {
        public bool $timeout = false;

        public function __construct(private readonly WorkbookRuntime $inner) {}

        public function exchange(stdClass $request): stdClass
        {
            if ($this->timeout && ($request->operation ?? null) === 'apply') {
                throw new WorkbookProblem('calculation_pending', 503);
            }

            return $this->inner->exchange($request);
        }
    };
    $this->app->instance(WorkbookRuntime::class, $runtime);

    $fixture = new SheetCommandFixture($this->app, automatic: true);
    $fixture->authorizeHttp();
    $r = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $p = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $r->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $runtime->timeout = true;
    $beforeVersion = $fixture->snapshot()->stateVersion;
    $failed = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-timeout-1',
        'proposal_id' => $p->proposal_id,
        'digest' => $p->digest,
    ]);
    expect($failed->ok)->toBeFalse()
        ->and($failed->errorCode)->toBe(ErrorCode::CalculationPending)
        ->and($failed->nextAction)->toBe(NextAction::RetryRead)
        ->and($fixture->snapshot()->stateVersion)->toBe($beforeVersion)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'cmd-timeout-1'])->result->state)->toBe('running');

    $httpTimeout = $this->postJson('/api/vaulter-ai/v1/operations', [
        'version' => 1,
        'operation' => 'office.commit',
        'input' => [
            'resource_id' => $fixture->node->id,
            'command_id' => 'cmd-timeout-1',
            'proposal_id' => $p->proposal_id,
            'digest' => $p->digest,
        ],
    ], ['Authorization' => 'Bearer scenario-test-token']);
    $httpTimeout->assertStatus(409)
        ->assertJsonPath('error.code', 'calculation_pending')
        ->assertJsonPath('error.retryable', true)
        ->assertJsonPath('error.next_action', 'retry_read');

    $runtime->timeout = false;
    $recovered = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-timeout-1',
        'proposal_id' => $p->proposal_id,
        'digest' => $p->digest,
    ]);
    expect($recovered->ok)->toBeTrue()
        ->and($recovered->result->state)->toBe('committed')
        ->and($fixture->snapshot()->stateVersion)->toBe($beforeVersion + 1);

    // If C2 changes from 120 to 130 after a calculation_pending timeout, retrying the same command_id must reject with precondition_failed and leave D2 unchanged.
    $fixture2 = new SheetCommandFixture($this->app, automatic: true);
    $r2 = $fixture2->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $p2 = $fixture2->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $r2->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $runtime->timeout = true;
    $timedOut2 = $fixture2->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-timeout-changed-input',
        'proposal_id' => $p2->proposal_id,
        'digest' => $p2->digest,
    ]);
    expect($timedOut2->ok)->toBeFalse()->and($timedOut2->errorCode)->toBe(ErrorCode::CalculationPending);

    $runtime->timeout = false;
    $fixture2->humanSetNumber('expenses', 2, 3, (object) ['number' => 120], 130);

    $retriedAfterEdit = $fixture2->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-timeout-changed-input',
        'proposal_id' => $p2->proposal_id,
        'digest' => $p2->digest,
    ]);
    expect($retriedAfterEdit->ok)->toBeFalse()
        ->and($retriedAfterEdit->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($fixture2->call(OfficeOperation::Status, ['command_id' => 'cmd-timeout-changed-input'])->result->state)->toBe('rejected');

    $cells2 = [];
    foreach ($fixture2->readTableCells('costs') as $range) {
        foreach ($range->cells as $c) {
            $cells2[$c->a1] = $c;
        }
    }
    expect($cells2['D2']->value)->toBeNull()
        ->and($cells2['D2']->formula)->toBeNull();
});

test('two-row fill passes verify(at_commit) when first target already contains matching formula and verify(current) returns calculation_pending when state lags behind receipt', function (): void {
    $fixture = new SheetCommandFixture(
        $this->app,
        automatic: true,
        initialDeltaOverrides: [
            'r-telecom' => ['formula' => '=C2-B2', 'number' => 20],
        ],
    );

    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $prop = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $read->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $commit = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-preseeded-d2',
        'proposal_id' => $prop->proposal_id,
        'digest' => $prop->digest,
    ]);
    expect($commit->ok)->toBeTrue();
    $receipt = $commit->result->receipt_id;

    $atCommit = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'at_commit']);
    expect($atCommit->ok)->toBeTrue()
        ->and($atCommit->result->outcome)->toBe('passed');
    $checks = array_column($atCommit->result->checks, null, 'name');
    expect($checks['row_ids']->actual)->toBe(['r-telecom', 'r-travel'])
        ->and($checks['formulas']->actual)->toBe(['=C2-B2', '=C3-B3'])
        ->and($checks['calculated_values']->actual)->toBe([20, -20])
        ->and($checks['sum']->actual)->toBe(0);

    // Full no-op fill where both targets already match also passes verify(at_commit).
    $readAllMatch = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $propAllMatch = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $readAllMatch->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;
    $commitAllMatch = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-all-matched-noop',
        'proposal_id' => $propAllMatch->proposal_id,
        'digest' => $propAllMatch->digest,
    ]);
    expect($commitAllMatch->ok)->toBeTrue();
    expect($fixture->call(OfficeOperation::Verify, ['receipt_id' => $commitAllMatch->result->receipt_id, 'mode' => 'at_commit'])->result->outcome)
        ->toBe('passed');

    // When catalog/read marker lags behind committed receipt marker, verify(current) returns calculation_pending.
    $realOps = $this->app->make(WorkbookOperations::class);
    $this->app->instance(WorkbookOperations::class, new class($realOps) implements WorkbookOperations
    {
        public function __construct(private readonly WorkbookOperations $inner) {}

        public function catalog(DriveContext $context, string $node): array
        {
            $res = $this->inner->catalog($context, $node);
            $res['marker'] = new WorkbookMarker(
                $res['marker']->generation,
                max(0, $res['marker']->position - 1),
                $res['marker']->sha256,
            );

            return $res;
        }

        public function readTable(DriveContext $context, string $node, string $table, ?array $columns = null, ?array $rowIds = null): array
        {
            return $this->inner->readTable($context, $node, $table, $columns, $rowIds);
        }

        public function prepareFormula(DriveContext $context, string $node, string $table, string $destination, string $left, string $right, string $scope): array
        {
            return $this->inner->prepareFormula($context, $node, $table, $destination, $left, $right, $scope);
        }

        public function commit(DriveContext $context, string $node, WorkbookCommand $command): array
        {
            return $this->inner->commit($context, $node, $command);
        }

        public function status(DriveContext $context, string $node, string $command): array
        {
            return $this->inner->status($context, $node, $command);
        }

        public function verify(DriveContext $context, string $node, string $command): array
        {
            return $this->inner->verify($context, $node, $command);
        }
    });

    $laggingVerify = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'current']);
    expect($laggingVerify->ok)->toBeFalse()
        ->and($laggingVerify->errorCode)->toBe(ErrorCode::CalculationPending)
        ->and($laggingVerify->nextAction)->toBe(NextAction::RetryRead);
});

test('non-numeric operand cell returns unsupported_content during prepare', function (): void {
    $fixture = new SheetCommandFixture(
        $this->app,
        automatic: true,
        seed: [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'first_data_row' => 2,
            'rows' => [
                ['row_id' => 'r-telecom', 'plan' => 'not-a-number', 'actual' => 120, 'delta' => null],
            ],
        ],
    );

    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
        ],
    ]);
    expect($read->ok)->toBeTrue();

    $prepare = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $read->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'current_rows',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($prepare->ok)->toBeFalse()->and($prepare->errorCode)->toBe(ErrorCode::UnsupportedContent);
});

test('Laravel AI executor completes end-to-end sheet search->read->describe->prepare->commit->verify flow', function (): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);
    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $runs = $this->app->make(Runs::class);
    $eventId = 'evt-sheet-agent-1';

    Config::set('ai.default', 'openai');
    Config::set('ai.providers.openai', ['driver' => 'openai', 'key' => 'sk-sheet-secret']);
    Config::set('vaulter-ai.profiles.sheets', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['sheet.fill_formula@1'],
        'approval' => 'automatic',
        'revision' => 'rev-s1',
    ]);
    $this->app->instance(RunContextResolver::class, new class($fixture->context) implements RunContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(string $tenantId, string $runId): ?CallContext
        {
            return $this->ctx->tenantId === $tenantId ? $this->ctx : null;
        }
    });

    $turn = 0;
    OfficeAgent::fake(function () use (&$turn, $fixture, $runs, $eventId): mixed {
        $run = $runs->createOrFind($fixture->context, 'laravel-ai', $eventId, 'rev-s1', ['prompt' => 'fill delta']);
        $steps = $runs->listSteps($fixture->context, $run->runId);
        $latest = static function (OfficeOperation $op) use ($steps): ?array {
            $match = null;
            foreach ($steps as $s) {
                if ($s->operation === $op) {
                    $match = $s->compactResult;
                }
            }

            return $match;
        };

        return match ($turn++) {
            0 => new ToolCall('ts-1', 'office_search', ['query' => 'Бюджет', 'resource_type' => 'sheet', 'limit' => 5]),
            1 => new ToolCall('ts-2', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'sheet_catalog']]),
            2 => new ToolCall('ts-3', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'sheet_table', 'sheet_id' => 'expenses', 'table_id' => 'costs', 'columns' => ['plan', 'actual'], 'row_ids' => ['r-telecom', 'r-travel']]]),
            3 => new ToolCall('ts-4', 'office_describe', ['resource_id' => $fixture->node->id, 'command' => 'sheet.fill_formula@1']),
            4 => new ToolCall('ts-5', 'office_prepare', [
                'resource_id' => $fixture->node->id,
                'command' => 'sheet.fill_formula@1',
                'read_token' => (string) ($latest(OfficeOperation::Read)['result']['read_token'] ?? ''),
                'arguments' => [
                    'sheet_id' => 'expenses',
                    'table_id' => 'costs',
                    'scope' => 'fixed_members',
                    'destination_column_id' => 'delta',
                    'expression' => ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
                ],
            ]),
            5 => new ToolCall('ts-6', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => (string) ($latest(OfficeOperation::Prepare)['result']['proposal_id'] ?? ''),
                'digest' => (string) ($latest(OfficeOperation::Prepare)['result']['digest'] ?? ''),
            ]),
            6 => new ToolCall('ts-7', 'office_verify', [
                'resource_id' => $fixture->node->id,
                'receipt_id' => (string) ($latest(OfficeOperation::Commit)['result']['receipt_id'] ?? ''),
                'mode' => 'at_commit',
            ]),
            default => 'Filled formula column delta and verified results.',
        };
    })->preventStrayPrompts();

    $executor->dispatch($fixture->context, [
        'channel' => 'laravel-ai',
        'source_event_id' => $eventId,
        'prompt' => 'Calculate delta for r-telecom and r-travel.',
        'resource_id' => $fixture->node->id,
    ]);

    $run = $runs->createOrFind($fixture->context, 'laravel-ai', $eventId, 'rev-s1', ['prompt' => '']);
    expect($run->state)->toBe('completed')
        ->and($runs->listSteps($fixture->context, $run->runId))->toHaveCount(7);
});

test('prepare rejects input cell changed since read and verify(at_commit) stays passed after restore while verify(current) reports generation_changed', function (): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);

    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    expect($read->ok)->toBeTrue();

    // Mutate C2 between read and prepare -> prepare rejects with precondition_failed instead of sealing stale read values.
    $fixture->humanSetNumber('expenses', 2, 3, (object) ['number' => 120], 130);
    $stalePrepare = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $read->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($stalePrepare->ok)->toBeFalse()->and($stalePrepare->errorCode)->toBe(ErrorCode::PreconditionFailed);

    // Re-read, prepare, commit, then restore workbook to new generation: at_commit stays passed, current returns generation_changed.
    $freshRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $prop = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $freshRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;
    $commit = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-restore-verify-1',
        'proposal_id' => $prop->proposal_id,
        'digest' => $prop->digest,
    ]);
    expect($commit->ok)->toBeTrue();
    $receipt = $commit->result->receipt_id;

    $snap = $fixture->snapshot();
    Documents::in($fixture->drive->actingAs($fixture->actor))
        ->restore($fixture->node->id, $snap->headRevisionId, $snap->stateVersion);

    $atCommit = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'at_commit']);
    expect($atCommit->ok)->toBeTrue()->and($atCommit->result->outcome)->toBe('passed');

    $currentAfterRestore = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'current']);
    expect($currentAfterRestore->ok)->toBeFalse()->and($currentAfterRestore->errorCode)->toBe(ErrorCode::GenerationChanged);
});
