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

    $this->app->instance(AgenToolsSdkLlmPort::class, new class($fixture, $runs) implements AgenToolsSdkLlmPort
    {
        public function __construct(private readonly TextCommandFixture $fixture, private readonly Runs $runs) {}

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

            return ['deltas' => ['Готово: ', 'квитанция проверена.']];
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
    expect($runs->find($fixture->context, $runId)?->state)->toBe('completed')
        ->and(array_column($events, 'type'))->toContain('approval.required', 'tool.committed', 'tool.verified', 'assistant.delta', 'run.finished')
        ->and(json_encode($events, JSON_THROW_ON_ERROR))->not->toContain($secretKey)
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
