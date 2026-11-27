<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Ai\AgenTools\AgenToolsServiceProvider;
use Vaulter\Ai\AgenTools\OfficeToolDescriptorRegistry;
use Vaulter\Ai\Collaboration\CollaborationAiServiceProvider;
use Vaulter\Ai\Collaboration\Tests\Support\LiveJournalTestCase;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Laravel\VaulterPluginRegistrar;

final class LiveJournalGateDriver implements CollaborationProtocolDriver
{
    public function protocol(): string
    {
        return 'yjs';
    }

    public function version(): string
    {
        return 'yjs/2';
    }

    public function supportsState(string $documentType, string $stateFormat, int $schemaVersion, bool $hasCheckpoint, CollaborationMode $mode): bool
    {
        return $documentType === 'vaulter/rich-text' && $stateFormat === 'yjs-update-v1';
    }

    public function issueSession(CollaborationGrant $grant): CollaborationSession
    {
        return new CollaborationSession(
            'yjs',
            $this->version(),
            null,
            'journal-gate-token',
            $grant->notAfter,
            $grant->mode,
            $grant->nodeId,
            $grant->generation,
            $grant->schemaVersion,
        );
    }
}

final class LiveJournalDriverPlugin implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.test-live-journal-gate', version: '1.0.0', requires: ['vaulter.documents']);
    }

    public function register(PluginRegistrar $registrar): void {}

    public function slotContributions(): array
    {
        return [[
            'slot' => CollaborationProtocolDriver::class,
            'id' => 'live-journal-gate.yjs',
            'implementation' => LiveJournalGateDriver::class,
            'key' => 'yjs',
        ]];
    }
}

final class LiveJournalDriverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        VaulterPluginRegistrar::register($this->app, LiveJournalDriverPlugin::class);
    }
}

class LiveJournalWithoutCollabTestCase extends OfficeTestCase
{
    protected function suiteDatabaseConnection(): array
    {
        return LiveJournalTestCase::resolveSuiteDatabaseConnection();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            CollaborationAiServiceProvider::class,
            LiveJournalDriverServiceProvider::class,
            AiServiceProvider::class,
            LaravelAiServiceProvider::class,
            AgenToolsServiceProvider::class,
        ];
    }
}

uses(LiveJournalWithoutCollabTestCase::class)->in(__FILE__);

test('live journal mode without the collaboration adapter excludes write tools and rejects describe/prepare while preserving reads', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $office = $this->app->make(Office::class);
    $chatTools = $this->app->make(ChatTools::class);
    $laravelExecutor = $this->app->make(LaravelOfficeExecutor::class);
    $agenToolsRegistry = $this->app->make(OfficeToolDescriptorRegistry::class);
    $directory = $this->app->make(ResourceDirectory::class);

    $entryBefore = $directory->findAccessible($fixture->context, $fixture->node->id, 'edit');
    expect($entryBefore)->not->toBeNull()
        ->and($this->app->make(OfficeRegistry::class)->ownerFor(ReplaceExact::COMMAND, 'journal'))->toBeNull();

    // Before transition (snapshot mode): write tools and describe succeed.
    $snapshotCatalog = $chatTools->catalog($fixture->context, [$fixture->node->id]);
    expect(array_keys($snapshotCatalog))->toContain('office_read', 'office_describe', 'office_prepare', 'office_commit');
    expect($fixture->call(OfficeOperation::Describe, ['command' => 'text.replace_exact@1'])->ok)->toBeTrue();

    // Switch document into live journal mode WITHOUT collaboration / journal owner registered.
    $session = Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);
    expect($session->mode)->toBe(CollaborationMode::Write);

    $readAfterTransition = $fixture->call(OfficeOperation::Read, [
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]);
    expect($readAfterTransition->ok)->toBeTrue();

    // Dynamic catalogs must drop write tools while keeping reads.
    $journalCatalog = $chatTools->catalog($fixture->context, [$fixture->node->id]);
    expect(array_keys($journalCatalog))->toBe(['office_read', 'office_status', 'office_verify']);

    $laravelTools = array_map(static fn ($t): string => $t->name(), $laravelExecutor->toolsFor($fixture->context, resourceIds: [$fixture->node->id]));
    expect($laravelTools)->toBe(['office_search', 'office_read', 'office_status', 'office_verify']);

    $scopedAgenTools = $agenToolsRegistry->forScope($fixture->context, [$entryBefore]);
    expect($scopedAgenTools->descriptor('office_read'))->not->toBeNull()
        ->and($scopedAgenTools->descriptor('office_describe'))->toBeNull()
        ->and($scopedAgenTools->descriptor('office_prepare'))->toBeNull()
        ->and($scopedAgenTools->descriptor('office_commit'))->toBeNull();

    // Direct describe & prepare calls must fail closed with unsupported_content.
    $describe = $office->handle($fixture->context, new OfficeRequest(1, OfficeOperation::Describe, (object) [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
    ]));
    expect($describe->ok)->toBeFalse()
        ->and($describe->errorCode)->toBe(ErrorCode::UnsupportedContent);

    $prepare = $office->handle($fixture->context, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => $readAfterTransition->result->read_token,
        'arguments' => (object) ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ]));
    expect($prepare->ok)->toBeFalse()
        ->and($prepare->errorCode)->toBe(ErrorCode::UnsupportedContent);
});
