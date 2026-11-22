<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Ai\Documents\Projection\IndexText;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Projection\TextProjectionOutboxConsumer;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\OutboxRelay;
use Vaulter\Support\Identity;

uses(OfficeTestCase::class);

test('projector handles duplicate delivery, missed updates, after_receipt lag/fallback, restore fencing, and single-snapshot reads', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Writer', 'email' => 'writer@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org 61']);

    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    $docV1 = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p17'],
                'content' => [['type' => 'text', 'text' => '15 дней']],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p18'],
                'content' => [['type' => 'text', 'text' => 'Дополнение']],
            ],
        ],
    ];

    $node = Documents::in($acting)->create('Договор', 'vaulter/rich-text', $docV1);
    $snap1 = Documents::in($acting)->content($node->id);
    $checkpoint1 = Documents::in($acting)->snapshot($node->id, 'v1');

    $reader = $this->app->make(TextProjectionReader::class);
    $indexer = $this->app->make(IndexText::class);
    $commands = $this->app->make(TextCommands::class);
    $office = $this->app->make(Office::class);
    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $actor);

    $head1 = $reader->head(61, 'default', Identity::key($snap1->documentId) ?? $snap1->documentId);
    expect($head1)->not->toBeNull();
    expect($head1['generation'])->toBe($snap1->epoch);
    expect($head1['position'])->toBe($snap1->stateVersion);

    // 1. Duplicate delivery at same (generation, position) does not rewrite build_id.
    $dupResult = $indexer->publishSnapshot(61, 'default', $snap1, $snap1->epoch);
    expect($dupResult)->toBeNull();
    $headAfterDup = $reader->head(61, 'default', Identity::key($snap1->documentId) ?? $snap1->documentId);
    expect($headAfterDup['build_id'])->toBe($head1['build_id']);

    // 2. Disable auto_index to simulate projector lag + missed intermediate event.
    Config::set('vaulter-ai-documents.projection.auto_index', false);
    Config::set('vaulter-ai-documents.read.live_fallback_on_lag', false);

    $docV2 = $docV1;
    $docV2['content'][0]['content'][0]['text'] = '20 дней';
    $snap2 = Documents::in($acting)->update($node->id, $docV2, $snap1->stateVersion);

    $docV3 = $docV1;
    $docV3['content'][0]['content'][0]['text'] = '30 дней';
    $snap3 = Documents::in($acting)->update($node->id, $docV3, $snap2->stateVersion);

    // Seed committed receipt at snap3 position while projection head is STILL lagging at snap1!
    $receiptId = (string) Str::uuid7();
    $commands->recordCommittedReceiptFixture(
        context: $ctx,
        resourceId: $node->id,
        documentId: Identity::key($snap3->documentId) ?? $snap3->documentId,
        commandId: 'cmd-after-receipt-1',
        proposalId: (string) Str::uuid7(),
        receiptId: $receiptId,
        marker: new Marker($snap3->epoch, $snap3->stateVersion),
        dedupeUntil: $this->fixedClock->now()->modify('+7 days'),
    );

    // Fail closed with projection_pending: NEVER return stale "15 дней" for after_receipt!
    $lagged = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
        'after_receipt' => $receiptId,
    ]));
    expect($lagged->ok)->toBeFalse();
    expect($lagged->errorCode)->toBe(ErrorCode::ProjectionPending);
    expect($lagged->retryable)->toBeTrue();
    expect($lagged->nextAction)->toBe(NextAction::RetryRead);

    // With host live_fallback_on_lag enabled, after_receipt returns authorized fresh "30 дней" at >= receipt position.
    Config::set('vaulter-ai-documents.read.live_fallback_on_lag', true);
    $liveFallback = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
        'after_receipt' => $receiptId,
    ]));
    expect($liveFallback->ok)->toBeTrue();
    expect($liveFallback->result?->marker->position)->toBe($snap3->stateVersion);
    expect($liveFallback->result?->data->segments[0]->text)->toBe('30 дней');

    // Catch up missed snap2 event directly to snap3 via RebuildTextIndex artisan command.
    Config::set('vaulter-ai-documents.read.live_fallback_on_lag', false);
    $this->artisan('vaulter-ai:documents:rebuild-text-index', [
        'drive' => $systemDrive->snapshot()->id,
        '--node' => $node->id,
        '--tenant' => '61',
        '--project' => 'default',
    ])->assertSuccessful();

    $afterCatchup = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
        'after_receipt' => $receiptId,
    ]));
    expect($afterCatchup->ok)->toBeTrue();
    expect($afterCatchup->result?->data->segments[0]->text)->toBe('30 дней');
    $head3 = $reader->head(61, 'default', Identity::key($snap3->documentId) ?? $snap3->documentId);
    expect($head3['position'])->toBe($snap3->stateVersion);

    // 3. Single-snapshot JOIN guarantee: inject rogue block row under another build_id; reader must never return it.
    $vDb = $this->app->make(VaulterDatabase::class);
    $dbOptions = $this->app->make(DatabaseOptions::class);
    $officeDb = $this->app->make(OfficeDatabase::class);
    $docKey = Identity::key($snap3->documentId) ?? $snap3->documentId;
    $rogueBuild = (string) Str::uuid7();
    $vDb->connection()->table($dbOptions->table('ai_text_blocks'))->insert([
        'tenant_id' => 61,
        'project_scope' => 'default',
        'document_id' => $docKey,
        'source_generation' => $snap3->epoch,
        'build_id' => $rogueBuild,
        'block_id' => 'p-rogue',
        'parent_id' => null,
        'ordinal' => 99,
        'node_kind' => 'paragraph',
        'heading_path' => '[]',
        'search_text' => '30 дней rogue',
        'formatted_node' => json_encode(['type' => 'paragraph', 'attrs' => ['blockId' => 'p-rogue'], 'content' => [['type' => 'text', 'text' => 'rogue']]], JSON_THROW_ON_ERROR),
        'source_hash' => $officeDb->binaryLiteral(hash('sha256', 'rogue', true)),
    ]);

    $outlineClean = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
    ]));
    expect($outlineClean->ok)->toBeTrue();
    $returnedIds = array_map(static fn (stdClass $b): string => $b->block_id, $outlineClean->result?->data->blocks ?? []);
    expect($returnedIds)->not->toContain('p-rogue');

    // 4. Restore opens new generation: old generation events are ignored & old after_receipt fails with generation_changed.
    Config::set('vaulter-ai-documents.projection.auto_index', true);
    $restored = Documents::in($acting)->restore($node->id, $checkpoint1->id, $snap3->stateVersion);
    expect($restored->epoch)->toBeGreaterThan($snap3->epoch);

    $headRestored = $reader->head(61, 'default', $docKey);
    expect($headRestored['generation'])->toBe($restored->epoch);

    // Attempt delayed publication from old snap3 generation: fenced and ignored!
    $stalePublish = $indexer->publishSnapshot(61, 'default', $snap3, $snap3->epoch);
    expect($stalePublish)->toBeNull();
    expect($reader->head(61, 'default', $docKey)['generation'])->toBe($restored->epoch);

    // after_receipt from pre-restore generation returns generation_changed.
    $oldGenRead = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
        'after_receipt' => $receiptId,
    ]));
    expect($oldGenRead->ok)->toBeFalse();
    expect($oldGenRead->errorCode)->toBe(ErrorCode::GenerationChanged);
    expect($oldGenRead->nextAction)->toBe(NextAction::Reread);

    // Normal read returns restored v1 text ("15 дней") under the new generation!
    $restoredBlockRead = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
    ]));
    expect($restoredBlockRead->ok)->toBeTrue();
    expect($restoredBlockRead->result?->marker->generation)->toBe($restored->epoch);
    expect($restoredBlockRead->result?->data->segments[0]->text)->toBe('15 дней');

    // 5. Outline cursor pagination works within one snapshot and expires when a new build replaces it.
    $page1 = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
        'limit' => 1,
    ]));
    expect($page1->ok)->toBeTrue();
    expect($page1->result?->truncated)->toBeTrue();
    expect($page1->result?->data->blocks)->toHaveCount(1);
    expect($page1->result?->data->blocks[0]->block_id)->toBe('p17');
    $cursor = (string) $page1->result?->next_cursor;
    expect($cursor)->not->toBe('');

    $page2 = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
        'limit' => 1,
        'cursor' => $cursor,
    ]));
    expect($page2->ok)->toBeTrue();
    expect($page2->result?->truncated)->toBeFalse();
    expect($page2->result?->data->blocks)->toHaveCount(1);
    expect($page2->result?->data->blocks[0]->block_id)->toBe('p18');

    // Advance document version => new projection build invalidates prior outline cursor cleanly.
    $docV4 = $docV1;
    $docV4['content'][0]['content'][0]['text'] = '45 дней';
    Documents::in($acting)->update($node->id, $docV4, $restored->stateVersion);

    $staleCursor = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
        'limit' => 1,
        'cursor' => $cursor,
    ]));
    expect($staleCursor->ok)->toBeFalse();
    expect($staleCursor->errorCode)->toBe(ErrorCode::CursorExpired);
    expect($staleCursor->nextAction)->toBe(NextAction::Reread);

    // Unknown after_receipt fails closed as resource_unavailable.
    $unknownReceipt = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p17'],
        'after_receipt' => (string) Str::uuid7(),
    ]));
    expect($unknownReceipt->ok)->toBeFalse();
    expect($unknownReceipt->errorCode)->toBe(ErrorCode::ResourceUnavailable);
});

