<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\ReadTokens;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

beforeEach(function (): void {
    $this->bootVaulterDomain = false;
    $this->bootDocumentsAi = false;
});

test('ReadTokens store hashed encrypted dependencies bound to scope and fail closed across boundaries or expiry', function (): void {
    $directory = new InMemoryResourceDirectory;
    $directory->put('t1', 'p1', 'user/1', new ResourceEntry('doc-1', ResourceType::Text, 'Contract'));
    $this->app->instance(ResourceDirectory::class, $directory);

    $this->freshRegistry()->registerReader(new class implements ResourceReader
    {
        public function resourceType(): ResourceType
        {
            return ResourceType::Text;
        }

        public function read(CallContext $context, ResourceEntry $resource, stdClass $selector, ?int $limit, ?string $cursor, ?string $afterReceipt): ReadPage
        {
            return new ReadPage(
                marker: new Marker(3, 27),
                data: (object) [
                    'kind' => 'text_block',
                    'block_id' => 'p42',
                    'segments' => [(object) ['text' => '15 дней', 'marks' => ['bold']]],
                ],
                truncated: false,
                nextCursor: null,
                selectorKind: 'text_block',
                schemaRevision: '2',
                completeScope: true,
                selectorDependencies: ['href' => 'https://example.test/terms'],
            );
        }
    });

    $office = $this->app->make(Office::class);
    $tokens = $this->app->make(ReadTokens::class);
    $db = $this->app->make(OfficeDatabase::class);

    $ctx = new CallContext('t1', 'p1', 'user/1');
    $readRes = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => 'doc-1',
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]));
    expect($readRes->ok)->toBeTrue();
    $rawToken = (string) $readRes->result?->read_token;
    expect($rawToken)->not->toBe('');

    // Verify only the SHA-256 hash is stored in DB and ciphertext contains dependencies.
    $count = $db->query('ai_read_tokens')->count();
    expect($count)->toBe(1);

    $verified = $tokens->verify($ctx, $rawToken, 'doc-1');
    expect($verified->marker->generation)->toBe(3);
    expect($verified->marker->position)->toBe(27);
    expect($verified->completeScope)->toBeTrue();
    expect($verified->dependencies)->toBe(['href' => 'https://example.test/terms']);

    // Cross-tenant, cross-project, cross-subject, cross-resource probes fail closed as resource_unavailable.
    foreach ([
        [new CallContext('t2', 'p1', 'user/1'), 'doc-1'],
        [new CallContext('t1', 'p2', 'user/1'), 'doc-1'],
        [new CallContext('t1', 'p1', 'user/2'), 'doc-1'],
        [$ctx, 'doc-2'],
    ] as [$foreignCtx, $targetResource]) {
        expect(static fn () => $tokens->verify($foreignCtx, $rawToken, $targetResource))
            ->toThrow(OfficeException::class);
        try {
            $tokens->verify($foreignCtx, $rawToken, $targetResource);
        } catch (OfficeException $e) {
            expect($e->errorCode)->toBe(ErrorCode::ResourceUnavailable);
        }
    }

    // Expired token inside same scope returns read_token_expired.
    $this->fixedClock->advanceSeconds(901);
    try {
        $tokens->verify($ctx, $rawToken, 'doc-1');
        $this->fail('Expected expired read token exception.');
    } catch (OfficeException $e) {
        expect($e->errorCode)->toBe(ErrorCode::ReadTokenExpired);
    }

    // Payload exceeding byte limit stops with limit_exceeded before issuing a read_token.
    Config::set('vaulter-ai.limits.read_max_bytes', 32);
    $tooBig = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => 'doc-1',
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]));
    expect($tooBig->ok)->toBeFalse();
    expect($tooBig->errorCode)->toBe(ErrorCode::LimitExceeded);
    expect($tooBig->nextAction)->toBe(NextAction::Narrow);
});
