<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(OfficeTestCase::class);

/**
 * The bridge is the entry point for messages from outside channels (bots, webhooks), so
 * it validates identifiers itself, needs edit access before a final event starts a run,
 * and mirrors an external run into the chat exactly once per step.
 */
function channelBridgeExpectCode(Closure $call, ErrorCode $code): void
{
    expect($call)->toThrow(static fn (OfficeException $e) => expect($e->errorCode)->toBe($code));
}

function channelBridgeContext(TextCommandFixture $fixture, string $email, string $role): CallContext
{
    $actor = TestActor::query()->create(['name' => 'Channel user', 'email' => $email]);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($actor), $role));

    return new CallContext($fixture->context->tenantId, $fixture->context->projectScope, 'user/'.$actor->getKey(), driveId: $fixture->context->driveId, actor: $actor);
}

test('ingest refuses malformed identifiers before touching the database', function (string $channel, string $session, string $event, string $phase, ?string $resource): void {
    $fixture = new TextCommandFixture($this->app);
    $bridge = $this->app->make(ChannelChatBridge::class);

    channelBridgeExpectCode(
        static fn () => $bridge->ingest($fixture->context, $channel, $session, $event, $phase, $resource ?? $fixture->node->id),
        ErrorCode::InvalidInput,
    );
    expect($this->app->make(OfficeDatabase::class)->query('ai_channel_sessions')->count())->toBe(0);
})->with([
    'upper-case channel' => ['Telegram', 'tg-1', 'evt-1', 'final', null],
    'channel with a slash' => ['tg/bot', 'tg-1', 'evt-1', 'final', null],
    'session with a space' => ['telegram', 'tg 1', 'evt-1', 'final', null],
    'event id too long' => ['telegram', 'tg-1', str_repeat('e', 129), 'final', null],
    'unknown phase' => ['telegram', 'tg-1', 'evt-1', 'draft', null],
    'empty resource' => ['telegram', 'tg-1', 'evt-1', 'final', ''],
    'resource with NUL' => ['telegram', 'tg-1', 'evt-1', 'final', "doc\0"],
    'resource not UTF-8' => ['telegram', 'tg-1', 'evt-1', 'final', "\xC3\x28"],
]);

test('a reader may stream partial events but a final event needs edit access', function (): void {
    Bus::fake([ExecuteChatRun::class]);
    $fixture = new TextCommandFixture($this->app);
    $bridge = $this->app->make(ChannelChatBridge::class);
    $reader = channelBridgeContext($fixture, 'channel-reader@example.test', RoleRegistry::READER);

    $partial = $bridge->ingest($reader, 'telegram', 'tg-reader', 'evt-1', 'partial', $fixture->node->id);
    expect($partial['published'])->toBeFalse();

    channelBridgeExpectCode(
        static fn () => $bridge->ingest($reader, 'telegram', 'tg-reader', 'evt-2', 'final', $fixture->node->id, 'Change it'),
        ErrorCode::ResourceUnavailable,
    );
    channelBridgeExpectCode(
        static fn () => $bridge->ingest($fixture->context, 'telegram', 'tg-1', 'evt-1', 'final', 'missing-resource'),
        ErrorCode::ResourceUnavailable,
    );
    expect($this->app->make(OfficeDatabase::class)->query('ai_runs')->count())->toBe(0);
});

test('an external session resolves to one chat session for its owner only', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $bridge = $this->app->make(ChannelChatBridge::class);
    $writer = channelBridgeContext($fixture, 'channel-writer@example.test', RoleRegistry::WRITER);

    expect($bridge->resolveChatSessionId($fixture->context, 'telegram', 'tg-own'))->toBeNull();
    $opened = $bridge->resolveOrOpenExternalSession($fixture->context, 'telegram', 'tg-own');

    expect($bridge->resolveOrOpenExternalSession($fixture->context, 'telegram', 'tg-own'))->toBe($opened)
        ->and($bridge->resolveChatSessionId($fixture->context, 'telegram', 'tg-own'))->toBe($opened)
        ->and($bridge->ingest($fixture->context, 'telegram', 'tg-own', 'evt-1', 'partial', $fixture->node->id)['chat_session_id'])->toBe($opened)
        // The same external id on another channel is another session.
        ->and($bridge->resolveChatSessionId($fixture->context, 'slack', 'tg-own'))->toBeNull()
        // Another subject neither sees nor takes over the binding.
        ->and($bridge->resolveChatSessionId($writer, 'telegram', 'tg-own'))->toBeNull();

    channelBridgeExpectCode(static fn () => $bridge->resolveOrOpenExternalSession($writer, 'telegram', 'tg-own'), ErrorCode::ResourceUnavailable);
    channelBridgeExpectCode(static fn () => $bridge->resolveOrOpenExternalSession($fixture->context, 'Telegram', 'tg-own'), ErrorCode::InvalidInput);
    channelBridgeExpectCode(static fn () => $bridge->resolveOrOpenExternalSession($fixture->context, 'telegram', ''), ErrorCode::InvalidInput);
});

