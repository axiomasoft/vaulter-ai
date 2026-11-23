<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ProviderSecret;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Persistence\ConfigProviderSecretStore;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(OfficeTestCase::class);

test('chat session open endpoint, bounded editor selection upload/encryption, and revocation redaction work end to end', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $fixture->authorizeHttp();
    Config::set('vaulter-ai.secret_store.connections', [
        'office_model' => ['driver' => 'openai', 'key' => 'test-secret-key-92a', 'model' => 'gpt-4o-mini'],
    ]);

    $foreignNode = Documents::in($fixture->drive)->create('Second Doc', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [['type' => 'paragraph', 'attrs' => ['blockId' => 'p9'], 'content' => [['type' => 'text', 'text' => 'Other']]]],
    ]);

    $opened = $this->withToken('scenario-test-token')
        ->postJson('/office/chat/sessions', ['retention_seconds' => 3600])
        ->assertCreated()
        ->json('data');

    $sessionId = (string) $opened['session_id'];
    expect($opened['last_seq'])->toBe(0)
        ->and($opened['provider'])->toBe([
            'configured' => true,
            'connection' => 'office_model',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'hint' => '****-92a',
        ])
        ->and(json_encode($opened, JSON_THROW_ON_ERROR))->not->toContain('test-secret-key-92a');

    // Selection referencing resource outside resource_ids is rejected cleanly.
    $this->withToken('scenario-test-token')->postJson("/office/chat/sessions/{$sessionId}/messages", [
        'message_id' => 'bad-sel-1',
        'content' => 'Update foreign',
        'resource_ids' => [$fixture->node->id],
        'selection' => ['kind' => 'text_block', 'resource_id' => $foreignNode->id, 'block_id' => 'p9'],
    ])->assertBadRequest()->assertJsonPath('error.code', 'invalid_input');

    // Oversized excerpt > 2048 bytes is rejected cleanly.
    $this->withToken('scenario-test-token')->postJson("/office/chat/sessions/{$sessionId}/messages", [
        'message_id' => 'bad-sel-2',
        'content' => 'Too big excerpt',
        'resource_ids' => [$fixture->node->id],
        'selection' => ['kind' => 'text_block', 'resource_id' => $fixture->node->id, 'block_id' => 'p42', 'excerpt' => str_repeat('z', 2049)],
    ])->assertBadRequest()->assertJsonPath('error.code', 'invalid_input');

    $selection = [
        'kind' => 'text_block',
        'resource_id' => $fixture->node->id,
        'block_id' => 'p42',
        'excerpt' => 'Pay within 15 working days.',
        'start_utf16' => 0,
        'end_utf16' => 27,
    ];
    $runId = $this->withToken('scenario-test-token')->postJson("/office/chat/sessions/{$sessionId}/messages", [
        'message_id' => 'msg-sel-ok',
        'content' => 'Change 15 to 30 in selected paragraph',
        'resource_ids' => [$fixture->node->id],
        'selection' => $selection,
    ])->assertAccepted()->json('data.run_id');

    // Conflicting selection on same message_id returns 409 idempotency_conflict.
    $this->withToken('scenario-test-token')->postJson("/office/chat/sessions/{$sessionId}/messages", [
        'message_id' => 'msg-sel-ok',
        'content' => 'Change 15 to 30 in selected paragraph',
        'resource_ids' => [$fixture->node->id],
        'selection' => ['kind' => 'text_block', 'resource_id' => $fixture->node->id, 'block_id' => 'p71'],
    ])->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');

    $db = $this->app->make(OfficeDatabase::class);
    $rawEncrypted = $db->decodeBinary($db->query('ai_chat_messages')->where('message_id', 'msg-sel-ok')->value('encrypted_body'));
    expect($rawEncrypted)->not->toContain('Pay within 15 working days.')
        ->and($rawEncrypted)->not->toContain('Change 15 to 30');

    // ChatWorkspace::turnInput carries selection cleanly into the model context.
    $turnInput = $this->app->make(ChatWorkspace::class)->turnInput($fixture->context, $sessionId, $runId);
    expect($turnInput['selection'])->toBe($selection);

    $shown = $this->withToken('scenario-test-token')->getJson("/office/chat/sessions/{$sessionId}")->assertOk()->json('data');
    expect($shown['messages'][0]['selection'])->toBe($selection)
        ->and($shown['runs'][0]['run_id'])->toBe($runId);

    // Revoking resource access redacts both message content and selection on show().
    $fixture->revoke();
    $redacted = $this->withToken('scenario-test-token')->getJson("/office/chat/sessions/{$sessionId}")->assertOk()->json('data');
    expect($redacted['messages'][0])->toBe(['message_id' => 'msg-sel-ok', 'hidden' => true])
        ->and(json_encode($redacted, JSON_THROW_ON_ERROR))->not->toContain('Pay within 15 working days.');
});

