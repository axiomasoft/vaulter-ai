<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;

uses(OfficeTestCase::class);

final readonly class ScriptedChatLlm implements LlmPort
{
    public function __construct(private Closure $script) {}

    public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable
    {
        yield from ($this->script)($messages, $tools, $profile);
    }
}

final class ChatTestContext implements CallContextResolver, RunContextResolver
{
    public int $remainingStreamReads = PHP_INT_MAX;

    public function __construct(private readonly CallContext $context) {}

    public function resolve(Request|string $request, ?string $runId = null): ?CallContext
    {
        if (is_string($request)) {
            return $request === $this->context->tenantId ? $this->context : null;
        }
        if ($request->is('office/chat/sessions/*/events') && $this->remainingStreamReads-- <= 0) {
            return null;
        }

        return $request->bearerToken() === 'chat-token' ? $this->context : null;
    }
}

function chatTestFrames(TestResponse $response): string
{
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    ob_start();
    try {
        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_flush();
        }

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function chatTestDisconnectDatabase(): void
{
    foreach (array_keys(Config::get('database.connections', [])) as $name) {
        DB::disconnect($name);
    }
}

function chatTestDisconnectAfterFirstFrame(TestResponse $response): string
{
    expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    chatTestDisconnectDatabase();
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $streamer = pcntl_fork();
    expect($streamer)->toBeGreaterThanOrEqual(0);
    if ($streamer === 0) {
        fclose($client);
        ob_start(static function (string $bytes) use ($server): string {
            fwrite($server, $bytes);

            return '';
        });
        $response->baseResponse->sendContent();
        exit(0);
    }
    fclose($server);
    stream_set_timeout($client, 5);
    try {
        $frame = '';
        while (($line = fgets($client)) !== false) {
            if ($frame === '' && ! str_starts_with($line, 'id: ')) {
                continue;
            }
            $frame .= $line;
            if ($line === "\n") {
                return $frame;
            }
        }
        throw new RuntimeException('The SSE connection did not deliver its first event.');
    } finally {
        fclose($client);
        if (pcntl_waitpid($streamer, $status, WNOHANG) === 0) {
            posix_kill($streamer, SIGKILL);
            pcntl_waitpid($streamer, $status);
        }
    }
}

test('chat accepts a message and schedules its durable run only after commit', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $fixture->authorizeHttp();
    $this->withToken('scenario-test-token');
    $chat = $this->app->make(ChatWorkspace::class);
    $session = $chat->open($fixture->context);
    $url = '/office/chat/sessions/'.$session;
    $input = ['message_id' => 'client-message', 'content' => 'Read the contract', 'resource_ids' => [$fixture->node->id]];

    $runId = $this->postJson($url.'/messages', $input)->assertAccepted()->json('data.run_id');
    $this->postJson($url.'/messages', $input)->assertAccepted()->assertJsonPath('data.run_id', $runId);
    $this->postJson($url.'/messages', [...$input, 'content' => 'Change the contract'])->assertConflict();
    $this->postJson($url.'/messages', [...$input, 'content' => $input['content'].' '])->assertConflict();
    $this->getJson($url)->assertOk()->assertJsonPath('data.messages.0.content', $input['content']);

    $db = $this->app->make(OfficeDatabase::class);
    expect($db->query('ai_runs')->count())->toBe(1)
        ->and($db->query('ai_chat_messages')->count())->toBe(1)
        ->and($db->decodeBinary($db->query('ai_chat_messages')->value('encrypted_body')))->not->toContain($input['content']);
    Bus::assertDispatched(ExecuteChatRun::class, fn (ExecuteChatRun $job): bool => $job->runId === $runId);

    expect(fn () => $db->transaction(function () use ($fixture, $chat, $session): never {
        $chat->post($fixture->context, $session, 'rolled-back', 'Run must roll back', []);
        throw new RuntimeException('roll back the message and its dispatch');
    }, $fixture->context->tenantId))->toThrow(RuntimeException::class, 'roll back the message and its dispatch');
    expect($db->query('ai_runs')->count())->toBe(1)
        ->and($db->query('ai_chat_messages')->count())->toBe(1);
    Bus::assertNotDispatched(ExecuteChatRun::class, fn (ExecuteChatRun $job): bool => $job->runId !== $runId);
});

