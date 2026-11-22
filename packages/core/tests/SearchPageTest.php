<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitting;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

beforeEach(function (): void {
    $this->bootVaulterDomain = false;
    $this->bootDocumentsAi = false;
});

test('office.search freezes snapshot across inserts, re-checks ACL per page, and isolates scopes', function (): void {
    $directory = new InMemoryResourceDirectory;
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-1', ResourceType::Text, 'Contract 01'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-2', ResourceType::Text, 'Contract 02'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-3', ResourceType::Text, 'Contract 03'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-4', ResourceType::Text, 'Contract 04'));
    // Identical resource ID in another project scope must stay isolated.
    $directory->put('t1', 'proj-2', 'user/1', new ResourceEntry('id-1', ResourceType::Text, 'Other Project Contract'));

    $this->app->instance(ResourceDirectory::class, $directory);
    $office = $this->app->make(Office::class);
    $ctxProj1 = new CallContext('t1', 'proj-1', 'user/1');

    // Page 1 returns first two sorted items and a signed cursor.
    $page1 = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
    ]));
    expect($page1->ok)->toBeTrue();
    expect($page1->result?->truncated)->toBeTrue();
    $cursor = $page1->result?->next_cursor;
    expect($cursor)->toBeString();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $page1->result?->items ?? []))
        ->toBe(['id-1', 'id-2']);

    // Mutate underlying corpus AFTER page 1: add id-0 (alphabetically earlier!) and revoke id-3.
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-0', ResourceType::Text, 'Contract 00'));
    $directory->revoke('t1', 'proj-1', 'user/1', 'id-3');

    // Page 2 MUST NOT admit id-0 and MUST hide revoked id-3, returning only id-4 cleanly.
    $page2 = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
        'cursor' => $cursor,
    ]));
    expect($page2->ok)->toBeTrue();
    expect($page2->result?->truncated)->toBeFalse();
    expect($page2->result?->next_cursor)->toBeNull();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $page2->result?->items ?? []))
        ->toBe(['id-4']);

    // Using cursor from another subject, project, or tenant fails with cursor_expired.
    foreach ([
        new CallContext('t1', 'proj-2', 'user/1'),
        new CallContext('t2', 'proj-1', 'user/1'),
        new CallContext('t1', 'proj-1', 'user/2'),
    ] as $foreignCtx) {
        $rejected = $office->handle($foreignCtx, new OfficeRequest(1, OfficeOperation::Search, (object) [
            'query' => 'Contract',
            'limit' => 2,
            'cursor' => $cursor,
        ]));
        expect($rejected->ok)->toBeFalse();
        expect($rejected->errorCode)->toBe(ErrorCode::CursorExpired);
    }

    // Expired cursor returns cursor_expired.
    $this->fixedClock->advanceSeconds(901);
    $expired = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
        'cursor' => $cursor,
    ]));
    expect($expired->ok)->toBeFalse();
    expect($expired->errorCode)->toBe(ErrorCode::CursorExpired);
    expect($expired->nextAction)->toBe(NextAction::Reread);

    // Request exceeding configured limit returns limit_exceeded.
    $tooLarge = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 51,
    ]));
    expect($tooLarge->ok)->toBeFalse();
    expect($tooLarge->errorCode)->toBe(ErrorCode::LimitExceeded);
    expect($tooLarge->nextAction)->toBe(NextAction::Narrow);
});

