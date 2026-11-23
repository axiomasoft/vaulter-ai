<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolCall;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\LaravelAi\LaravelAiLlmPort;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;

class ChatStreamingTestCase extends OfficeTestCase
{
    protected bool $bootLaravelAi = true;

    protected bool $bootAgenTools = false;
}

uses(ChatStreamingTestCase::class);

test('LaravelAiLlmPort streams deltas and tools cleanly through ChatTurn with server secret masking and human approval', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $secretKey = 'sk-proj-laravel-chat-secret-92';

    Config::set('vaulter-ai.secret_store.connections', [
        'office_model' => ['driver' => 'openai', 'key' => $secretKey, 'model' => 'gpt-4o-mini'],
    ]);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-chat-1',
    ]);

    $chat = $this->app->make(ChatWorkspace::class);
    $runs = $this->app->make(Runs::class);
    $db = $this->app->make(OfficeDatabase::class);

    $this->app->instance(RunContextResolver::class, new class($fixture->context) implements RunContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(string $tenantId, string $runId): ?CallContext
        {
            return $this->ctx->tenantId === $tenantId ? $this->ctx : null;
        }
    });
    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->ctx->tenantId === $tenantId ? $this->ctx : null;
        }
    });

    OfficeAgent::fake(static function () use ($fixture, $runs, $secretKey): mixed {
        $runId = (string) Context::get('vaulter.ai.run_id');
        $steps = $runs->listSteps($fixture->context, $runId);
        $ops = array_map(static fn ($s): string => $s->operation->value, $steps);
        if (! in_array('office.read', $ops, true)) {
            return new ToolCall('tc-1', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']]);
        }
        if (! in_array('office.prepare', $ops, true)) {
            $readStep = array_values(array_filter($steps, static fn ($s): bool => $s->operation->value === 'office.read'))[0];

            return new ToolCall('tc-2', 'office_prepare', [
                'resource_id' => $fixture->node->id,
                'command' => 'text.replace_exact@1',
                'read_token' => (string) ($readStep->compactResult['result']['read_token'] ?? ''),
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
            ]);
        }

        $commitCall = DB::table('v_ai_tool_calls')->where('run_id', $runId)->where('tool_name', 'office.commit')->first();
        if ($commitCall === null || $commitCall->state !== 'verified') {
            $prepStep = array_values(array_filter($steps, static fn ($s): bool => $s->operation->value === 'office.prepare'))[0];

            return new ToolCall('tc-3', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => (string) ($prepStep->compactResult['result']['proposal_id'] ?? ''),
                'digest' => (string) ($prepStep->compactResult['result']['digest'] ?? ''),
            ]);
        }

        return "Изменение сохранено и проверено. {$secretKey}";
    })->preventStrayPrompts();

    $sessionId = $chat->open($fixture->context);
    $failureInjected = false;
    DB::listen(static function (QueryExecuted $query) use (&$failureInjected): void {
        if (! $failureInjected && str_contains($query->sql, 'insert into "v_ai_chat_events"')
            && in_array('approval.required', $query->bindings, true)) {
            $failureInjected = true;
            throw new RuntimeException('Worker stopped during Prepare settlement.');
        }
    });
    $post = fn (): string => $chat->post(
        $fixture->context,
        $sessionId,
        'lai-msg-1',
        'Замени 15 на 30 в пункте p42',
        [$fixture->node->id],
        ['kind' => 'text_block', 'resource_id' => $fixture->node->id, 'block_id' => 'p42'],
    );
    expect($post)->toThrow(RuntimeException::class, 'Worker stopped during Prepare settlement.');
    expect($failureInjected)->toBeTrue()
        ->and($db->query('ai_proposals')->count())->toBe(0);
    $this->fixedClock->advanceSeconds(ToolCallLedger::LEASE_SECONDS + 1);
    $runId = $post();
    expect($db->query('ai_proposals')->count())->toBe(1);

    // Waiting for human approval after office_prepare + office_commit (blocked by approval_required).
    $run = $runs->find($fixture->context, $runId);
    expect($run?->state)->toBe('awaiting_approval', json_encode($chat->events($fixture->context, $sessionId, 0), JSON_THROW_ON_ERROR));

    $proposalId = (string) $db->query('ai_tool_calls')->where('run_id', $runId)->where('tool_name', 'office.prepare')->value('proposal_id');
    $digest = (string) $db->query('ai_proposals')->where('proposal_id', $proposalId)->value('digest');
    // Read digest from proposals API instead of binary column.
    $storedProposal = $this->app->make(Proposals::class)->find($fixture->context, $fixture->node->id, $proposalId);
    expect($storedProposal)->not->toBeNull();

    // Reviewer approves -> DispatchApprovedCommand settles & wakes ExecuteChatRun -> LaravelAiLlmPort streams final assistant delta!
    $this->app->make(ResolveProposal::class)->resolve(
        new CommandScope($fixture->context, $fixture->node->id, 'lai-cmd-1'),
        $proposalId,
        $storedProposal->plan->digest,
        $fixture->context,
        true,
    );

    $finalRun = $runs->find($fixture->context, $runId);
    $events = $chat->events($fixture->context, $sessionId, 0);
    $shown = $chat->show($fixture->context, $sessionId);
    $eventsJson = json_encode([$events, $shown], JSON_THROW_ON_ERROR);

    expect($finalRun?->state)->toBe('completed')
        ->and(array_column($events, 'type'))->toContain('tool.requested', 'approval.required', 'tool.committed', 'tool.verified', 'assistant.delta', 'run.finished')
        ->and($eventsJson)->not->toContain($secretKey)
        ->and($this->app->make(VaulterDatabase::class)->connection()->table('v_ai_text_commands')->count())->toBe(1);
});

