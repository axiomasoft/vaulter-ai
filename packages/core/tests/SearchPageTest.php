<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

beforeEach(function (): void {
    $this->bootVaulterDomain = false;
    $this->bootDocumentsAi = false;
});

test('office.search freezes snapshot across inserts, re-checks ACL per page, and isolates scopes', function (): void {
    $directory = new InMemoryResourceDirectory;
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-1', ResourceType::Text, 'Contract 01'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-2', ResourceType::Text, 'Contract 02'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-3', ResourceType::Text, 'Contract 03'));
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-4', ResourceType::Text, 'Contract 04'));
    // Identical resource ID in another project scope must stay isolated.
    $directory->put('t1', 'proj-2', 'user/1', new ResourceEntry('id-1', ResourceType::Text, 'Other Project Contract'));

    $this->app->instance(ResourceDirectory::class, $directory);
    $office = $this->app->make(Office::class);
    $ctxProj1 = new CallContext('t1', 'proj-1', 'user/1');

    // Page 1 returns first two sorted items and a signed cursor.
    $page1 = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
    ]));
    expect($page1->ok)->toBeTrue();
    expect($page1->result?->truncated)->toBeTrue();
    $cursor = $page1->result?->next_cursor;
    expect($cursor)->toBeString();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $page1->result?->items ?? []))
        ->toBe(['id-1', 'id-2']);

    // Mutate underlying corpus AFTER page 1: add id-0 (alphabetically earlier!) and revoke id-3.
    $directory->put('t1', 'proj-1', 'user/1', new ResourceEntry('id-0', ResourceType::Text, 'Contract 00'));
    $directory->revoke('t1', 'proj-1', 'user/1', 'id-3');

    // Page 2 MUST NOT admit id-0 and MUST hide revoked id-3, returning only id-4 cleanly.
    $page2 = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
        'cursor' => $cursor,
    ]));
    expect($page2->ok)->toBeTrue();
    expect($page2->result?->truncated)->toBeFalse();
    expect($page2->result?->next_cursor)->toBeNull();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $page2->result?->items ?? []))
        ->toBe(['id-4']);

    // Using cursor from another subject, project, or tenant fails with cursor_expired.
    foreach ([
        new CallContext('t1', 'proj-2', 'user/1'),
        new CallContext('t2', 'proj-1', 'user/1'),
        new CallContext('t1', 'proj-1', 'user/2'),
    ] as $foreignCtx) {
        $rejected = $office->handle($foreignCtx, new OfficeRequest(1, OfficeOperation::Search, (object) [
            'query' => 'Contract',
            'limit' => 2,
            'cursor' => $cursor,
        ]));
        expect($rejected->ok)->toBeFalse();
        expect($rejected->errorCode)->toBe(ErrorCode::CursorExpired);
    }

    // Expired cursor returns cursor_expired.
    $this->fixedClock->advanceSeconds(901);
    $expired = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 2,
        'cursor' => $cursor,
    ]));
    expect($expired->ok)->toBeFalse();
    expect($expired->errorCode)->toBe(ErrorCode::CursorExpired);
    expect($expired->nextAction)->toBe(NextAction::Reread);

    // Request exceeding configured limit returns limit_exceeded.
    $tooLarge = $office->handle($ctxProj1, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Contract',
        'limit' => 51,
    ]));
    expect($tooLarge->ok)->toBeFalse();
    expect($tooLarge->errorCode)->toBe(ErrorCode::LimitExceeded);
    expect($tooLarge->nextAction)->toBe(NextAction::Narrow);
});