test('human approval in another session settles owner commit and wakes ExecuteChatRun to verify and finish run', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $reviewer = TestActor::query()->create(['name' => 'Reviewer B', 'email' => 'rev-b@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $reviewerContext = new CallContext($fixture->context->tenantId, $fixture->context->projectScope, 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);

    $proposal = $fixture->prepare();
    $chat = $this->app->make(ChatWorkspace::class);
    $runs = $this->app->make(Runs::class);
    $session = $chat->open($fixture->context);
    $runId = $chat->post($fixture->context, $session, 'tab-close-turn', 'Commit paragraph update', [$fixture->node->id]);

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

    // Step 1: Browser tab A hits human approval fence and disconnects.
    $firstLlm = new class($fixture, $proposal) implements LlmPort
    {
        public function __construct(private readonly TextCommandFixture $fixture, private readonly object $proposal) {}

        public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable
        {
            $input = json_encode([
                'resource_id' => $this->fixture->node->id,
                'proposal_id' => $this->proposal->proposal_id,
                'digest' => $this->proposal->digest,
            ], JSON_THROW_ON_ERROR);
            yield ['type' => 'toolInputComplete', 'tool' => 'office_commit', 'input' => $input];
        }
    };
    $this->app->instance(LlmPort::class, $firstLlm);
    expect($this->app->make(ChatTurn::class)->execute($fixture->context, $runId, $firstLlm))->toBe(30)
        ->and($runs->find($fixture->context, $runId)?->state)->toBe('awaiting_approval');

    // Step 2: Reviewer approves P while tab A is closed; swap LlmPort to resumed post-approval responder.
    $resumedLlm = new class implements LlmPort
    {
        public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable
        {
            yield ['type' => 'textDelta', 'text' => 'Owner committed and verified.'];
            yield ['type' => 'finish'];
        }
    };
    $this->app->instance(LlmPort::class, $resumedLlm);

    $this->app->make(ResolveProposal::class)->resolve(
        new CommandScope($fixture->context, $fixture->node->id, 'cross-tab-cmd-1'),
        $proposal->proposal_id,
        $proposal->digest,
        $reviewerContext,
        true,
    );

    // On sync queue, DispatchApprovedCommand settles owner route AND wakes ExecuteChatRun automatically!
    $events = $chat->events($fixture->context, $session, 0);
    $types = array_column($events, 'type');
    expect($types)->toContain('tool.requested', 'approval.required', 'tool.committed', 'tool.verified', 'assistant.delta', 'run.finished')
        ->and($runs->find($fixture->context, $runId)?->state)->toBe('completed')
        ->and($this->app->make(VaulterDatabase::class)->connection()->table('v_ai_text_commands')->count())->toBe(1);
});

test('ProviderSecretStore never leaks API keys via serialization or exceptions and decrypts encrypted keys', function (): void {
    $encrypter = $this->app->make(Encrypter::class);
    $encryptedKey = $encrypter->encryptString('secret-provider-key-12345');

    Config::set('vaulter-ai.secret_store', [
        'connections' => [
            'office_model' => [
                'driver' => 'openai',
                'encrypted_key' => $encryptedKey,
                'model' => 'gpt-4o-mini',
                'base_url' => 'https://api.openai.com/v1',
            ],
        ],
        'mask_patterns' => [],
    ]);
    $this->app->forgetInstance(ConfigProviderSecretStore::class);
    $this->app->forgetInstance(ProviderSecretStore::class);

    $store = $this->app->make(ProviderSecretStore::class);
    $secret = $store->resolve('office_model');

    expect($secret)->toBeInstanceOf(ProviderSecret::class)
        ->and($secret->apiKey())->toBe('secret-provider-key-12345')
        ->and($secret->__debugInfo()['apiKey'])->toBe('[REDACTED]')
        ->and(array_key_exists('api_key', $secret->jsonSerialize()))->toBeFalse()
        ->and(json_encode($secret, JSON_THROW_ON_ERROR))->not->toContain('secret-provider-key-12345')
        ->and((string) $secret)->not->toContain('secret-provider-key-12345')
        ->and($store->maskText('Failed using bearer secret-provider-key-12345'))->not->toContain('secret-provider-key-12345')
        ->and($store->status('office_model'))->toBe([
            'configured' => true,
            'connection' => 'office_model',
            'driver' => 'openai',
            'model' => 'gpt-4o-mini',
            'hint' => '****2345',
        ]);
});

test('ChannelChatBridge maps external partial/final/duplicate/hijack cleanly onto durable ChatWorkspace sessions', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $bridge = $this->app->make(ChannelChatBridge::class);
    $chat = $this->app->make(ChatWorkspace::class);

    $partial = $bridge->ingest($fixture->context, 'laravel-ai', 'tg-session-1', 'evt-1', 'partial', $fixture->node->id);
    expect($partial['published'])->toBeFalse()
        ->and($partial['run_id'])->toBeNull()
        ->and($partial['chat_session_id'])->not->toBe('')
        ->and($this->app->make(OfficeDatabase::class)->query('ai_runs')->count())->toBe(0);

    $final = $bridge->ingest($fixture->context, 'laravel-ai', 'tg-session-1', 'evt-2', 'final', $fixture->node->id, 'Apply change to p42');
    expect($final['published'])->toBeTrue()
        ->and($final['duplicate'])->toBeFalse()
        ->and($final['chat_session_id'])->toBe($partial['chat_session_id'])
        ->and($final['run_id'])->not->toBeNull();

    $dup = $bridge->ingest($fixture->context, 'laravel-ai', 'tg-session-1', 'evt-2', 'final', $fixture->node->id, 'Apply change to p42');
    expect($dup['duplicate'])->toBeTrue()
        ->and($dup['chat_session_id'])->toBe($final['chat_session_id'])
        ->and($dup['run_id'])->toBe($final['run_id'])
        ->and($this->app->make(OfficeDatabase::class)->query('ai_runs')->count())->toBe(1);

    // Same event_id with altered prompt fails closed with idempotency_conflict.
    expect(fn () => $bridge->ingest($fixture->context, 'laravel-ai', 'tg-session-1', 'evt-2', 'final', $fixture->node->id, 'Tampered prompt'))
        ->toThrow(OfficeException::class);

    // Another subject trying to hijack tg-session-1 is rejected.
    $other = TestActor::query()->create(['name' => 'Intruder', 'email' => 'intruder@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($other), RoleRegistry::WRITER));
    $otherCtx = new CallContext($fixture->context->tenantId, $fixture->context->projectScope, 'user/'.$other->getKey(), driveId: $fixture->context->driveId, actor: $other);
    expect(fn () => $bridge->ingest($otherCtx, 'laravel-ai', 'tg-session-1', 'evt-3', 'final', $fixture->node->id))
        ->toThrow(OfficeException::class);

    $shown = $chat->show($fixture->context, $final['chat_session_id']);
    expect($shown['runs'][0]['run_id'])->toBe($final['run_id'])
        ->and($shown['messages'][0]['content'])->toBe('Apply change to p42');
});
