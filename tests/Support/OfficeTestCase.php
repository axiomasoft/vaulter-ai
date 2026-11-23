<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Ai\AgenTools\AgenToolsServiceProvider;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Operations\Describe;
use Vaulter\Ai\Core\Operations\DispatchOfficeOperation;
use Vaulter\Ai\Core\Operations\Prepare;
use Vaulter\Ai\Core\Operations\Read;
use Vaulter\Ai\Core\Operations\Status;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Operations\Verify;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\Documents\Host\DocumentsCommandWriteCapability;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
use Vaulter\Ai\Sheets\SheetsAiServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Sheets\VaulterSheetsServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

/**
 * Shared Testbench harness booting Vaulter Core/Media/Drive/Documents and AI packages
 * across SQLite (:memory:) and PostgreSQL 18.
 */
abstract class OfficeTestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;

    protected bool $bootVaulterDomain = true;

    protected bool $bootVaulterSheets = true;

    protected bool $bootDocumentsAi = true;

    protected bool $bootSheetsAi = true;

    protected bool $bootLaravelAi = false;

    protected bool $bootAgenTools = false;

    protected FixedClock $fixedClock;

    protected function setUp(): void
    {
        $this->fixedClock = new FixedClock;
        parent::setUp();
    }

    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [];
        if ($this->bootVaulterDomain) {
            $providers[] = VaulterServiceProvider::class;
            $providers[] = VaulterDriveServiceProvider::class;
            $providers[] = VaulterMediaServiceProvider::class;
            $providers[] = VaulterDocumentsServiceProvider::class;
            if ($this->bootVaulterSheets) {
                $providers[] = VaulterSheetsServiceProvider::class;
            }
        }

        $providers[] = OfficeServiceProvider::class;

        if ($this->bootDocumentsAi && $this->bootVaulterDomain) {
            $providers[] = DocumentsAiServiceProvider::class;
        }

        if ($this->bootSheetsAi && $this->bootVaulterDomain && $this->bootVaulterSheets) {
            $providers[] = SheetsAiServiceProvider::class;
        }

        if ($this->bootLaravelAi) {
            $providers[] = AiServiceProvider::class;
            $providers[] = LaravelAiServiceProvider::class;
        }

        if ($this->bootAgenTools) {
            $providers[] = AgenToolsServiceProvider::class;
        }

        return $providers;
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->pinSuiteConnection($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('vaulter.routes.middleware.authenticated', []);
        $app['config']->set('vaulter-drive.access.permission_driver', 'database');
        $app['config']->set('vaulter.ids.strategy', env('VAULTER_TEST_ID_STRATEGY', 'uuid7'));
        $app['config']->set('vaulter.ids.host_keys', env('VAULTER_TEST_HOST_KEYS', 'bigint'));
        $app['config']->set('vaulter-documents.tiptap.block_ids', true);

        if ($this->bootVaulterDomain && $this->bootVaulterSheets) {
            $sheetsCli = dirname(__DIR__, 3).'/vaulter-sheets/packages/runtime/dist/cli.js';
            if (is_file($sheetsCli)) {
                $app['config']->set('vaulter-sheets.runtime_command', ['node', $sheetsCli]);
            }
        }

        // Bind deterministic FixedClock in the container for expiry / cursor / signature verification.
        $app->instance(Clock::class, $this->fixedClock);
    }

    protected function defineDatabaseMigrations(): void
    {
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        $paths = $this->bootVaulterDomain
            ? [__DIR__.'/database', ...SuiteDatabaseConnection::migrationPaths($this->app)]
            : [];
        $paths[] = dirname(__DIR__, 2).'/packages/core/database/migrations';
        if ($this->bootDocumentsAi && $this->bootVaulterDomain) {
            $paths[] = dirname(__DIR__, 2).'/packages/documents/database/migrations';
        }
        $this->loadMigrationsFrom($paths);
    }

    protected function freshRegistry(): OfficeRegistry
    {
        $registry = new OfficeRegistry;
        $this->app->instance(OfficeRegistry::class, $registry);
        $this->app->forgetInstance(Read::class);
        $this->app->forgetInstance(Describe::class);
        $this->app->forgetInstance(Prepare::class);
        $this->app->forgetInstance(Commit::class);
        $this->app->forgetInstance(Status::class);
        $this->app->forgetInstance(Verify::class);
        $this->app->forgetInstance(DispatchOfficeOperation::class);
        $this->app->forgetInstance(Office::class);
        $this->app->forgetInstance(StepCommitCoordinator::class);
        $this->app->forgetInstance(CommandWriteCapability::class);
        $this->app->forgetInstance(DocumentsCommandWriteCapability::class);
        $this->app->forgetInstance(ChatTools::class);

        return $registry;
    }
}
