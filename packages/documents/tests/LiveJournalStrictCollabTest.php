<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
use Vaulter\Ai\Documents\Writing\LiveTextOwner;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Collaboration\Persistence\CommandJournal;
use Vaulter\Collaboration\RoomOwnership;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\State\CollaborativeState;

$collabSrc = dirname(__DIR__, 4).'/vaulter-collaboration/packages/collaboration/src';
$jwtSrc = dirname(__DIR__, 4).'/vaulter-collaboration/vendor/firebase/php-jwt/src';
if (is_dir($collabSrc) && ! class_exists(VaulterCollaborationServiceProvider::class, false)) {
    spl_autoload_register(static function (string $class) use ($collabSrc, $jwtSrc): void {
        if (str_starts_with($class, 'Firebase\\JWT\\') && is_dir($jwtSrc)) {
            $jwtFile = $jwtSrc.'/'.str_replace('\\', '/', substr($class, strlen('Firebase\\JWT\\'))).'.php';
            if (is_file($jwtFile)) {
                require_once $jwtFile;
            }

            return;
        }
        $prefix = 'Vaulter\\Collaboration\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }
        $file = $collabSrc.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

class StrictCollabJournalGateTestCase extends OfficeTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        if (class_exists(VaulterCollaborationServiceProvider::class)) {
            // Register Collaboration BEFORE DocumentsAiServiceProvider freezes/registers LiveTextOwner.
            return [
                ...array_slice($providers, 0, 5),
                VaulterCollaborationServiceProvider::class,
                ...array_slice($providers, 5),
            ];
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', TestActor::class);
        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter-collaboration.strict_profile', false);
        $app['config']->set('vaulter-collaboration.hub.jwt_keys', ['k1' => 'test-collab-jwt-secret-key-at-least-32-bytes']);
        $app['config']->set('vaulter-collaboration.hub.jwt_active_kid', 'k1');
        $app['config']->set('vaulter-collaboration.hub.webhook_secret', 'test-webhook-secret-different-value');
    }
}

uses(StrictCollabJournalGateTestCase::class);

test('live journal document requires 3.5 strict profile before exposing write tools or permitting describe/prepare', function (): void {
    if (! class_exists(VaulterCollaborationServiceProvider::class)) {
        require_once dirname(__DIR__, 3).'/scripts/ci/stubs/vaulter-collaboration.php';
        $fixture = new TextCommandFixture($this->app);
        $entry = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id, 'edit');
        $owner = new LiveTextOwner(new CommandJournal, $this->app->make(TextDocumentAccess::class));
        expect($owner->supportsLiveJournal($fixture->context, $entry, 1, 1))->toBeFalse();

        return;
    }

    $fixture = new TextCommandFixture($this->app);
    Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);

    $registry = $this->app->make(OfficeRegistry::class);
    $capability = $this->app->make(CommandWriteCapability::class);
    $entry = $this->app->make(ResourceDirectory::class)->findAccessible($fixture->context, $fixture->node->id, 'edit');
    $descriptor = $registry->command('text.replace_exact@1')->descriptor();
    $chatTools = $this->app->make(ChatTools::class);

    // Non-strict room: write capability stays false, write tools hidden, describe returns unsupported_content.
    expect($capability->canWrite($fixture->context, $entry, $descriptor))->toBeFalse()
        ->and(array_keys($chatTools->catalog($fixture->context, [$fixture->node->id])))
        ->toBe(['office_read', 'office_status', 'office_verify'])
        ->and($fixture->call(OfficeOperation::Describe, ['command' => 'text.replace_exact@1'])->errorCode)
        ->toBe(ErrorCode::UnsupportedContent);

    // Activate 3.5 strict profile on the room head.
    $this->app->make(VaulterDatabase::class)->transaction(function () use ($fixture): void {
        $locked = $this->app->make(CollaborativeState::class)->lock($fixture->node->id);
        $this->app->make(RoomOwnership::class)->selectProfile($locked, strict: true);
    });

    expect($capability->canWrite($fixture->context, $entry, $descriptor))->toBeTrue()
        ->and(array_keys($chatTools->catalog($fixture->context, [$fixture->node->id])))
        ->toContain('office_describe', 'office_prepare', 'office_commit');
    expect($fixture->call(OfficeOperation::Describe, ['command' => 'text.replace_exact@1'])->ok)->toBeTrue();
});
