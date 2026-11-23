<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Sheets\Content\WorkbookCodec;

uses(OfficeTestCase::class);

function replaySheetScenarios(): array
{
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json'), true, 512, JSON_THROW_ON_ERROR);

    return array_column($fixture['scenarios'], null, 'id');
}

function mapSheetScenarioValues(array $message, array $ids): array
{
    array_walk_recursive($message, static function (mixed &$value) use ($ids): void {
        if (is_string($value) && isset($ids[$value])) {
            $value = $ids[$value];
        }
    });

    return $message;
}

test('PHP and HTTP replay sheet_fixed_members preserving untouched cells D3 empty and D5 = 77', function (string $transport): void {
    $scenario = replaySheetScenarios()['sheet_fixed_members'];
    $seed = $scenario['events'][0]['details'];
    $fixture = new SheetCommandFixture($this->app, automatic: true, seed: $seed);
    $fixture->authorizeHttp();
    $this->fixedClock->set('2026-11-21T11:00:00Z');

    $ids = ['budget-8' => $fixture->node->id];
    $validator = $this->app->make(WireValidator::class);

    foreach ($scenario['steps'] as $index => $step) {
        $stepNum = $index + 1;
        foreach ($scenario['events'] as $event) {
            if ($event['before_step'] !== $stepNum) {
                continue;
            }
            if ($event['type'] === 'human_inserts_row') {
                $details = $event['details'];
                $inserted = $fixture->humanInsertRowBefore(
                    $details['sheet_id'],
                    $details['table_id'],
                    $details['before_row_id'],
                    ['plan' => $details['row']['plan'], 'actual' => $details['row']['actual']],
                );
                expect($inserted['marker'])->toBe($details['marker']);
                $ids[$details['row']['row_id']] = $inserted['inserted_row_id'];
            } elseif ($event['type'] === 'assert_cells_at_marker') {
                $proof = $event['details'];
                $snap = $fixture->snapshot();
                expect([$snap->epoch, $snap->stateVersion])->toBe([$proof['marker']['generation'], $proof['marker']['position']]);

                $workbook = $fixture->catalog()['workbook'];
                $table = $workbook->tables[0];
                $rowsById = array_column($table->rows, 'row', 'row_id');
                $colsById = array_column($table->columns, 'column', 'column_id');
                $deltaCol = $colsById['delta'];

                $ranges = $fixture->readTableCells('costs');
                $cellByCoord = [];
                foreach ($ranges as $r) {
                    foreach ($r->cells as $c) {
                        $cellByCoord[$c->a1] = $c;
                    }
                }
                expect($cellByCoord['D2']->formula)->toBe('=C2-B2')
                    ->and($cellByCoord['D2']->value)->toBe(20)
                    ->and($cellByCoord['D4']->formula)->toBe('=C4-B4')
                    ->and($cellByCoord['D4']->value)->toBe(-20);

                foreach ($proof['cells'] as $a1 => $expectedCell) {
                    $resolvedRowId = $ids[$expectedCell['row_id']] ?? $expectedCell['row_id'];
                    expect($rowsById[$resolvedRowId] ?? null)->toBe((int) substr($a1, 1));
                    $observed = $cellByCoord[$a1] ?? null;
                    expect($observed)->not->toBeNull();
                    expect($observed->column)->toBe($deltaCol);
                    expect($observed->value)->toBe($expectedCell['value']);
                    expect($observed->formula !== null)->toBe($expectedCell['has_formula']);
                }
            }
        }

        $request = mapSheetScenarioValues($step['request'], $ids);
        $wire = json_decode(json_encode($request, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $actual = $transport === 'php'
            ? $this->app->make(Office::class)->handle($fixture->context, new OfficeRequest(
                1, OfficeOperation::from($wire->operation), $wire->input,
            ))->toArray()
            : $this->postJson('/api/vaulter-ai/v1/operations', $request, ['Authorization' => 'Bearer scenario-test-token'])->json();

        expect($validator->isValidMessage(json_decode(json_encode($actual, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)))
            ->toBeTrue("Wire validation failed at step {$stepNum}");

        $expected = $step['response'];
        if (($expected['result']['data']['kind'] ?? null) === 'sheet_catalog') {
            $expected['result']['data']['tables'][0]['table_title'] = $actual['result']['data']['tables'][0]['table_title'];
        }
        if ($wire->operation === 'office.prepare') {
            $resultObj = json_decode(json_encode($actual['result'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            expect($resultObj->digest)->toBe(ProposalDigest::digest($wire->input, $resultObj));
            $expected['result']['summary'] = $resultObj->summary;
        }
        foreach (['read_token', 'proposal_id', 'digest', 'receipt_id'] as $field) {
            $placeholder = $expected['result'][$field] ?? null;
            if ($placeholder !== null && ! isset($ids[$placeholder])) {
                $ids[$placeholder] = $actual['result'][$field];
            }
        }

        expect($actual)->toEqual(mapSheetScenarioValues($expected, $ids), "Mismatch at step {$stepNum}");
    }

    // Current verification passes and also tracks relocated row formulas if another row is inserted later.
    $receiptId = $ids['receipt-sheet-13'];
    $verifyCurrent = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receiptId, 'mode' => 'current']);
    expect($verifyCurrent->ok)->toBeTrue()
        ->and($verifyCurrent->result->outcome)->toBe('passed');

    $extra = $fixture->humanInsertRowBefore('expenses', 'costs', 'r-travel', ['plan' => 50, 'actual' => 60]);
    $verifyAfterExtraInsert = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receiptId, 'mode' => 'current']);
    expect($verifyAfterExtraInsert->ok)->toBeTrue()
        ->and($verifyAfterExtraInsert->result->outcome)->toBe('passed')
        ->and($verifyAfterExtraInsert->result->marker->position)->toBe($extra['marker']['position'])
        ->and($verifyAfterExtraInsert->result->checks[1]->actual)->toBe(['=C2-B2', '=C5-B5'])
        ->and($verifyAfterExtraInsert->result->checks[2]->actual)->toBe([20, -20]);
})->with(['php', 'http']);

test('missing row_id, foreign row, partial page, unsupported expression, deleted target, and changed input never widen write and duplicate commit is idempotent', function (): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);
    $ownerDb = $this->app->make(VaulterDatabase::class)->connection();

    // 1. Missing or non-existent row_id gives uniform resource_unavailable with no partial rows.
    $missingRow = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-missing-999'],
        ],
    ]);
    expect($missingRow->ok)->toBeFalse()->and($missingRow->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    // 1b. Foreign row from another workbook/table never leaks or widens write.
    $foreignFixture = new SheetCommandFixture($this->app, automatic: true, seed: [
        'sheet_id' => 'expenses',
        'table_id' => 'costs',
        'first_data_row' => 2,
        'rows' => [
            ['row_id' => 'r-foreign-only', 'plan' => 50, 'actual' => 60, 'delta' => null],
        ],
    ]);
    $foreignRowRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-foreign-only'],
        ],
    ]);
    expect($foreignRowRead->ok)->toBeFalse()->and($foreignRowRead->errorCode)->toBe(ErrorCode::ResourceUnavailable);
    expect($foreignFixture->snapshot()->stateVersion)->toBe(1);

    // 2. Partial paginated read token is refused before prepare (even on page 2 with limit=1 when cursor is set).
    $partialPage1 = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) ['kind' => 'sheet_table', 'sheet_id' => 'expenses', 'table_id' => 'costs', 'columns' => ['plan', 'actual']],
        'limit' => 1,
    ]);
    expect($partialPage1->ok)->toBeTrue()->and($partialPage1->result->truncated)->toBeTrue();
    $preparePartial = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $partialPage1->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($preparePartial->ok)->toBeFalse()->and($preparePartial->errorCode)->toBe(ErrorCode::PreconditionFailed);

    $partialPage2 = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) ['kind' => 'sheet_table', 'sheet_id' => 'expenses', 'table_id' => 'costs', 'columns' => ['plan', 'actual']],
        'limit' => 2,
        'cursor' => $partialPage1->result->next_cursor,
    ]);
    expect($partialPage2->ok)->toBeTrue()->and($partialPage2->result->truncated)->toBeFalse();
    $preparePage2 = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $partialPage2->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($preparePage2->ok)->toBeFalse()->and($preparePage2->errorCode)->toBe(ErrorCode::PreconditionFailed);

    // 3. Unsupported formula op or overlapping destination fails cleanly.
    $fullSel = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    expect($fullSel->ok)->toBeTrue();

    $badOp = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $fullSel->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'multiply', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($badOp->ok)->toBeFalse()->and($badOp->errorCode)->toBe(ErrorCode::InvalidInput);

    $overlappingDest = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $fullSel->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'actual',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($overlappingDest->ok)->toBeFalse()->and($overlappingDest->errorCode)->toBe(ErrorCode::InvalidInput);

    $unknownDest = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $fullSel->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'missing_col',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($unknownDest->ok)->toBeFalse()->and($unknownDest->errorCode)->toBe(ErrorCode::PreconditionFailed);

    // 4. Changed input cell (C2 = 120 -> 150) between prepare and commit rejects with precondition_failed without mutating delta.
    $proposalInputChange = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $fullSel->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $fixture->humanSetNumber('expenses', 2, 3, (object) ['number' => 120], 150);
    $rejectedInputChange = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-changed-input',
        'proposal_id' => $proposalInputChange->proposal_id,
        'digest' => $proposalInputChange->digest,
    ]);
    expect($rejectedInputChange->ok)->toBeFalse()->and($rejectedInputChange->errorCode)->toBe(ErrorCode::PreconditionFailed);

    // 4b. Changed destination cell (D2 = null -> 55) between prepare and commit rejects with precondition_failed, keeping 55 and D5=77.
    $destFixture = new SheetCommandFixture($this->app, automatic: true);
    $destRead = $destFixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $destProp = $destFixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $destRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;
    $destFixture->humanSetNumber('expenses', 2, 4, null, 55);
    $rejectedDestChange = $destFixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-changed-dest',
        'proposal_id' => $destProp->proposal_id,
        'digest' => $destProp->digest,
    ]);
    expect($rejectedDestChange->ok)->toBeFalse()->and($rejectedDestChange->errorCode)->toBe(ErrorCode::PreconditionFailed);
    $destCells = [];
    foreach ($destFixture->readTableCells('costs') as $r) {
        foreach ($r->cells as $c) {
            $destCells[$c->a1] = $c;
        }
    }
    expect($destCells['D2']->value)->toBe(55)
        ->and($destCells['D2']->formula)->toBeNull()
        ->and($destCells['D3']->value)->toBeNull()
        ->and($destCells['D4']->value)->toBe(77);

    // 4c. Deleted target row between prepare and commit rejects cleanly with precondition_failed and writes nothing to surviving rows.
    $deletedFixture = new SheetCommandFixture($this->app, automatic: true);
    $delRead = $deletedFixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    $delProp = $deletedFixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $delRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;
    $deletedFixture->removeTableRow('expenses', 'costs', 'r-travel');
    $rejectedDeletedTarget = $deletedFixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-deleted-target',
        'proposal_id' => $delProp->proposal_id,
        'digest' => $delProp->digest,
    ]);
    expect($rejectedDeletedTarget->ok)->toBeFalse()->and($rejectedDeletedTarget->errorCode)->toBe(ErrorCode::PreconditionFailed);
    $delCells = [];
    foreach ($deletedFixture->readTableCells('costs') as $r) {
        foreach ($r->cells as $c) {
            $delCells[$c->a1] = $c;
        }
    }
    expect($delCells['D2']->value)->toBeNull()
        ->and($delCells['D2']->formula)->toBeNull();

    // 5. Re-read, prepare, and commit twice with same command_id & digest => exactly 1 command row, 1 receipt; different digest => idempotency_conflict.
    $reRead = $fixture->call(OfficeOperation::Read, [
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
        'read_token' => $reRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    $countBefore = $ownerDb->table('v_sheets_commands')->count();
    $first = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-idemp-1',
        'proposal_id' => $prop->proposal_id,
        'digest' => $prop->digest,
    ]);
    $second = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-idemp-1',
        'proposal_id' => $prop->proposal_id,
        'digest' => $prop->digest,
    ]);
    expect($first->ok)->toBeTrue()
        ->and($second->toArray())->toBe($first->toArray())
        ->and($ownerDb->table('v_sheets_commands')->count())->toBe($countBefore + 1);

    $conflict = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'cmd-idemp-1',
        'proposal_id' => $prop->proposal_id,
        'digest' => str_repeat('a', 64),
    ]);
    expect($conflict->ok)->toBeFalse()->and($conflict->errorCode)->toBe(ErrorCode::IdempotencyConflict);

    // Unused limit config assertion: target limit exceeding prepare_max_targets fails cleanly with limit_exceeded.
    Config::set('vaulter-ai.limits.prepare_max_targets', 1);
    $tooManyTargets = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $reRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'fixed_members',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($tooManyTargets->ok)->toBeFalse()->and($tooManyTargets->errorCode)->toBe(ErrorCode::LimitExceeded);
});

