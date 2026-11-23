<?php

declare(strict_types=1);

use Vaulter\Ai\Collaboration\Tests\Support\LiveJournalTestCase;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Documents\Facades\Documents;

class NonStrictLiveJournalTestCase extends LiveJournalTestCase
{
    protected bool $strictProfile = false;
}

uses(NonStrictLiveJournalTestCase::class);

test('requires a strict live journal before offering text writes', function (): void {
    $fixture = new TextCommandFixture($this->app);
    Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);

    $entry = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id, 'edit');
    $descriptor = $this->app->make(OfficeRegistry::class)->command(ReplaceExact::COMMAND)->descriptor();
    $capability = $this->app->make(CommandWriteCapability::class);
    $chatTools = $this->app->make(ChatTools::class);

    expect($capability->canWrite($fixture->context, $entry, $descriptor))->toBeFalse()
        ->and(array_keys($chatTools->catalog($fixture->context, [$fixture->node->id])))
        ->toBe(['office_read', 'office_status', 'office_verify'])
        ->and($fixture->call(OfficeOperation::Describe, ['command' => ReplaceExact::COMMAND])->errorCode)
        ->toBe(ErrorCode::UnsupportedContent);
});