test('extractor rejects duplicate blockId values with unsupported_content', function (): void {
    $extractor = $this->app->make(RichTextBlockExtractor::class);

    $badDoc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'dup-1'], 'content' => [['type' => 'text', 'text' => 'First']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'dup-1'], 'content' => [['type' => 'text', 'text' => 'Second']]],
        ],
    ];

    try {
        $extractor->extract($badDoc);
        $this->fail('Expected duplicate blockId exception.');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::UnsupportedContent);
        expect($this->app->make(WireErrorMapper::class)->describe($e->errorCode)['status'])->toBe(422);
    }
});

test('forced rebuild keeps same-generation position monotonic and never regresses newer writes', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Rebuilder', 'email' => 'rebuilder@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Rebuild']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    $docOld = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'pos-41']],
        ]],
    ];
    $node = Documents::in($acting)->create('Договор rebuild', 'vaulter/rich-text', $docOld);
    $snapOld = Documents::in($acting)->content($node->id);

    $docNew = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'pos-42']],
        ]],
    ];
    $snapNew = Documents::in($acting)->update($node->id, $docNew, $snapOld->stateVersion);

    $reader = $this->app->make(TextProjectionReader::class);
    $indexer = $this->app->make(IndexText::class);
    $docKey = Identity::key($snapNew->documentId) ?? $snapNew->documentId;

    $headBefore = $reader->head(61, 'default', $docKey);
    expect($headBefore)->not->toBeNull();
    expect($headBefore['position'])->toBe($snapNew->stateVersion);

    // Forced rebuild carrying an older snapshot in the same generation must be ignored.
    $regressed = $indexer->publishSnapshot(61, 'default', $snapOld, null, forceRebuild: true);
    expect($regressed)->toBeNull();

    $headAfter = $reader->head(61, 'default', $docKey);
    expect($headAfter['build_id'])->toBe($headBefore['build_id']);
    expect($headAfter['position'])->toBe($snapNew->stateVersion);

    $block = $reader->readBlock(61, 'default', $docKey, 'p1');
    expect($block['segments'][0]['text'] ?? null)->toBe('pos-42');

    // Forced rebuild at the SAME position succeeds with a new build_id.
    $forcedSame = $indexer->publishSnapshot(61, 'default', $snapNew, null, forceRebuild: true);
    expect($forcedSame)->not->toBeNull();
    expect($forcedSame)->not->toBe($headBefore['build_id']);
});