test('committed spreadsheet workbook reopens cold in headless Chromium with VaulterSpreadsheet', function (): void {
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

    $proposal = $fixture->call(OfficeOperation::Prepare, [
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
    expect($proposal->ok)->toBeTrue();

    $fixture->humanInsertRowBefore('expenses', 'costs', 'r-travel', ['plan' => 40, 'actual' => 55]);

    $commit = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'browser-reopen-1',
        'proposal_id' => $proposal->result->proposal_id,
        'digest' => $proposal->result->digest,
    ]);
    expect($commit->ok)->toBeTrue();

    $codec = $this->app->make(WorkbookCodec::class);
    $committedWorkbook = $codec->snapshot($fixture->snapshot()->content);

    $tmp = sys_get_temp_dir().'/vaulter-ai-sheets-browser-'.bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $sheetsRepo = dirname(__DIR__, 4).'/vaulter-sheets';

    try {
        file_put_contents($tmp.'/workbook.json', json_encode($committedWorkbook, JSON_THROW_ON_ERROR));

        $ownerScript = __DIR__.'/Support/Browser/reopen_check.mjs';
        expect(is_file($ownerScript))->toBeTrue();

        $proc = new Process(
            ['node', $ownerScript, $tmp.'/workbook.json', $sheetsRepo, $fixture->node->id],
            $sheetsRepo,
            ['APP_ENV' => 'testing'],
            timeout: 60,
        );
        $proc->run();
        expect($proc->getExitCode())->toBe(0, $proc->getOutput()."\n".$proc->getErrorOutput());
    } finally {
        foreach (glob($tmp.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmp);
    }
});