test('chat never exposes protected frames on reconnect and keeps the cursor across hidden frames', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $context = new ChatTestContext($fixture->context);
    $this->app->instance(CallContextResolver::class, $context);
    $chat = $this->app->make(ChatWorkspace::class);
    $session = $chat->open($fixture->context);
    $path = '/office/chat/sessions/'.$session;
    $scope = [$fixture->node->id];

    expect(fn () => $chat->append($fixture->context, $session, null, 'invalid', [], $scope))->toThrow(QueryException::class);
    expect($chat->append($fixture->context, $session, null, 'assistant.delta', ['text' => 'redact this sentence'], $scope))->toBe(1);
    expect($chat->append($fixture->context, $session, null, 'assistant.delta', ['text' => 'public'], []))->toBe(2);
    $this->get($path.'/events')->assertUnauthorized();
    $this->withToken('chat-token')->get($path.'/events?cursor=-1')->assertBadRequest();
    $this->get($path.'/events?cursor=3')->assertGone();

    $context->remainingStreamReads = 2;
    $response = $this->get($path.'/events?cursor=1', ['Last-Event-ID' => '0'])->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
        ->assertHeader('X-Accel-Buffering', 'no')
        ->assertHeader('Content-Encoding', 'identity');
    foreach (['private', 'no-cache', 'no-store', 'no-transform'] as $directive) {
        expect($response->headers->hasCacheControlDirective($directive))->toBeTrue();
    }
    expect(chatTestFrames($response))->toContain("id: 1\nevent: assistant.delta", 'redact this sentence');

    $fixture->revoke();
    $context->remainingStreamReads = 2;
    $replay = chatTestFrames($this->get($path.'/events', ['Last-Event-ID' => '0'])->assertOk());
    expect($replay)->toContain("id: 1\nevent: hidden\ndata: {}\n\n", "id: 2\nevent: assistant.delta", 'public')
        ->not->toContain('redact this sentence');

    $context->remainingStreamReads = 2;
    $tail = chatTestFrames($this->get($path.'/events?cursor=1')->assertOk());
    expect($tail)->toContain('id: 2')->not->toContain('id: 1');
    $other = new CallContext($fixture->context->tenantId, $fixture->context->projectScope, $fixture->context->effectiveSubject.'-other');
    $this->app->instance(CallContextResolver::class, new ChatTestContext($other));
    $this->getJson($path)->assertNotFound();
});

test('chat rejects incomplete tool JSON before recording a call or writing to the owner', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $chat = $this->app->make(ChatWorkspace::class);
    $db = $this->app->make(OfficeDatabase::class);
    $session = $chat->open($fixture->context);
    $runId = $chat->post($fixture->context, $session, 'm1', 'Edit this resource', [$fixture->node->id]);
    $llm = new ScriptedChatLlm(fn () => [[
        'type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => '{"resource_id":',
    ]]);
    $this->app->instance(LlmPort::class, $llm);
    $this->app->instance(RunContextResolver::class, new ChatTestContext($fixture->context));
    $this->app->call([new ExecuteChatRun($fixture->context->tenantId, $runId), 'handle']);

    expect($db->query('ai_tool_calls')->count())->toBe(0)
        ->and($db->query('ai_run_steps')->count())->toBe(0)
        ->and($db->query('ai_owner_routes')->count())->toBe(0)
        ->and($chat->events($fixture->context, $session, 0)[0]['data']['error'])->toBe('invalid_input');
});

