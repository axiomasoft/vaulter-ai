<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
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

test('filterAccessible resolves the caller drives once per batch, not once per resource', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Batch', 'email' => 'batch@example.test']);
    $ids = [];
    for ($d = 1; $d <= 3; $d++) {
        $drive = Vaulter::drive(TestOwner::query()->create(['name' => "Org {$d}"]))->asSystem('seed');
        $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));
        for ($i = 1; $i <= 2; $i++) {
            $ids[] = Documents::in($drive)->create("Doc {$d}.{$i}", 'vaulter/rich-text', [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'attrs' => ['blockId' => 'p1'], 'content' => [['type' => 'text', 'text' => 'x']]]],
            ])->id;
        }
    }

    // Count the pages of the caller's drive listing.
    $driveListings = 0;
    $this->app->make(VaulterDatabase::class)->connection()->listen(static function (QueryExecuted $query) use (&$driveListings): void {
        if (str_contains($query->sql, 'from "v_drives" order by')) {
            $driveListings++;
        }
    });

    // No pinned drive: the directory has to discover the caller's drives.
    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', null, $actor);
    $found = $this->app->make(ResourceDirectory::class)->filterAccessible($ctx, [...$ids, '01900000-0000-7000-8000-000000000000']);

    expect(array_keys($found))->toBe($ids)
        ->and($driveListings)->toBe(1);
});