test('an external run is linked only to its own channel session and its steps are mirrored once', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $bridge = $this->app->make(ChannelChatBridge::class);
    $chat = $this->app->make(ChatWorkspace::class);
    $runs = $this->app->make(Runs::class);
    $resourceId = $fixture->node->id;

    $chatSessionId = $bridge->resolveOrOpenExternalSession($fixture->context, 'laravel-ai', 'tg-ext');
    $run = $runs->createOrFind($fixture->context, 'laravel-ai', 'evt-ext-1', 'rev-1', [
        'prompt' => 'Change 15 to 30.',
        'resource_id' => $resourceId,
        'session_id' => 'tg-ext',
        'chat_session_id' => $chatSessionId,
    ]);

    channelBridgeExpectCode(
        static fn () => $bridge->linkAndSyncExternalRun($fixture->context, 'laravel-ai', 'tg-ext', 'evt-ext-1', 'run-missing', [$resourceId]),
        ErrorCode::ResourceUnavailable,
    );
    channelBridgeExpectCode(
        static fn () => $bridge->linkAndSyncExternalRun($fixture->context, 'agentools', 'tg-ext', 'evt-ext-1', $run->runId, [$resourceId]),
        ErrorCode::IdempotencyConflict,
    );
    channelBridgeExpectCode(
        static fn () => $bridge->linkAndSyncExternalRun($fixture->context, 'laravel-ai', 'tg-other', 'evt-ext-1', $run->runId, [$resourceId]),
        ErrorCode::IdempotencyConflict,
    );

    // Step rows only keep proposal ids that are canonical lower-case UUIDs.
    $proposalId = $fixture->prepare('15', '30', 'p42')->proposal_id;
    $runs->recordStep($fixture->context, $run->runId, OfficeOperation::Prepare, 'finished',
        resourceId: $resourceId, proposalId: $proposalId, compactResult: ['result' => ['approval' => 'human']]);
    $runs->recordStep($fixture->context, $run->runId, OfficeOperation::Commit, 'finished',
        resourceId: $resourceId, proposalId: $proposalId, commandId: 'cmd-ext-1', receiptId: 'rcpt-ext-1');
    $runs->recordStep($fixture->context, $run->runId, OfficeOperation::Verify, 'verified',
        resourceId: $resourceId, proposalId: $proposalId, commandId: 'cmd-ext-1', receiptId: 'rcpt-ext-1',
        compactResult: ['result' => ['outcome' => 'passed']]);

    $linked = $bridge->linkAndSyncExternalRun($fixture->context, 'laravel-ai', 'tg-ext', 'evt-ext-1', $run->runId, [$resourceId]);
    expect($linked)->toBe($chatSessionId);
    $bridge->syncLinkedRunEvents($fixture->context, $run->runId);
    $bridge->linkAndSyncExternalRun($fixture->context, 'laravel-ai', 'tg-ext', 'evt-ext-1', $run->runId, [$resourceId]);

    $events = $chat->events($fixture->context, $chatSessionId, 0);
    $types = array_column($events, 'type');
    $byType = static fn (string $type): array => array_values(array_filter($events, static fn (array $e): bool => $e['type'] === $type));

    expect(array_count_values($types))->toMatchArray([
        'tool.requested' => 3,
        'approval.required' => 1,
        'tool.committed' => 1,
        'tool.verified' => 1,
    ])
        ->and($byType('tool.committed')[0]['data'])->toMatchArray(['proposal_id' => $proposalId, 'command_id' => 'cmd-ext-1', 'receipt_id' => 'rcpt-ext-1'])
        ->and($byType('tool.verified')[0]['data']['verification'])->toBe(['ok' => true, 'result' => ['outcome' => 'passed']])
        ->and($chat->show($fixture->context, $chatSessionId)['messages'][0]['content'])->toBe('Process laravel-ai channel event evt-ext-1 on '.$resourceId.'.');

    // A scope that does not match the run's resource is not mirrored at all.
    $before = count($events);
    $bridge->syncExternalRunEvents($fixture->context, $chatSessionId, $run->runId, ['other-resource']);
    expect($chat->events($fixture->context, $chatSessionId, 0))->toHaveCount($before);
});
