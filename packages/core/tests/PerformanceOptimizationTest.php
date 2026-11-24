<?php

declare(strict_types=1);

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

test('performance indexes exist on ai_run_steps(proposal_id), ai_chat_sessions(retention_until), and ai_chat_events(dedupe_key)', function (): void {
    $db = $this->app->make(OfficeDatabase::class);
    $conn = $db->connection();

    if ($db->driver() === 'pgsql') {
        $indexes = collect($conn->select(
            'SELECT indexname FROM pg_indexes WHERE tablename IN (?, ?, ?)',
            [$db->table('ai_run_steps'), $db->table('ai_chat_sessions'), $db->table('ai_chat_events')],
        ))->pluck('indexname')->all();

        expect($indexes)->toContain(
            $db->table('ai_run_steps_proposal'),
            $db->table('ai_chat_sessions_retention'),
            $db->table('ai_chat_events_dedupe'),
        );
    } else {
        $stepsIdx = collect($conn->select("PRAGMA index_list('{$db->table('ai_run_steps')}')"))->pluck('name')->all();
        $sessIdx = collect($conn->select("PRAGMA index_list('{$db->table('ai_chat_sessions')}')"))->pluck('name')->all();
        $evtIdx = collect($conn->select("PRAGMA index_list('{$db->table('ai_chat_events')}')"))->pluck('name')->all();

        expect($stepsIdx)->toContain($db->table('ai_run_steps_proposal'))
            ->and($sessIdx)->toContain($db->table('ai_chat_sessions_retention'))
            ->and($evtIdx)->toContain($db->table('ai_chat_events_dedupe'));
    }
});

test('OfficeWorkspaceService::listProposals executes <= 15 SQL queries for 25 proposals', function (): void {
    $directory = new InMemoryResourceDirectory;
    $entry = new ResourceEntry('res-batch-1', ResourceType::Text, 'Batch Doc', 'documents');
    $directory->put('61', 'default', 'user/1', $entry, ['view', 'edit']);
    $deniedEntry = new ResourceEntry('res-batch-denied', ResourceType::Text, 'Denied Doc', 'documents');
    $directory->put('61', 'default', 'user/other', $deniedEntry, ['view', 'edit']);
    $this->app->instance(ResourceDirectory::class, $directory);

    /** @var Proposals $proposals */
    $proposals = $this->app->make(Proposals::class);
    $ctx = new CallContext('61', 'default', 'user/1', 'documents', '1');
    $otherCtx = new CallContext('61', 'default', 'user/other', 'documents', '1');
    $expiresAt = $this->fixedClock->now()->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');

    for ($i = 1; $i <= 26; $i++) {
        $isDenied = $i === 26;
        $targetRes = $isDenied ? 'res-batch-denied' : 'res-batch-1';
        $targetCtx = $isDenied ? $otherCtx : $ctx;
        $pid = (string) Str::uuid7();
        $digest = hash('sha256', 'batch-'.$i);
        $token = new ReadTokenRecord(
            hash('sha256', 'tok-'.$i),
            '61',
            'default',
            $targetCtx->effectiveSubject,
            $targetRes,
            'text_block',
            new Marker(1, $i),
            '1',
            true,
            $this->fixedClock->now(),
            $this->fixedClock->now()->modify('+1 hour'),
            'app-key-v1',
            ['block_id' => 'p'.$i],
        );
        $plan = new PreparedPlan(
            $pid,
            'text.replace_exact@1',
            $targetRes,
            new Marker(1, $i),
            ApprovalMode::Human,
            'Edit block '.$i,
            ['p'.$i],
            $expiresAt,
            $digest,
            'owner.text',
            ['block_id' => 'p'.$i],
        );
        $proposals->store($targetCtx, $token, $plan, '1');
    }

    /** @var OfficeWorkspaceService $workspace */
    $workspace = $this->app->make(OfficeWorkspaceService::class);

    $queryCount = 0;
    DB::listen(static function (QueryExecuted $query) use (&$queryCount): void {
        $queryCount++;
    });

    $listed = $workspace->listProposals($ctx, limit: 50);

    expect($listed)->toHaveCount(25)
        ->and($queryCount)->toBeLessThanOrEqual(15);
});

