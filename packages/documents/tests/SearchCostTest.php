<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

uses(OfficeTestCase::class);

test('body-text search maps projection hits to nodes without loading any document state', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Searcher', 'email' => 'cost@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Cost']);
    $drive = Vaulter::drive($owner)->asSystem('seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));

    $paragraph = static fn (string $text): array => [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
    ];
    for ($i = 1; $i <= 5; $i++) {
        Documents::in($drive)->create("Plain {$i}", 'vaulter/rich-text', $paragraph('Ordinary words only'));
    }
    $match = Documents::in($drive)->create('Target', 'vaulter/rich-text', $paragraph('Contains the needle term in its body'));

    $stateQueries = [];
    $this->app->make(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use (&$stateQueries): void {
        if (preg_match('/document_(?:revision_)?(?:payloads|sync_states)/', $query->sql) === 1) {
            $stateQueries[] = $query->sql;
        }
    });

    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $drive->snapshot()->id, $actor);
    $res = $this->app->make(Office::class)->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'needle',
        'resource_type' => 'text',
        'limit' => 5,
    ]));

    expect($res->ok)->toBeTrue()
        ->and(array_map(static fn (stdClass $i): string => $i->resource_id, $res->result?->items ?? []))->toBe([$match->id])
        ->and($stateQueries)->toBe([]);
})->group('engine:pgsql');