test('after_receipt read rejects concurrent restore with generation_changed and outline cursor rejects concurrent build publication', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Racer', 'email' => 'racer@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Race']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    $docV1 = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'Базовый текст']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p2'], 'content' => [['type' => 'text', 'text' => 'Второй блок']]],
        ],
    ];
    $node = Documents::in($acting)->create('Договор гонки', 'vaulter/rich-text', $docV1);
    $snap1 = Documents::in($acting)->content($node->id);
    $checkpoint1 = Documents::in($acting)->snapshot($node->id, 'v1');

    $docV2 = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'Обновлённый текст']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p2'], 'content' => [['type' => 'text', 'text' => 'Второй блок']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p-only-gen1'], 'content' => [['type' => 'text', 'text' => 'Только в v2']]],
        ],
    ];
    $snap2 = Documents::in($acting)->update($node->id, $docV2, $snap1->stateVersion);

    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $actor);
    $office = $this->app->make(Office::class);
    $commands = $this->app->make(TextCommands::class);
    $vDb = $this->app->make(VaulterDatabase::class);

    // 1. Outline cursor race: page 1 obtained on current build, then new build publishes between head() and readOutline().
    $page1 = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
        'limit' => 1,
    ]));
    expect($page1->ok)->toBeTrue();
    $cursor = (string) $page1->result?->next_cursor;

    $outlineRaceTriggered = false;
    $vDb->connection()->listen(function (QueryExecuted $query) use (&$outlineRaceTriggered, $acting, $node, $snap2): void {
        if (
            ! $outlineRaceTriggered
            && str_contains($query->sql, 'ai_text_projection_heads')
            && ! str_contains($query->sql, 'ai_text_blocks')
            && str_contains($query->sql, 'projector_version')
        ) {
            $outlineRaceTriggered = true;
            $docV3 = [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'Версия v3']]],
                    ['type' => 'paragraph', 'attrs' => ['blockId' => 'p2'], 'content' => [['type' => 'text', 'text' => 'Второй блок']]],
                    ['type' => 'paragraph', 'attrs' => ['blockId' => 'p-only-gen1'], 'content' => [['type' => 'text', 'text' => 'Только в v3']]],
                ],
            ];
            Documents::in($acting)->update($node->id, $docV3, $snap2->stateVersion);
        }
    });

    $racedCursor = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
        'limit' => 1,
        'cursor' => $cursor,
    ]));
    expect($outlineRaceTriggered)->toBeTrue();
    expect($racedCursor->ok)->toBeFalse();
    expect($racedCursor->errorCode)->toBe(ErrorCode::CursorExpired);
    expect($racedCursor->nextAction)->toBe(NextAction::Reread);

    // 2. after_receipt generation_changed race: restore publishes generation + 1 between preliminary head() and readBlock().
    $snap3 = Documents::in($acting)->content($node->id);
    $receiptId = (string) Str::uuid7();
    $commands->recordCommittedReceiptFixture(
        context: $ctx,
        resourceId: $node->id,
        documentId: Identity::key($snap3->documentId) ?? $snap3->documentId,
        commandId: 'cmd-gen-race',
        proposalId: (string) Str::uuid7(),
        receiptId: $receiptId,
        marker: new Marker($snap3->epoch, $snap3->stateVersion),
        dedupeUntil: $this->fixedClock->now()->modify('+7 days'),
    );

    $restoreRaceTriggered = false;
    $vDb->connection()->listen(function (QueryExecuted $query) use (&$restoreRaceTriggered, $acting, $node, $checkpoint1, $snap3): void {
        if (
            ! $restoreRaceTriggered
            && str_contains($query->sql, 'ai_text_projection_heads')
            && ! str_contains($query->sql, 'ai_text_blocks')
            && str_contains($query->sql, 'projector_version')
        ) {
            $restoreRaceTriggered = true;
            Documents::in($acting)->restore($node->id, $checkpoint1->id, $snap3->stateVersion);
        }
    });

    $racedBlock = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p1'],
        'after_receipt' => $receiptId,
    ]));
    expect($restoreRaceTriggered)->toBeTrue();
    expect($racedBlock->ok)->toBeFalse();
    expect($racedBlock->errorCode)->toBe(ErrorCode::GenerationChanged);
    expect($racedBlock->nextAction)->toBe(NextAction::Reread);
});