test('LaravelAiLlmPort enforces output limit, retries transient failure before first delta, and masks exceptions', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $secretKey = 'sk-laravel-masked-999';

    Config::set('vaulter-ai.secret_store.connections', [
        'office_model' => ['driver' => 'openai', 'key' => $secretKey, 'model' => 'gpt-4o-mini'],
    ]);
    Config::set('vaulter-ai-laravel-ai.chat.max_retries', 2);
    Config::set('vaulter-ai-laravel-ai.chat.retry_backoff_ms', 1);
    Config::set('vaulter-ai-laravel-ai.chat.max_output_bytes', 1024);

    /** @var LaravelAiLlmPort $port */
    $port = $this->app->make(LlmPort::class);
    $catalog = $this->app->make(ChatTools::class)->catalog($fixture->context, [$fixture->node->id]);
    $profile = $this->app->make(HostPolicy::class)->profile($fixture->context);

    // 1. Retries transient ConnectionException before first delta, then succeeds.
    $attempts = 0;
    OfficeAgent::fake(function () use (&$attempts): string {
        $attempts++;
        if ($attempts === 1) {
            throw new ConnectionException('Temporary socket timeout');
        }

        return 'Recovered answer';
    });

    $events = iterator_to_array($port->stream([['role' => 'user', 'content' => 'Hello']], $catalog, $profile, static fn (): bool => false), false);
    expect($attempts)->toBe(2)
        ->and(implode('', array_map(static fn (array $e): string => $e['text'] ?? '', array_filter($events, static fn (array $e): bool => $e['type'] === 'textDelta'))))
        ->toBe('Recovered answer');

    // 2. Output exceeding max_output_bytes triggers LimitExceeded.
    OfficeAgent::fake(static fn (): string => str_repeat('a', 1500));
    expect(function () use ($port, $catalog, $profile): void {
        iterator_to_array($port->stream([['role' => 'user', 'content' => 'Too long']], $catalog, $profile, static fn (): bool => false), false);
    })->toThrow(OfficeException::class);

    // 3. Non-retryable error masks provider API key in thrown message.
    OfficeAgent::fake(static function () use ($secretKey): never {
        throw new RuntimeException("Bad auth header Bearer {$secretKey}");
    });
    try {
        iterator_to_array($port->stream([['role' => 'user', 'content' => 'Fail']], $catalog, $profile, static fn (): bool => false), false);
        expect(false)->toBeTrue('Expected exception');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::TemporarilyUnavailable)
            ->and($e->getMessage())->not->toContain($secretKey);
    }
});

test('LaravelAi rejects a new tool at the budget boundary and leaves premature HTTP EOF unfinished', function (): void {
    $fixture = new TextCommandFixture($this->app);
    Config::set('vaulter-ai.secret_store.connections.office_model', [
        'driver' => 'openai', 'key' => 'sk-test-stream', 'model' => 'gpt-4o-mini',
    ]);
    Config::set('vaulter-ai-laravel-ai.chat.max_tool_calls', 1);
    $port = $this->app->make(LlmPort::class);
    $profile = $this->app->make(HostPolicy::class)->profile($fixture->context);
    $tools = $this->app->make(ChatTools::class)->catalog($fixture->context, [$fixture->node->id]);

    Http::preventStrayRequests();
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(
        "data: {\"type\":\"response.output_text.delta\",\"delta\":\"partial\"}\n\n",
        200,
        ['Content-Type' => 'text/event-stream'],
    )]);
    $stream = $port->stream([['role' => 'user', 'content' => 'Hello']], $tools, $profile, static fn (): bool => false);
    $stream->rewind();
    expect($stream->current())->toBe(['type' => 'textDelta', 'text' => 'partial']);
    try {
        $stream->next();
        $this->fail('Expected premature EOF to interrupt the stream.');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::TemporarilyUnavailable);
    }

    OfficeAgent::fake([new ToolCall('c-2', 'office_read', ['resource_id' => $fixture->node->id])]);
    try {
        iterator_to_array($port->stream(
            [['role' => 'user', 'content' => 'Hello'], ['role' => 'tool', 'name' => 'office_read', 'call_id' => 'c-1']],
            $tools,
            $profile,
            static fn (): bool => false,
        ));
        $this->fail('Expected limit_exceeded before yielding a new tool.');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::LimitExceeded);
    }
});
