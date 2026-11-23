<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Documents\Projection\DefaultDocumentScopeResolver;
use Vaulter\Ai\Sheets\Host\SheetResourceDirectory;
use Vaulter\Ai\Sheets\Persistence\SheetsTransactionRunner;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Facades\Vaulter;

uses(OfficeTestCase::class);

test('spreadsheet search, read, describe, prepare, commit, status, and verify enforce Drive ACL, tenant, project scope, and profile cleanly over PHP and HTTP', function (): void {
    $fixture = new SheetCommandFixture($this->app, automatic: true);
    $fixture->authorizeHttp();

    // Hidden folder spreadsheet, trashed spreadsheet, and foreign workspace spreadsheet must remain invisible.
    $secretFolder = $fixture->drive->folders()->create('SecretSheets');
    $fixture->drive->access()->restrictInheritance($secretFolder->id, InheritanceRestriction::Empty);
    $payload = json_decode(json_encode($fixture->catalog()['workbook'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $secretSheet = Documents::in($fixture->drive)->create('Бюджет секретный', 'vaulter/spreadsheet', $payload, $secretFolder->id);

    $trashedSheet = Documents::in($fixture->drive)->create('Бюджет удалённый', 'vaulter/spreadsheet', $payload);
    $fixture->drive->actingAs($fixture->actor)->nodes()->trash($trashedSheet->id);

    $outsider = TestActor::query()->create(['name' => 'Outside Sheet User', 'email' => 'outsider-sheet@example.test']);
    $foreignOwner = TestOwner::query()->create(['name' => 'Foreign Sheet Org']);
    $foreignDrive = Vaulter::drive($foreignOwner)->asSystem('seed');
    $foreignDrive->access()->grant($foreignDrive->root()->id, new GrantInput(Subject::ofModel($outsider), RoleRegistry::WRITER));
    $foreignSheet = Documents::in($foreignDrive)->create('Бюджет чужой', 'vaulter/spreadsheet', $payload);

    // Dual-project isolation on another drive where actor IS writer, bound to project-b.
    $dualDrive = Vaulter::drive(TestOwner::query()->create(['name' => 'Project B Org']))->asSystem('seed');
    $dualDrive->access()->grant($dualDrive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::WRITER));
    $this->app->make(DefaultDocumentScopeResolver::class)->bindDriveScope($dualDrive->snapshot()->id, 61, 'project-b');
    $projectBSheet = Documents::in($dualDrive)->create('Бюджет проект Бета', 'vaulter/spreadsheet', $payload);

    // Search for "Бюджет" / "бюджет" finds only fixture node in default project scope.
    foreach (['Бюджет', 'бюджет'] as $q) {
        $search = $fixture->call(OfficeOperation::Search, ['query' => $q, 'resource_type' => 'sheet', 'limit' => 10]);
        expect($search->ok)->toBeTrue();
        expect(array_map(static fn (stdClass $i): string => $i->resource_id, $search->result?->items ?? []))
            ->toBe([$fixture->node->id]);
    }

    // Hidden / trashed / foreign / other-project sheets all return uniform 404 resource_unavailable without leaking titles/cells.
    foreach ([$secretSheet->id, $trashedSheet->id, $foreignSheet->id, $projectBSheet->id] as $hiddenId) {
        $httpResp = $this->postJson('/api/vaulter-ai/v1/operations', [
            'version' => 1,
            'operation' => 'office.read',
            'input' => ['resource_id' => $hiddenId, 'selector' => ['kind' => 'sheet_catalog']],
        ], ['Authorization' => 'Bearer scenario-test-token']);
        $httpResp->assertStatus(404);
        expect($httpResp->json('error.code'))->toBe('resource_unavailable')
            ->and((string) $httpResp->getContent())->not->toContain('expenses');
    }

    // Wrong tenant hides accessible resource identically.
    $wrongTenantCtx = new CallContext('62', 'default', $fixture->context->effectiveSubject, 'sheets', driveId: $fixture->context->driveId, actor: $fixture->actor);
    $wrongTenantRead = $this->app->make(Office::class)->handle($wrongTenantCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $fixture->node->id,
        'selector' => (object) ['kind' => 'sheet_catalog'],
    ]));
    expect($wrongTenantRead->ok)->toBeFalse()->and($wrongTenantRead->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    // Profile lacking sheet.fill_formula@1 refuses describe/prepare even for writer.
    $textProfileCtx = new CallContext('61', 'default', $fixture->context->effectiveSubject, 'documents', driveId: $fixture->context->driveId, actor: $fixture->actor);
    $deniedDescribe = $this->app->make(Office::class)->handle($textProfileCtx, new OfficeRequest(1, OfficeOperation::Describe, (object) [
        'resource_id' => $fixture->node->id,
        'command' => 'sheet.fill_formula@1',
    ]));
    expect($deniedDescribe->ok)->toBeFalse()->and($deniedDescribe->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    // Prepare proposal while still writer, then downgrade to reader-only before commit:
    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) [
            'kind' => 'sheet_table',
            'sheet_id' => 'expenses',
            'table_id' => 'costs',
            'columns' => ['plan', 'actual'],
            'row_ids' => ['r-telecom', 'r-travel'],
        ],
    ]);
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
    ])->result;

    // Reader can read catalog & table, CANNOT describe, prepare, or commit.
    $readerGrant = $fixture->downgradeToReader();
    expect($fixture->call(OfficeOperation::Read, ['selector' => (object) ['kind' => 'sheet_catalog']])->ok)->toBeTrue()
        ->and($fixture->call(OfficeOperation::Describe, ['command' => 'sheet.fill_formula@1'])->errorCode)->toBe(ErrorCode::ResourceUnavailable)
        ->and($fixture->call(OfficeOperation::Commit, [
            'command_id' => 'acl-reader-deny',
            'proposal_id' => $proposal->proposal_id,
            'digest' => $proposal->digest,
        ])->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    // Re-grant writer, commit, then revoke completely: immediate resource_unavailable on commit/status/verify over PHP & HTTP.
    $fixture->drive->access()->revoke($fixture->drive->root()->id, $readerGrant);
    $writerGrant = $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::WRITER))->id;

    $committed = $fixture->call(OfficeOperation::Commit, [
        'command_id' => 'acl-committed-1',
        'proposal_id' => $proposal->proposal_id,
        'digest' => $proposal->digest,
    ]);
    expect($committed->ok)->toBeTrue();
    $receipt = $committed->result->receipt_id;

    $fixture->drive->access()->revoke($fixture->drive->root()->id, $writerGrant);

    foreach ([
        $fixture->call(OfficeOperation::Read, ['selector' => (object) ['kind' => 'sheet_catalog']]),
        $fixture->call(OfficeOperation::Status, ['command_id' => 'acl-committed-1']),
        $fixture->call(OfficeOperation::Verify, ['receipt_id' => $receipt, 'mode' => 'at_commit']),
    ] as $denied) {
        expect($denied->ok)->toBeFalse()
            ->and($denied->errorCode)->toBe(ErrorCode::ResourceUnavailable)
            ->and($denied->nextAction)->toBe(NextAction::Stop);
    }

    $httpStatus = $this->postJson('/api/vaulter-ai/v1/operations', [
        'version' => 1,
        'operation' => 'office.status',
        'input' => ['resource_id' => $fixture->node->id, 'command_id' => 'acl-committed-1'],
    ], ['Authorization' => 'Bearer scenario-test-token']);
    $httpStatus->assertStatus(404);
    expect($httpStatus->json('error.code'))->toBe('resource_unavailable');

    // Text documents still hidden from resource_type=sheet search when Documents is booted.
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::WRITER));
    Config::set('vaulter-ai-documents.default_tenant_id', '61');
    $textDoc = Documents::in($fixture->drive)->create('Бюджет договор текст', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'Бюджет договор']]]],
    ]);

    $sheetOnlySearch = $fixture->call(OfficeOperation::Search, ['query' => 'Бюджет', 'resource_type' => 'sheet', 'limit' => 10]);
    expect($sheetOnlySearch->ok)->toBeTrue()
        ->and(array_map(static fn (stdClass $i): string => $i->resource_id, $sheetOnlySearch->result?->items ?? []))
        ->toBe([$fixture->node->id]);

    $textOnlySearch = $fixture->call(OfficeOperation::Search, ['query' => 'Бюджет', 'resource_type' => 'text', 'limit' => 10]);
    expect($textOnlySearch->ok)->toBeTrue()
        ->and(array_map(static fn (stdClass $i): string => $i->resource_id, $textOnlySearch->result?->items ?? []))
        ->toBe([$textDoc->id]);

    // Standalone SheetResourceDirectory & SheetsTransactionRunner enforce identical ACL and scope isolation.
    $sheetDir = $this->app->make(SheetResourceDirectory::class);
    $sheetDir->bindDriveScope($dualDrive->snapshot()->id, 61, 'project-b');
    $standaloneMatches = $sheetDir->searchCandidates($fixture->context, 'Бюджет', ResourceType::Sheet, 10);
    expect(array_map(static fn ($e): string => $e->resourceId, $standaloneMatches))->toBe([$fixture->node->id])
        ->and($sheetDir->searchCandidates($fixture->context, 'Бюджет', ResourceType::Text, 10))->toBe([])
        ->and($sheetDir->findAccessible($fixture->context, $secretSheet->id))->toBeNull()
        ->and($sheetDir->findAccessible($fixture->context, $projectBSheet->id))->toBeNull()
        ->and($sheetDir->findAccessible($fixture->context, $textDoc->id))->toBeNull()
        ->and(array_keys($sheetDir->filterAccessible($fixture->context, [$fixture->node->id, $secretSheet->id])))->toBe([$fixture->node->id]);

    $txRunner = $this->app->make(SheetsTransactionRunner::class);
    $vaulterConn = $this->app->make(VaulterDatabase::class)->connection();
    $txRan = false;
    $txRunner->run($vaulterConn, static function () use (&$txRan): void {
        $txRan = true;
    }, tenantId: '61');
    expect($txRan)->toBeTrue();
});
