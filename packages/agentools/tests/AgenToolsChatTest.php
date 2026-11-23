<?php

declare(strict_types=1);

use AgenTools\Contracts\LlmPort as AgenToolsSdkLlmPort;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Vaulter\Ai\AgenTools\AgenToolsLlmPort;
use Vaulter\Ai\AgenTools\OfficeToolDescriptorRegistry;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

class AgenToolsChatTestCase extends OfficeTestCase
{
    protected bool $bootLaravelAi = false;

    protected bool $bootAgenTools = true;
}

uses(AgenToolsChatTestCase::class);

test('AgenToolsLlmPort drives full streaming ChatTurn without laravel/ai and masks secrets cleanly', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $secretKey = 'sk-agentools-only-secret-92';

    Config::set('vaulter-ai.secret_store.connections', [
        'office_model' => ['driver' => 'openai', 'key' => $secretKey, 'model' => 'gpt-4o-mini'],
    ]);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'agentools',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-agentools-chat-1',
    ]);

    expect($this->app->make(LlmPort::class))->toBeInstanceOf(AgenToolsLlmPort::class);

    $runs = $this->app->make(Runs::class);
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

    $this->app->instance(AgenToolsSdkLlmPort::class, new class($fixture, $runs, $secretKey) implements AgenToolsSdkLlmPort
    {
        public function __construct(
            private readonly TextCommandFixture $fixture,
            private readonly Runs $runs,
            private readonly string $secret,
        ) {}

        public function respond(array $input): array
        {
            $runId = (string) Context::get('vaulter.ai.run_id');
            $steps = $this->runs->listSteps($this->fixture->context, $runId);
            $ops = array_map(static fn ($s): string => $s->operation->value, $steps);

            if (! in_array('office.read', $ops, true)) {
                return [
                    'tool_call' => [
                        'tool' => 'office_read',
                        'input' => ['resource_id' => $this->fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']],
                    ],
                ];
            }
            if (! in_array('office.prepare', $ops, true)) {
                $readStep = array_values(array_filter($steps, static fn ($s): bool => $s->operation->value === 'office.read'))[0];

                return [
                    'tool_call' => [
                        'tool' => 'office_prepare',
                        'input' => [
                            'resource_id' => $this->fixture->node->id,
                            'command' => 'text.replace_exact@1',
                            'read_token' => (string) ($readStep->compactResult['result']['read_token'] ?? ''),
                            'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
                        ],
                    ],
                ];
            }

            $commitCall = DB::table('v_ai_tool_calls')->where('run_id', $runId)->where('tool_name', 'office.commit')->first();
            if ($commitCall === null || $commitCall->state !== 'verified') {
                $prepStep = array_values(array_filter($steps, static fn ($s): bool => $s->operation->value === 'office.prepare'))[0];

                return [
                    'tool_call' => [
                        'tool' => 'office_commit',
                        'input' => [
                            'resource_id' => $this->fixture->node->id,
                            'proposal_id' => (string) ($prepStep->compactResult['result']['proposal_id'] ?? ''),
                            'digest' => (string) ($prepStep->compactResult['result']['digest'] ?? ''),
                        ],
                    ],
                ];
            }

            return ['deltas' => ['Готово: '.substr($this->secret, 0, 12), substr($this->secret, 12).' квитанция проверена.']];
        }
    });

    $chat = $this->app->make(ChatWorkspace::class);
    $sessionId = $chat->open($fixture->context);
    $runId = $chat->post($fixture->context, $sessionId, 'at-msg-1', 'Обнови срок оплаты в p42', [$fixture->node->id]);

    $debugEvents = json_encode($this->app->make(ChatWorkspace::class)->events($fixture->context, $sessionId, 0), JSON_THROW_ON_ERROR);
    expect($runs->find($fixture->context, $runId)?->state)->toBe('awaiting_approval', $debugEvents);

    $proposalId = (string) $this->app->make(OfficeDatabase::class)
        ->query('ai_tool_calls')->where('run_id', $runId)->where('tool_name', 'office.prepare')->value('proposal_id');
    $storedProposal = $this->app->make(Proposals::class)->find($fixture->context, $fixture->node->id, $proposalId);

    $this->app->make(ResolveProposal::class)->resolve(
        new CommandScope($fixture->context, $fixture->node->id, 'at-cmd-1'),
        $proposalId,
        $storedProposal->plan->digest,
        $fixture->context,
        true,
    );

    $events = $chat->events($fixture->context, $sessionId, 0);
    $deltas = array_filter($events, static fn (array $e): bool => $e['type'] === 'assistant.delta');
    $transcript = $chat->show($fixture->context, $sessionId);
    $assistant = array_values(array_filter($transcript['messages'], static fn (array $m): bool => ($m['role'] ?? null) === 'assistant'));
    expect($runs->find($fixture->context, $runId)?->state)->toBe('completed')
        ->and(array_column($events, 'type'))->toContain('approval.required', 'tool.committed', 'tool.verified', 'assistant.delta', 'run.finished')
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain($secretKey)
        ->and(implode('', array_column(array_column($deltas, 'data'), 'text')))->toBe('Готово: [REDACTED] квитанция проверена.')
        ->and(array_column($assistant, 'content'))->toBe(['Готово: [REDACTED] квитанция проверена.'])
        ->and($this->app->make(VaulterDatabase::class)->connection()->table('v_ai_text_commands')->count())->toBe(1);
});