test('Octane receives the chat cursor frames as a generator with no buffering', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $fixture->authorizeHttp();
    $chat = $this->app->make(ChatWorkspace::class);
    $session = $chat->open($fixture->context);
    $chat->append($fixture->context, $session, null, 'assistant.delta', ['text' => 'streamed'], []);

    $original = $_SERVER['LARAVEL_OCTANE'] ?? null;
    $_SERVER['LARAVEL_OCTANE'] = '1';
    try {
        $response = $this->withToken('scenario-test-token')->get('/office/chat/sessions/'.$session.'/events')
            ->assertOk()->assertHeader('X-Accel-Buffering', 'no');
        $callback = $response->baseResponse->getCallback();
        expect((new ReflectionFunction($callback))->isGenerator())->toBeTrue();
        $frames = $callback();
        expect($frames->current())->toBe(": connected\n\n");
        $frames->next();
        expect($frames->current())->toContain("id: 1\nevent: assistant.delta", 'streamed');
    } finally {
        if ($original === null) {
            unset($_SERVER['LARAVEL_OCTANE']);
        } else {
            $_SERVER['LARAVEL_OCTANE'] = $original;
        }
    }
});

test('chat ledger fences stale workers and distinguishes separate calls with identical arguments', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $chat = $this->app->make(ChatWorkspace::class);
    $db = $this->app->make(OfficeDatabase::class);
    $runs = $this->app->make(Runs::class);
    $ledger = $this->app->make(ToolCallLedger::class);
    $context = $fixture->context;
    $session = $chat->open($context);
    $runId = $chat->post($context, $session, 'm1', 'Read a paragraph', [$fixture->node->id]);
    $input = ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']];
    $register = fn (string $lease, int $ordinal, array $input): array => $db->transaction(
        fn () => $ledger->request($context, $runId, $lease, $ordinal, OfficeOperation::Read, json_encode($input, JSON_THROW_ON_ERROR)),
        $context->tenantId,
    );
    $firstRunLease = $runs->claimLease($context, $runId, 90);
    $first = $register($firstRunLease, 1, $input);
    $replay = $register($firstRunLease, 1, array_reverse($input, true));
    $second = $register($firstRunLease, 2, $input);
    expect($replay)->toBe(['id' => $first['id'], 'created' => false])
        ->and($second['id'])->not->toBe($first['id']);
    expect(fn () => $register($firstRunLease, 1, [...$input, 'selector' => ['kind' => 'text_block', 'block_id' => 'p71']]))
        ->toThrow(OfficeException::class);

    $firstCallLease = $ledger->claim($context, $runId, $firstRunLease, $first['id']);
    expect($firstCallLease)->not->toBeNull()
        ->and($ledger->claim($context, $runId, $firstRunLease, $first['id']))->toBeNull()
        ->and($ledger->claim($context, $runId, $firstRunLease, $second['id']))->not->toBeNull();
    $this->fixedClock->advanceSeconds(91);
    $currentRunLease = $runs->claimLease($context, $runId, 90);
    $currentCallLease = $ledger->claim($context, $runId, $currentRunLease, $first['id']);
    expect($currentCallLease)->not->toBeNull()->not->toBe($firstCallLease);
    $settle = fn (string $lease) => $db->transaction(
        fn () => $ledger->transition($context, $runId, $currentRunLease, $first['id'], $lease, 'verified', null, null),
        $context->tenantId,
    );
    expect(fn () => $settle($firstCallLease))->toThrow(OfficeException::class);
    $settle($currentCallLease);
    expect($db->query('ai_tool_calls')->where('call_id', $first['id'])->value('state'))->toBe('verified');
});

test('office commit rejects a subagent without an approved P/Q before calling its owner', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare();
    $context = $fixture->context;
    $subagent = new CallContext(
        $context->tenantId, $context->projectScope, $context->effectiveSubject, $context->profile,
        $context->policyRevision, $context->driveId, $context->actor, ['office_subagent' => true],
    );
    $this->app->instance(CallContextResolver::class, new ChatTestContext($subagent));
    $this->withToken('chat-token')->postJson('/api/vaulter-ai/v1/operations', [
        'version' => 1, 'operation' => 'office.commit',
        'input' => ['resource_id' => $fixture->node->id] + $fixture->commitInput($proposal),
    ])->assertForbidden()->assertJsonPath('error.code', 'approval_required');
    $this->postJson('/office/proposals/'.$proposal->proposal_id.'/decision', [
        'digest' => $proposal->digest, 'approved' => true,
    ])->assertNotFound();

    expect($this->app->make(OfficeDatabase::class)->query('ai_owner_routes')->count())->toBe(0)
        ->and($this->app->make(VaulterDatabase::class)->connection()->table('v_ai_text_commands')->count())->toBe(0);
});

