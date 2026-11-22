<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Operations\Describe;
use Vaulter\Ai\Core\Operations\DispatchOfficeOperation;
use Vaulter\Ai\Core\Operations\Read;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Testing\Concerns\RefreshesDatabaseForSchemaVariant;
use Vaulter\Testing\Concerns\UsesSuiteDatabase;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

/**
 * Shared Testbench harness booting Vaulter Core/Media/Drive/Documents and AI Core/Documents
 * across SQLite (:memory:) and PostgreSQL 18.
 */
abstract class OfficeTestCase extends OrchestraTestCase
{
    use RefreshesDatabaseForSchemaVariant;
    use UsesSuiteDatabase;

    protected bool $bootVaulterDomain = true;

    protected bool $bootDocumentsAi = true;

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
        }

        $providers[] = OfficeServiceProvider::class;

        if ($this->bootDocumentsAi && $this->bootVaulterDomain) {
            $providers[] = DocumentsAiServiceProvider::class;
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

        // Bind deterministic FixedClock in the container for expiry / cursor / signature verification.
        $app->instance(Clock::class, $this->fixedClock);
    }

    protected function defineDatabaseMigrations(): void
    {
        SuiteDatabaseConnection::skipOnForeignEngine($this);

        if ($this->bootVaulterDomain) {
            $this->ensureHostFixtureTables();
            $this->loadMigrationsFrom(SuiteDatabaseConnection::migrationPaths($this->app));
        }

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/packages/core/database/migrations');

        if ($this->bootDocumentsAi && $this->bootVaulterDomain) {
            $this->loadMigrationsFrom(dirname(__DIR__, 2).'/packages/documents/database/migrations');
        }
    }

    protected function freshRegistry(): OfficeRegistry
    {
        $registry = new OfficeRegistry;
        $this->app->instance(OfficeRegistry::class, $registry);
        $this->app->forgetInstance(Read::class);
        $this->app->forgetInstance(Describe::class);
        $this->app->forgetInstance(DispatchOfficeOperation::class);
        $this->app->forgetInstance(Office::class);

        return $registry;
    }

    private function ensureHostFixtureTables(): void
    {
        $schema = Schema::connection('testing');

        if (! $schema->hasTable('users')) {
            $schema->create('users', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
            });
        }

        if (! $schema->hasTable('ai_test_owners')) {
            $schema->create('ai_test_owners', static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
    }
}