test('AgenToolsLlmPort streams Responses SSE HTTP fallback with retry and hides write tools for read-only callers', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $secretKey = 'sk-agentools-http-secret-77';

    Config::set('vaulter-ai.secret_store.connections', [
        'office_model' => [
            'driver' => 'openai',
            'key' => $secretKey,
            'model' => 'gpt-4o-mini',
            'base_url' => 'https://llm.local.test/v1',
        ],
    ]);
    Config::set('vaulter-ai-agentools.chat.max_retries', 1);
    Config::set('vaulter-ai-agentools.chat.retry_backoff_ms', 1);

    // HTTP SSE stream: first attempt 503 transient failure, second succeeds with deltas.
    Http::fake([
        'https://llm.local.test/v1/responses' => Http::sequence()
            ->push(['error' => 'overloaded'], 503)
            ->push(
                'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'Привет '])."\n\n".
                'data: '.json_encode(['type' => 'response.output_text.delta', 'delta' => 'из SSE'])."\n\n".
                "data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
    ]);

    /** @var AgenToolsLlmPort $port */
    $port = $this->app->make(LlmPort::class);
    $catalog = $this->app->make(ChatTools::class)->catalog($fixture->context, [$fixture->node->id]);
    $profile = $this->app->make(HostPolicy::class)->profile($fixture->context);

    $streamed = iterator_to_array($port->stream([['role' => 'user', 'content' => 'Скажи привет']], $catalog, $profile, static fn (): bool => false), false);
    $deltas = implode('', array_map(static fn (array $e): string => $e['text'] ?? '', array_filter($streamed, static fn (array $e): bool => $e['type'] === 'textDelta')));
    expect($deltas)->toBe('Привет из SSE')
        ->and($streamed[array_key_last($streamed)]['type'])->toBe('finish');

    // Reader downgrade removes write tools from OfficeToolDescriptorRegistry::forContext.
    $fixture->revoke();
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::READER));
    $entry = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id, 'view');
    $readerRegistry = $this->app->make(OfficeToolDescriptorRegistry::class)->forContext($fixture->context, $entry);
    expect($readerRegistry->descriptor('office_read'))->not->toBeNull()
        ->and($readerRegistry->descriptor('office_describe'))->toBeNull()
        ->and($readerRegistry->descriptor('office_prepare'))->toBeNull()
        ->and($readerRegistry->descriptor('office_commit'))->toBeNull();
});

test('AgenTools requires a terminal HTTP response and rejects tools after the adapter budget is spent', function (): void {
    $fixture = new TextCommandFixture($this->app);
    Config::set('vaulter-ai.secret_store.connections.office_model', [
        'driver' => 'openai', 'key' => 'sk-test-stream', 'base_url' => 'https://llm.local.test/v1',
    ]);
    Config::set('vaulter-ai-agentools.chat.max_tool_calls', 1);
    Http::fake(['https://llm.local.test/v1/responses' => Http::sequence()
        ->push("data: {\"type\":\"response.output_text.delta\",\"delta\":\"partial\"}\n\n")
        ->push("data: {\"type\":\"response.output_item.added\",\"item\":{\"type\":\"function_call\",\"id\":\"fc-2\",\"name\":\"office_read\"}}\n\n".
            "data: {\"type\":\"response.function_call_arguments.done\",\"item_id\":\"fc-2\",\"arguments\":\"{}\"}\n\n")]);

    $port = $this->app->make(LlmPort::class);
    $profile = $this->app->make(HostPolicy::class)->profile($fixture->context);
    $tools = $this->app->make(ChatTools::class)->catalog($fixture->context, [$fixture->node->id]);
    $cancelled = static fn (): bool => false;
    $stream = $port->stream([['role' => 'user', 'content' => 'Hello']], $tools, $profile, $cancelled);
    $stream->rewind();
    expect($stream->current())->toBe(['type' => 'textDelta', 'text' => 'partial']);
    try {
        $stream->next();
        $this->fail('Expected premature EOF to interrupt the stream.');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::TemporarilyUnavailable);
    }

    $history = [['role' => 'tool', 'name' => 'office_read', 'call_id' => 'c-1']];
    $assertBudget = function () use ($port, $history, $tools, $profile, $cancelled): void {
        try {
            iterator_to_array($port->stream($history, $tools, $profile, $cancelled));
            $this->fail('Expected limit_exceeded before yielding a new tool.');
        } catch (OfficeException $e) {
            expect($e->errorCode)->toBe(ErrorCode::LimitExceeded);
        }
    };
    $assertBudget();
    $this->app->instance(AgenToolsSdkLlmPort::class, new class implements AgenToolsSdkLlmPort
    {
        public function respond(array $input): array
        {
            return ['tool_call' => ['tool' => 'office_read', 'input' => []]];
        }
    });
    $assertBudget();
});
