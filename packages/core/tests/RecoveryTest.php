<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Schema\OfficeCoreSchema;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Contracts\CollaborationProtocolDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationGrant;
use Vaulter\Documents\Data\Collaboration\CollaborationMode;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Laravel\VaulterPluginRegistrar;

class TextRecoveryTestCase extends OfficeTestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), TextRecoveryCollaborationProvider::class];
    }
}

final class TextRecoveryCollaborationProvider extends ServiceProvider
{
    public function register(): void
    {
        VaulterPluginRegistrar::register($this->app, TextRecoveryCollaborationPlugin::class);
    }
}

final class TextRecoveryCollaborationPlugin implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'vaulter.test-office-recovery', version: '1.0.0', requires: ['vaulter.documents']);
    }

    public function register(PluginRegistrar $registrar): void {}

    public function slotContributions(): array
    {
        return [[
            'slot' => CollaborationProtocolDriver::class,
            'id' => 'office-recovery.yjs',
            'implementation' => TextRecoveryCollaborationDriver::class,
            'key' => 'yjs',
        ]];
    }
}

final class TextRecoveryCollaborationDriver implements CollaborationProtocolDriver
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
        return new CollaborationSession('yjs', $this->version(), null, 'fixture-token', $grant->notAfter,
            $grant->mode, $grant->nodeId, $grant->generation, $grant->schemaVersion,
        );
    }
}

uses(TextRecoveryTestCase::class);

test('a lost HTTP response between owner commit and CORE settle recovers the exact receipt after switching to journal', function (): void {
    $coreConfig = Config::get('vaulter-ai.database');
    Config::set('database.connections.office_recovery', Config::get('database.connections.testing'));
    Config::set('vaulter-ai.database.connection', 'office_recovery');
    Config::set('vaulter-ai.database.table_prefix', 'office_recovery_');
    $schema = $this->app->make(OfficeCoreSchema::class);
    $schema->createAll();

    try {
        $scenarios = json_decode(file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json'), true, 512, JSON_THROW_ON_ERROR)['scenarios'];
        $lost = array_column($scenarios, null, 'id')['lost_response'];
        $fixture = new TextCommandFixture($this->app, seed: $lost['events'][0]['details']);
        $fixture->authorizeHttp();
        $proposal = $fixture->prepare();
        $unexecuted = $fixture->prepare(replacement: '60');
        $fixture->edit('p71', static fn (): array => [['type' => 'text', 'text' => $lost['events'][1]['details']['new_text']]]);

        $db = $this->app->make(OfficeDatabase::class);
        $owner = $this->app->make(VaulterDatabase::class)->connection();
        expect($db->connection())->not->toBe($owner);
        $call = fn () => $this->postJson('/api/vaulter-ai/v1/operations', [
            'version' => 1, 'operation' => 'office.commit',
            'input' => ['resource_id' => $fixture->node->id] + $fixture->commitInput($proposal),
        ], ['Authorization' => 'Bearer scenario-test-token']);
        $this->withoutExceptionHandling();
        $killProcess = $db->driver() === 'pgsql';
        $fault = true;
        $db->connection()->beforeExecuting(static function (string $sql) use ($killProcess, &$fault): void {
            if ($fault && str_starts_with($sql, 'update "office_recovery_ai_owner_routes" set "receipt_id"')) {
                $fault = false;
                if ($killProcess) {
                    posix_kill(getmypid(), SIGKILL);
                }
                throw new RuntimeException('lost response after owner commit');
            }
        });

        if ($killProcess) {
            DB::disconnect('office_recovery');
            DB::disconnect('testing');
            $worker = pcntl_fork();
            expect($worker)->toBeGreaterThanOrEqual(0);
            if ($worker === 0) {
                $call();
                exit(1);
            }
            pcntl_waitpid($worker, $status);
            $fault = false;
            expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
        } else {
            expect($call)->toThrow(RuntimeException::class, 'lost response after owner commit');
        }

        expect($db->query('ai_owner_routes')->count())->toBe(1)
            ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBeNull()
            ->and($owner->table('v_ai_text_commands')->count())->toBe(1)
            ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);
        $receipt = $owner->table('v_ai_text_commands')->value('receipt_id');
        $session = Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);
        expect($session->mode)->toBe(CollaborationMode::Write)->and($session->generation)->toBe(4);

        $recovered = $fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1']);
        expect($recovered->result->receipt_id)->toBe($receipt)
            ->and((array) $recovered->result->marker)->toBe(['generation' => 3, 'position' => 29])
            ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBe($receipt);
        $db->query('ai_proposal_payloads')->where('proposal_id', $proposal->proposal_id)->delete();
        $retry = $call()->assertOk()->json('result');
        expect($retry)->toBe($recovered->toArray()['result'])
            ->and($owner->table('v_ai_text_commands')->count())->toBe(1)
            ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);

        $verify = ['receipt_id' => $receipt];
        expect($fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'at_commit'])->result->outcome)->toBe('passed')
            ->and($fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'current'])->errorCode)->toBe(ErrorCode::GenerationChanged);
        expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($unexecuted, 'edit-2'))->errorCode)->toBe(ErrorCode::GenerationChanged)
            ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-2'])->result->reason)->toBe('generation_changed')
            ->and($fixture->snapshot()->stateVersion)->toBe(30);
    } finally {
        $schema->dropAll();
        Config::set('vaulter-ai.database', $coreConfig);
    }
});
