<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Vaulter\Ai\Collaboration\Tests\Support\LiveJournalTestCase;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Documents\Projection\TextProjectionOutboxConsumer;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Collaboration\Persistence\CommandEventType;
use Vaulter\Collaboration\Revocation\ControlConsumer;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Support\Identity;

uses(LiveJournalTestCase::class);

test('delivers delayed CommandCommitted wakeup and redelivery replays via vaulter:relay without duplicating commands or wakes', function (): void {
    $wakeups = [];
    $controlMessages = [];
    Http::fake([
        $this->hubVerifierUrl().'/control' => function (ClientRequest $request) use (&$wakeups, &$controlMessages) {
            $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
            $controlMessages[] = $payload;
            if (($payload['type'] ?? null) === 'wakeup') {
                $wakeups[] = $payload;
            }

            return Http::response(['ok' => true], 200);
        },
    ]);
    Http::preventStrayRequests();

    $fixture = $this->openStrictJournalFixture();
    $proposal = $fixture->prepare('15', '30', 'p42');

    $commit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'cmd-delay-1'));
    expect($commit->ok)->toBeTrue()
        ->and($commit->result->state)->toBe('running');

    $claimed = $this->hubAcquireAndClaimNext((string) Str::uuid());
    $settleResp = $this->hubSettleReplacementCommit($claimed);
    $settleResp->assertOk();
    $receiptId = (string) $settleResp->json('receipt_id');

    // Outbox holds the CommandCommitted event until vaulter:relay delivers it (simulating delayed delivery).
    $committedOutboxRows = DB::table('v_outbox')
        ->where('type', CommandEventType::Committed->value)
        ->orderBy('outbox_seq')
        ->get();
    expect($committedOutboxRows)->toHaveCount(1)
        ->and($wakeups)->toBe([]);

    // First relay pass delivers wakeup once.
    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => ControlConsumer::ID]))->toBe(0);
    expect($wakeups)->toHaveCount(1)
        ->and($wakeups[0]['type'] ?? null)->toBe('wakeup')
        ->and($wakeups[0]['document_id'] ?? null)->toBe($claimed['document_id'])
        ->and($wakeups[0]['generation'] ?? null)->toBe($claimed['generation']);

    // Repeated relay pass + duplicate Hub settle + duplicate office.commit produce zero duplicates.
    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => ControlConsumer::ID]))->toBe(0);
    expect($wakeups)->toHaveCount(1);

    $dupSettle = $this->hubSettleReplacementCommit($claimed);
    $dupSettle->assertOk();
    expect($dupSettle->json('receipt_id'))->toBe($receiptId);

    $dupCommit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'cmd-delay-1'));
    expect($dupCommit->ok)->toBeTrue()
        ->and($dupCommit->result->receipt_id)->toBe($receiptId);

    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => ControlConsumer::ID]))->toBe(0);
    expect($wakeups)->toHaveCount(1)
        ->and(DB::table('v_collaboration_commands')->where('document_id', $claimed['document_id'])->count())->toBe(1)
        ->and(DB::table('v_outbox')->where('type', CommandEventType::Committed->value)->count())->toBe(1)
        ->and(DB::table('v_ai_owner_routes')->where('proposal_id', $proposal->proposal_id)->count())->toBe(1);

    // Explicit outbox replay for the collaboration control consumer re-delivers cleanly without spawning extra commands.
    $seq = (int) $committedOutboxRows->first()->outbox_seq;
    expect(Artisan::call('vaulter:relay:replay', [
        '--consumer' => ControlConsumer::ID,
        '--from' => (string) $seq,
        '--to' => (string) $seq,
    ]))->toBe(0);
    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => ControlConsumer::ID]))->toBe(0);
    expect($wakeups)->toHaveCount(2)
        ->and(DB::table('v_collaboration_commands')->where('document_id', $claimed['document_id'])->count())->toBe(1);
});

test('delayed text projection outbox delivery catches up via vaulter:relay alongside live journal without duplicates', function (): void {
    Config::set('vaulter-ai-documents.projection.auto_index', false);

    $fixture = $this->openStrictJournalFixture();
    $secondDoc = Documents::in($fixture->drive)->create('Дополнительное соглашение', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p-extra'],
                'content' => [['type' => 'text', 'text' => 'Delayed outbox search marker phrase.']],
            ],
        ],
    ]);

    $snap = Documents::in($fixture->drive)->content($secondDoc->id);
    $docKey = Identity::key($snap->documentId) ?? $snap->documentId;
    $reader = $this->app->make(TextProjectionReader::class);

    expect($reader->head(61, 'default', $docKey))->toBeNull()
        ->and(DB::table('v_ai_text_blocks')->where('document_id', $docKey)->count())->toBe(0);

    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => TextProjectionOutboxConsumer::ID]))->toBe(0);
    $searchAfterRelay = $this->app->make(Office::class)->handle(
        $fixture->context,
        new OfficeRequest(1, OfficeOperation::Search, (object) [
            'query' => 'marker phrase',
            'resource_type' => 'text',
            'limit' => 5,
        ]),
    );
    expect($searchAfterRelay->ok)->toBeTrue()
        ->and(array_column($searchAfterRelay->result->items, 'resource_id'))->toContain($secondDoc->id)
        ->and($reader->head(61, 'default', $docKey))->not->toBeNull()
        ->and(DB::table('v_ai_text_blocks')->where('document_id', $docKey)->count())->toBe(1);

    // Running relay again is a clean idempotent no-op for projected blocks.
    expect(Artisan::call('vaulter:relay', ['--once' => true, '--consumer' => TextProjectionOutboxConsumer::ID]))->toBe(0);
    expect(DB::table('v_ai_text_blocks')->where('document_id', $docKey)->count())->toBe(1);
});