test('SearchPages does not persist v_ai_search_pages or v_ai_search_items when next_cursor is null', function (): void {
    $directory = new InMemoryResourceDirectory;
    $directory->put('61', 'default', 'user/1', new ResourceEntry('res-single-1', ResourceType::Text, 'Single Match', 'documents'), ['view']);
    $this->app->instance(ResourceDirectory::class, $directory);

    $office = $this->app->make(Office::class);
    $ctx = new CallContext('61', 'default', 'user/1', 'documents', '1');

    $res = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Single',
        'limit' => 5,
    ]));

    $db = $this->app->make(OfficeDatabase::class);
    expect($res->ok)->toBeTrue()
        ->and($res->result?->next_cursor)->toBeNull()
        ->and($db->query('ai_search_pages')->count())->toBe(0)
        ->and($db->query('ai_search_items')->count())->toBe(0);
});

test('PayloadCipher memoizes derived keys and invalidates on app.key change', function (): void {
    /** @var PayloadCipher $cipher */
    $cipher = $this->app->make(PayloadCipher::class);
    $aad = ['t', 's', '1'];

    $enc1 = $cipher->encrypt(['hello' => 'world'], $aad);
    expect($cipher->decrypt($enc1['key_id'], $enc1['ciphertext'], $aad))->toBe(['hello' => 'world']);

    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    expect(fn () => $cipher->decrypt($enc1['key_id'], $enc1['ciphertext'], $aad))
        ->toThrow(RuntimeException::class);

    $enc2 = $cipher->encrypt(['hello' => 'rotated'], $aad);
    expect($cipher->decrypt($enc2['key_id'], $enc2['ciphertext'], $aad))->toBe(['hello' => 'rotated']);
});

test('ChatTurn batches textDelta tokens and ChatWorkspace::appendOnceForRun avoids decrypting past events', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $directory = new InMemoryResourceDirectory;
    $directory->put('61', 'default', 'user/1', new ResourceEntry('res-chat-opt', ResourceType::Text, 'Chat Doc', 'documents'), ['view', 'edit']);
    $this->app->instance(ResourceDirectory::class, $directory);

    /** @var ChatWorkspace $chat */
    $chat = $this->app->make(ChatWorkspace::class);
    /** @var Runs $runs */
    $runs = $this->app->make(Runs::class);
    $ctx = new CallContext('61', 'default', 'user/1', 'documents', '1');
    $sessionId = $chat->open($ctx);
    $runId = $chat->post($ctx, $sessionId, 'm-opt-1', 'Stream many tokens', ['res-chat-opt']);

    $llm = new class implements LlmPort
    {
        public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable
        {
            for ($i = 0; $i < 20; $i++) {
                yield ['type' => 'textDelta', 'text' => 'tok'.$i.' '];
            }
            yield ['type' => 'finish'];
        }
    };

    /** @var ChatTurn $turn */
    $turn = $this->app->make(ChatTurn::class);
    $turn->execute($ctx, $runId, $llm);

    $events = $chat->events($ctx, $sessionId, 0);
    $deltaEvents = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'assistant.delta'));
    $fullText = implode('', array_map(static fn (array $e): string => (string) ($e['data']['text'] ?? ''), $deltaEvents));

    expect($runs->find($ctx, $runId)?->state)->toBe('completed')
        ->and(count($deltaEvents))->toBeLessThan(20)
        ->and($fullText)->toContain('tok0 ')
        ->and($fullText)->toContain('tok19 ');

    // appendOnceForRun deduplicates without reading/decrypting existing rows.
    $first = $chat->appendOnceForRun($ctx, $sessionId, $runId, 'tool.committed', ['call_id' => 'c1', 'state' => 'committed'], ['res-chat-opt']);
    $second = $chat->appendOnceForRun($ctx, $sessionId, $runId, 'tool.committed', ['call_id' => 'c1', 'state' => 'committed'], ['res-chat-opt']);
    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();

    // Multi-run ChatWorkspace::show() batches runs and run_steps instead of N+1 per run.
    for ($r = 2; $r <= 5; $r++) {
        $extraRun = $chat->post($ctx, $sessionId, 'm-opt-'.$r, 'Extra '.$r, ['res-chat-opt']);
        $turn->execute($ctx, $extraRun, $llm);
    }

    $runSelects = 0;
    $stepSelects = 0;
    DB::listen(static function (QueryExecuted $q) use (&$runSelects, &$stepSelects): void {
        $sql = strtolower($q->sql);
        if (str_contains($sql, 'ai_runs') && str_starts_with(ltrim($sql), 'select') && ! str_contains($sql, 'ai_run_steps')) {
            $runSelects++;
        }
        if (str_contains($sql, 'ai_run_steps') && str_starts_with(ltrim($sql), 'select')) {
            $stepSelects++;
        }
    });

    $shown = $chat->show($ctx, $sessionId);
    expect($shown['runs'])->toHaveCount(5)
        ->and($runSelects)->toBeLessThanOrEqual(1)
        ->and($stepSelects)->toBeLessThanOrEqual(1);
});