test('OfficeDatabase transaction cleans failed commits (#61964) and sets transaction-local PG tenant context under RLS', function (): void {
    $db = $this->app->make(OfficeDatabase::class);
    $connection = $db->connection();
    $connection->setTransactionManager(new DatabaseTransactionsManager);

    $actions = [];
    $fail = true;
    $connection->getEventDispatcher()->listen(TransactionCommitting::class, static function (TransactionCommitting $event) use ($connection, &$fail): void {
        if ($event->connection === $connection && $fail) {
            $fail = false;
            $error = new PDOException('COMMIT rejected');
            $error->errorInfo = ['23001'];
            throw $error;
        }
    });

    expect(static function () use ($db, $connection, &$actions): void {
        $db->transaction(static function () use ($db, $connection, &$actions): void {
            $db->afterCommit(static function () use (&$actions): void {
                $actions[] = 'aborted-office';
            });
            $connection->afterCommit(static function () use (&$actions): void {
                $actions[] = 'aborted-framework';
            });
        }, tenantId: 't1');
    })->toThrow(PDOException::class, 'COMMIT rejected');

    expect($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->transactionLevel())->toBe(0)
        ->and($actions)->toBe([]);

    $db->transaction(static function () use ($db, &$actions): void {
        $db->afterCommit(static function () use (&$actions): void {
            $actions[] = 'committed';
        });
    }, tenantId: 't1');
    expect($actions)->toBe(['committed']);

    if ($db->driver() !== 'pgsql') {
        return;
    }

    $observed = $db->transaction(
        static fn (): ?string => $connection->selectOne("SELECT NULLIF(current_setting('vaulter.tenant_id', true), '') AS t")?->t,
        tenantId: 'tenant-rls-42',
    );
    expect($observed)->toBe('tenant-rls-42');
    $after = $connection->selectOne("SELECT NULLIF(current_setting('vaulter.tenant_id', true), '') AS t")?->t;
    expect($after)->toBeNull();

    // Verify snapshot creation and cursor read under forced restrictive PostgreSQL RLS.
    $directory = new InMemoryResourceDirectory;
    $directory->put('tenant-rls-42', 'proj-1', 'user/1', new ResourceEntry('doc-rls-1', ResourceType::Text, 'RLS Contract 1'));
    $directory->put('tenant-rls-42', 'proj-1', 'user/1', new ResourceEntry('doc-rls-2', ResourceType::Text, 'RLS Contract 2'));
    $this->app->instance(ResourceDirectory::class, $directory);
    $office = $this->app->make(Office::class);

    $searchPages = $connection->getQueryGrammar()->wrapTable($db->table('ai_search_pages'));
    $searchItems = $connection->getQueryGrammar()->wrapTable($db->table('ai_search_items'));

    $connection->statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'vaulter_ai_rls_probe') THEN CREATE ROLE vaulter_ai_rls_probe NOLOGIN NOBYPASSRLS; END IF; END \$\$");
    $connection->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$searchPages}, {$searchItems} TO vaulter_ai_rls_probe");
    $connection->statement("ALTER TABLE {$searchPages} ENABLE ROW LEVEL SECURITY");
    $connection->statement("ALTER TABLE {$searchPages} FORCE ROW LEVEL SECURITY");
    $connection->statement("CREATE POLICY ai_search_pages_allow_all ON {$searchPages} FOR ALL USING (true) WITH CHECK (true)");
    $connection->statement("CREATE POLICY ai_search_pages_tenant_restrictive ON {$searchPages} AS RESTRICTIVE FOR ALL USING (tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), ''))) WITH CHECK (tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')))");
    $connection->statement("ALTER TABLE {$searchItems} ENABLE ROW LEVEL SECURITY");
    $connection->statement("ALTER TABLE {$searchItems} FORCE ROW LEVEL SECURITY");
    $connection->statement("CREATE POLICY ai_search_items_allow_all ON {$searchItems} FOR ALL USING (true) WITH CHECK (true)");
    $connection->statement("CREATE POLICY ai_search_items_tenant_restrictive ON {$searchItems} AS RESTRICTIVE FOR ALL USING (EXISTS (SELECT 1 FROM {$searchPages} p WHERE p.snapshot_id = snapshot_id AND p.tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), '')))) WITH CHECK (EXISTS (SELECT 1 FROM {$searchPages} p WHERE p.snapshot_id = snapshot_id AND p.tenant_id = (SELECT NULLIF(current_setting('vaulter.tenant_id', true), ''))))");

    try {
        $connection->statement('SET ROLE vaulter_ai_rls_probe');
        $ctx = new CallContext('tenant-rls-42', 'proj-1', 'user/1');
        $page1 = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'RLS', 'limit' => 1]));
        expect($page1->ok)->toBeTrue();
        expect($page1->result?->truncated)->toBeTrue();

        $page2 = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) [
            'query' => 'RLS',
            'limit' => 1,
            'cursor' => (string) $page1->result?->next_cursor,
        ]));
        expect($page2->ok)->toBeTrue();
        expect(array_map(static fn (stdClass $i): string => $i->resource_id, $page2->result?->items ?? []))->toBe(['doc-rls-2']);
    } finally {
        $connection->statement('RESET ROLE');
        $connection->statement("DROP POLICY IF EXISTS ai_search_items_tenant_restrictive ON {$searchItems}");
        $connection->statement("DROP POLICY IF EXISTS ai_search_items_allow_all ON {$searchItems}");
        $connection->statement("ALTER TABLE {$searchItems} NO FORCE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$searchItems} DISABLE ROW LEVEL SECURITY");
        $connection->statement("DROP POLICY IF EXISTS ai_search_pages_tenant_restrictive ON {$searchPages}");
        $connection->statement("DROP POLICY IF EXISTS ai_search_pages_allow_all ON {$searchPages}");
        $connection->statement("ALTER TABLE {$searchPages} NO FORCE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$searchPages} DISABLE ROW LEVEL SECURITY");
        $connection->statement("REVOKE ALL ON {$searchPages}, {$searchItems} FROM vaulter_ai_rls_probe");
    }
});