test('chat resumes a pending tool using the command bound by the human decision', function (): void {
    Bus::fake([ExecuteChatRun::class, DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $context = $fixture->context;
    $proposal = $fixture->prepare();
    $chat = $this->app->make(ChatWorkspace::class);
    $db = $this->app->make(OfficeDatabase::class);
    $turn = $this->app->make(ChatTurn::class);
    $session = $chat->open($context);
    $runId = $chat->post($context, $session, 'approval-turn', 'Request an edit', [$fixture->node->id]);
    $input = json_encode(['resource_id' => $fixture->node->id, 'proposal_id' => $proposal->proposal_id, 'digest' => $proposal->digest], JSON_THROW_ON_ERROR);
    $llm = new ScriptedChatLlm(fn () => [['type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => $input]]);

    expect($turn->execute($context, $runId, $llm))->toBe(30);
    expect($turn->execute($context, $runId, $llm))->toBe(30);
    expect(array_column($chat->events($context, $session, 0), 'type'))->toBe(['tool.requested', 'approval.required'])
        ->and($db->query('ai_tool_calls')->value('state'))->toBe('awaiting_approval')
        ->and($db->query('ai_owner_routes')->count())->toBe(0);

    $won = $this->app->make(ResolveProposal::class)->resolve(
        new CommandScope($context, $fixture->node->id, 'human-approved-command'),
        $proposal->proposal_id, $proposal->digest, $context, true,
    );
    expect($won)->toBeTrue();
    $afterApproval = new ScriptedChatLlm(function (array $messages): iterable {
        expect($messages[1]['response']['result']['command_id'])->toBe('human-approved-command');
        yield ['type' => 'finish'];
    });
    expect($turn->execute($context, $runId, $afterApproval))->toBeNull()
        ->and($db->query('ai_tool_calls')->count())->toBe(1)
        ->and($db->query('ai_tool_calls')->value('command_id'))->toBe('human-approved-command')
        ->and($db->query('ai_tool_calls')->value('state'))->toBe('verified');
});

test('CHAT recovers one receipt and replays a continuous SSE tail after a worker dies following owner commit', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $context = $fixture->context;
    $resolver = new ChatTestContext($context);
    $this->app->instance(CallContextResolver::class, $resolver);
    $this->app->instance(RunContextResolver::class, $resolver);
    $this->withToken('chat-token');

    $proposal = $fixture->prepare();
    $scope = [$fixture->node->id];
    $chat = $this->app->make(ChatWorkspace::class);
    $db = $this->app->make(OfficeDatabase::class);
    $owner = $this->app->make(VaulterDatabase::class)->connection();
    $session = $chat->open($context);
    $path = '/office/chat/sessions/'.$session;
    $message = ['message_id' => 'm1', 'content' => 'Update paragraph 4.2', 'resource_ids' => $scope];
    $runId = $this->postJson($path.'/messages', $message)->assertAccepted()->json('data.run_id');
    $input = json_encode(['resource_id' => $fixture->node->id] + $fixture->commitInput($proposal), JSON_THROW_ON_ERROR);

    $job = new ExecuteChatRun($context->tenantId, $runId);
    $runJob = fn () => $this->app->call([$job, 'handle']);
    $pg = $db->driver() === 'pgsql';
    $armed = true;
    $routesTable = $db->table('ai_owner_routes');
    $db->connection()->beforeExecuting(static function (string $sql) use ($pg, $routesTable, &$armed): void {
        if ($armed && str_starts_with($sql, 'update "'.$routesTable.'" set "receipt_id"')) {
            $armed = false;
            if ($pg) {
                posix_kill(getmypid(), SIGKILL);
            }
            throw new RuntimeException('Lost connection after owner commit');
        }
    });

    $readFirst = function () use ($resolver, $path, $pg): void {
        $resolver->remainingStreamReads = $pg ? PHP_INT_MAX : 2;
        $response = $this->get($path.'/events')->assertOk();
        $first = $pg ? chatTestDisconnectAfterFirstFrame($response) : chatTestFrames($response);
        expect($first)->toContain("id: 1\nevent: assistant.delta", 'draft delta')->not->toContain('id: 2');
    };
    if ($pg) {
        chatTestDisconnectDatabase();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_timeout($parent, 5);
        stream_set_timeout($child, 5);
        $worker = pcntl_fork();
        expect($worker)->toBeGreaterThanOrEqual(0);
        if ($worker === 0) {
            fclose($parent);
            $this->app->instance(LlmPort::class, new ScriptedChatLlm(function (array $_messages, array $tools) use ($child, $input): Generator {
                if (! isset($tools['office_commit'])) {
                    throw new RuntimeException('Commit tool missing from the authorized catalog');
                }
                yield ['type' => 'textDelta', 'text' => 'draft delta'];
                fwrite($child, 'D');
                if (fread($child, 1) !== 'C') {
                    throw new RuntimeException('SSE client missed the delta');
                }
                yield ['type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => $input, 'providerToolCallId' => 'first-provider-id'];
            }));
            $runJob();
            exit(2);
        }
        fclose($child);
        try {
            expect(fread($parent, 1))->toBe('D');
            $readFirst();
            fwrite($parent, 'C');
            pcntl_waitpid($worker, $status);
            expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
        } finally {
            fclose($parent);
            if (pcntl_waitpid($worker, $status, WNOHANG) === 0) {
                posix_kill($worker, SIGKILL);
                pcntl_waitpid($worker, $status);
            }
            $armed = false;
        }
    } else {
        $this->app->instance(LlmPort::class, new ScriptedChatLlm(function () use ($readFirst, $input): Generator {
            yield ['type' => 'textDelta', 'text' => 'draft delta'];
            $readFirst();
            yield ['type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => $input, 'providerToolCallId' => 'first-provider-id'];
        }));
        expect($runJob)->toThrow(RuntimeException::class, 'Lost connection after owner commit');
    }

    $receipt = $owner->table('v_ai_text_commands')->value('receipt_id');
    expect($receipt)->toBeString()
        ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBeNull()
        ->and($db->query('ai_tool_calls')->count())->toBe(1)
        ->and($db->query('ai_tool_calls')->value('state'))->toBe('dispatched');
    $this->postJson($path.'/messages', $message)->assertAccepted()->assertJsonPath('data.run_id', $runId);
    $this->fixedClock->advanceSeconds(100);

    $writes = [];
    $statusReads = [];
    $owner->listen(static function ($query) use (&$writes, &$statusReads): void {
        if (str_contains($query->sql, 'v_ai_text_commands')) {
            if (str_starts_with($query->sql, 'select')) {
                $statusReads[] = $query->sql;
            } elseif (str_starts_with($query->sql, 'insert')) {
                $writes[] = $query->sql;
            }
        }
    });
    $generation = 0;
    $this->app->instance(LlmPort::class, new ScriptedChatLlm(function (array $messages) use (&$generation, $receipt, $input): iterable {
        expect($messages[1]['response']['result']['receipt_id'])->toBe($receipt)
            ->and($messages[1]['response']['verification']['result']['outcome'])->toBe('passed');
        if ($generation++ === 0) {
            yield ['type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => $input,
                'providerToolCallId' => 'new-provider-id', 'replayCallId' => $messages[1]['call_id']];

            return;
        }
        yield ['type' => 'textDelta', 'text' => 'Updated.'];
        yield ['type' => 'finish'];
    }));
    $runJob();
    $runJob();

    $resolver->remainingStreamReads = 2;
    $tail = chatTestFrames($this->get($path.'/events', ['Last-Event-ID' => '1'])->assertOk());
    preg_match_all('/^id: (\d+)$/m', $tail, $ids);
    expect($ids[1])->toBe(['2', '3', '4', '5', '6', '7'])
        ->and($tail)->toContain('tool.requested', 'tool.committed', 'tool.verified', 'assistant.interrupted', 'run.finished', $receipt)
        ->not->toContain('draft delta', 'first-provider-id', 'new-provider-id')
        ->and($db->query('ai_runs')->count())->toBe(1)
        ->and($db->query('ai_runs')->value('state'))->toBe('completed')
        ->and($db->query('ai_tool_calls')->count())->toBe(1)
        ->and($db->query('ai_tool_calls')->value('state'))->toBe('verified')
        ->and($db->query('ai_tool_calls')->value('receipt_id'))->toBe($receipt)
        ->and($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1)
        ->and($statusReads)->not->toBeEmpty()
        ->and($writes)->toBeEmpty();

    $fixture->revoke();
    $resolver->remainingStreamReads = 2;
    $hidden = chatTestFrames($this->get($path.'/events', ['Last-Event-ID' => '0'])->assertOk());
    expect(substr_count($hidden, 'event: hidden'))->toBe(7);
    expect($hidden)->not->toContain($receipt, 'draft delta', 'Updated.');
});

test('PostgreSQL serializes two chat writers on one row while a writer in another session commits', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $context = $fixture->context;
    $chat = $this->app->make(ChatWorkspace::class);
    $db = $this->app->make(OfficeDatabase::class);
    $firstSession = $chat->open($context);
    $secondSession = $chat->open($context);
    chatTestDisconnectDatabase();

    [$firstParent, $firstChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    [$secondParent, $secondChild] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_timeout($firstParent, 10);
    stream_set_timeout($secondParent, 10);
    stream_set_timeout($firstChild, 10);
    $append = fn (string $session, string $text): int => $chat->append($context, $session, null, 'assistant.delta', ['text' => $text], []);
    $first = pcntl_fork();
    expect($first)->toBeGreaterThanOrEqual(0);
    if ($first === 0) {
        fclose($firstParent);
        fclose($secondParent);
        fclose($secondChild);
        $db->transaction(function () use ($db, $append, $firstSession, $firstChild): void {
            $seq = $append($firstSession, 'first');
            fwrite($firstChild, $db->connection()->scalar('select pg_backend_pid()').':'.$seq."\n");
            if (fread($firstChild, 1) !== 'C') {
                throw new RuntimeException('First session was not released');
            }
        }, $context->tenantId);
        fwrite($firstChild, "committed\n");
        posix_kill(getmypid(), SIGKILL);
    }
    fclose($firstChild);
    $children = [$first];
    try {
        [$firstPid, $firstSeq] = explode(':', trim(fgets($firstParent)));
        expect($firstSeq)->toBe('1');
        $second = pcntl_fork();
        expect($second)->toBeGreaterThanOrEqual(0);
        if ($second === 0) {
            fclose($firstParent);
            fclose($secondParent);
            fwrite($secondChild, $db->connection()->scalar('select pg_backend_pid()')."\n");
            fwrite($secondChild, $append($firstSession, 'second')."\n");
            posix_kill(getmypid(), SIGKILL);
        }
        $children[] = $second;
        fclose($secondChild);
        $secondPid = (int) fgets($secondParent);
        $deadline = microtime(true) + 5;
        do {
            $blocked = $db->connection()->scalar('select ? = any(pg_blocking_pids(?))', [(int) $firstPid, $secondPid]);
            if ($blocked) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        expect($blocked)->toBeTrue();
        expect($chat->events($context, $firstSession, 0))->toBeEmpty();
        $db->transaction(function () use ($db, $append, $secondSession): void {
            $db->connection()->statement("SET LOCAL lock_timeout = '1s'");
            expect($append($secondSession, 'independent'))->toBe(1);
        }, $context->tenantId);
        expect($chat->events($context, $secondSession, 0)[0]['data']['text'])->toBe('independent');
        fwrite($firstParent, 'C');
        expect(trim(fgets($firstParent)))->toBe('committed');
        expect(trim(fgets($secondParent)))->toBe('2');
        $events = $chat->events($context, $firstSession, 0);
        expect(array_column($events, 'seq'))->toBe([1, 2])
            ->and(array_column(array_column($events, 'data'), 'text'))->toBe(['first', 'second']);
    } finally {
        fclose($firstParent);
        fclose($secondParent);
        if (is_resource($secondChild)) {
            fclose($secondChild);
        }
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
    }
})->group('engine:pgsql');