test('durable OutboxConsumer replays document creation via vaulter:relay when in-process listeners did not run', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $relay = $this->app->make(OutboxRelay::class);
    $consumers = $relay->consumers();
    expect($consumers)->toHaveKey(TextProjectionOutboxConsumer::ID);
    expect($consumers[TextProjectionOutboxConsumer::ID]->deliveryClass())->toBe(DeliveryClass::Durable);

    $actor = TestActor::query()->create(['name' => 'Outbox Writer', 'email' => 'outbox@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Outbox']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    // Suppress in-process listener dispatch (simulating process exit right after SQL commit).
    Config::set('vaulter-ai-documents.projection.auto_index', false);
    Config::set('vaulter-ai-documents.read.live_fallback_on_lag', false);

    $node = Documents::in($acting)->create('Договор из outbox', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p-outbox'],
            'content' => [['type' => 'text', 'text' => 'Условие из outbox']],
        ]],
    ]);
    $snap = Documents::in($acting)->content($node->id);
    $docKey = Identity::key($snap->documentId) ?? $snap->documentId;

    $reader = $this->app->make(TextProjectionReader::class);
    expect($reader->head(61, 'default', $docKey))->toBeNull();

    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $actor);
    $office = $this->app->make(Office::class);

    $beforeRelay = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p-outbox'],
    ]));
    expect($beforeRelay->ok)->toBeFalse();
    expect($beforeRelay->errorCode)->toBe(ErrorCode::ProjectionPending);

    // Relay delivers the committed outbox event to the durable projector consumer.
    $this->artisan('vaulter:relay', ['--once' => true])->assertSuccessful();

    $head = $reader->head(61, 'default', $docKey);
    expect($head)->not->toBeNull();
    expect($head['generation'])->toBe($snap->epoch);
    expect($head['position'])->toBe($snap->stateVersion);

    $afterRelay = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p-outbox'],
    ]));
    expect($afterRelay->ok)->toBeTrue();
    expect($afterRelay->result?->data->segments[0]->text)->toBe('Условие из outbox');
});
