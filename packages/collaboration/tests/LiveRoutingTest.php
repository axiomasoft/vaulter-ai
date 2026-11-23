<?php

declare(strict_types=1);

use Vaulter\Ai\Collaboration\LiveTextCommandOwner;
use Vaulter\Ai\Collaboration\Tests\Support\LiveJournalTestCase;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Writing\LiveTextOwner;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Documents\Facades\Documents;

uses(LiveJournalTestCase::class);

test('routes strict live reads and commands through the collaboration adapter', function (): void {
    $fixture = new TextCommandFixture($this->app);
    Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);

    $registry = $this->app->make(OfficeRegistry::class);
    $capability = $this->app->make(CommandWriteCapability::class);
    $entry = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id, 'edit');
    $descriptor = $registry->command(ReplaceExact::COMMAND)->descriptor();
    $chatTools = $this->app->make(ChatTools::class);
    $owner = $registry->ownerFor(ReplaceExact::COMMAND, 'journal');

    expect($owner)->toBeInstanceOf(LiveTextCommandOwner::class)
        ->toBeInstanceOf(LiveTextOwner::class)
        ->toBe($this->app->make(LiveTextOwner::class))
        ->toBe($registry->owner('documents.live_text@1'));

    expect($capability->canWrite($fixture->context, $entry, $descriptor))->toBeTrue()
        ->and(array_keys($chatTools->catalog($fixture->context, [$fixture->node->id])))
        ->toContain('office_describe', 'office_prepare', 'office_commit');
    expect($fixture->call(OfficeOperation::Describe, ['command' => ReplaceExact::COMMAND])->ok)->toBeTrue();

    $read = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]);
    expect($read->ok)->toBeTrue()
        ->and($read->result->marker->position)->toBe(0);

    $proposal = $fixture->prepare();
    $commit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal));
    expect($commit->ok)->toBeTrue()
        ->and($commit->result->state)->toBe('running');

    $status = $fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1']);
    expect($status->ok)->toBeTrue()
        ->and($status->result->state)->toBe('running');
});
