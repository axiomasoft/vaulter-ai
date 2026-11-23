<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Documents\Facades\Documents;

uses(OfficeTestCase::class);

test('PHP and HTTP replay sheet_current_rows across scope_changed rejection and successful re-prepare', function (string $transport): void {
    $fixturePath = dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json';
    $doc = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $scenario = array_column($doc['scenarios'], null, 'id')['sheet_current_rows'];
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
            }
        }

        $request = $step['request'];
        array_walk_recursive($request, static function (mixed &$v) use ($ids): void {
            if (is_string($v) && isset($ids[$v])) {
                $v = $ids[$v];
            }
        });
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

        array_walk_recursive($expected, static function (mixed &$v) use ($ids): void {
            if (is_string($v) && isset($ids[$v])) {
                $v = $ids[$v];
            }
        });
        expect($actual)->toEqual($expected, "Mismatch at step {$stepNum}");
    }

    // Old rejected command returns durable scope_changed status, and committed receipt stays intact.
    $oldStatus = $fixture->call(OfficeOperation::Status, ['command_id' => 'sheet-2']);
    expect($oldStatus->ok)->toBeTrue()
        ->and($oldStatus->result->state)->toBe('rejected')
        ->and($oldStatus->result->reason)->toBe('scope_changed');

    $cells = [];
    foreach ($fixture->readTableCells('costs') as $r) {
        foreach ($r->cells as $c) {
            $cells[$c->a1] = $c;
        }
    }
    expect([$cells['D2']->formula, $cells['D2']->value])->toBe(['=C2-B2', 20])
        ->and([$cells['D3']->formula, $cells['D3']->value])->toBe(['=C3-B3', 15])
        ->and([$cells['D4']->formula, $cells['D4']->value])->toBe(['=C4-B4', -20]);
})->with(['php', 'http']);

test('filtered read_token is refused for current_rows with invalid_input, and after_receipt + human approval work cleanly', function (): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);

    // Filtered read_token (row_ids specified) must be refused when preparing current_rows.
    $filteredRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
    expect($filteredRead->ok)->toBeTrue();

    $refused = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $filteredRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'current_rows',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ]);
    expect($refused->ok)->toBeFalse()
        ->and($refused->errorCode)->toBe(ErrorCode::InvalidInput)
        ->and($refused->nextAction)->toBe(NextAction::Stop);

    // Human approval flow for sheet.fill_formula@1: commit before approval -> approval_required, after ResolveProposal -> committed.
    Config::set('vaulter-ai.profiles.sheets.approval', 'human');
    $fullRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
        ],
    ]);
    $proposal = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'sheet.fill_formula@1',
        'read_token' => $fullRead->result->read_token,
        'arguments' => (object) [
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'scope' => 'current_rows',
            'destination_column_id' => 'delta',
            'expression' => (object) ['op' => 'subtract', 'left_column_id' => 'actual', 'right_column_id' => 'plan'],
        ],
    ])->result;

    expect($proposal->approval)->toBe('human');
    $pendingCommit = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'sheet-human-1',
        'proposal_id' => $proposal->proposal_id,
        'digest' => $proposal->digest,
    ]);
    expect($pendingCommit->ok)->toBeFalse()->and($pendingCommit->errorCode)->toBe(ErrorCode::ApprovalRequired);

    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $context) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->context->tenantId === $tenantId ? $this->context : null;
        }
    });

    $scope = new CommandScope($fixture->context, $fixture->node->id, 'sheet-human-1');
    $resolved = $this->app->make(ResolveProposal::class)->resolve(
        $scope,
        $proposal->proposal_id,
        $proposal->digest,
        $fixture->context,
        true,
    );
    expect($resolved)->toBeTrue();

    $status = $fixture->call(OfficeOperation::Status, ['command_id' => 'sheet-human-1']);
    expect($status->ok)->toBeTrue()
        ->and($status->result->state)->toBe('committed');
    $receiptId = $status->result->receipt_id;

    // after_receipt read returns >= receipt marker; restore opens new generation -> generation_changed.
    $afterRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual', 'delta'],
        ],
        'after_receipt' => $receiptId,
    ]);
    expect($afterRead->ok)->toBeTrue()
        ->and($afterRead->result->marker->position)->toBeGreaterThanOrEqual($status->result->marker->position);

    $snap = $fixture->snapshot();
    Documents::in($fixture->drive->actingAs($fixture->actor))
        ->restore($fixture->node->id, $snap->headRevisionId, $snap->stateVersion);

    $afterRestoreRead = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) ['kind' => 'sheet_catalog'],
        'after_receipt' => $receiptId,
    ]);
    expect($afterRestoreRead->ok)->toBeFalse()->and($afterRestoreRead->errorCode)->toBe(ErrorCode::GenerationChanged);
});
