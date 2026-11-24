<?php

declare(strict_types=1);

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Vaulter\Ai\Collaboration\CollaborationAiServiceProvider;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\Sheets\SheetsAiServiceProvider;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Kernel;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Sheets\VaulterSheetsServiceProvider;
use Vaulter\Testing\Support\SuiteDatabaseConnection;
use Vaulter\VaulterServiceProvider;

/**
 * Migrate every Vaulter module schema and every vaulter-ai package onto the
 * suite PostgreSQL database, and leave that schema in place for unindexed-fks.sql.
 *
 * Requires DB_CONNECTION=pgsql and a DB_DATABASE name that ends in _test.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

$connection = SuiteDatabaseConnection::resolve();
if (($connection['driver'] ?? '') !== 'pgsql') {
    fwrite(STDERR, "DB_CONNECTION must be pgsql so the foreign-key gate sees the migrated schema.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$app = new Application($root);
$app->singleton(Illuminate\Contracts\Http\Kernel::class, Illuminate\Foundation\Http\Kernel::class);
$app->singleton(Illuminate\Contracts\Console\Kernel::class, Illuminate\Foundation\Console\Kernel::class);
$app->singleton(ExceptionHandler::class, Handler::class);
$storage = sys_get_temp_dir().'/vaulter-ai-migrate-'.getmypid();
foreach (['app', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    if (! is_dir($storage.'/'.$directory)) {
        mkdir($storage.'/'.$directory, 0775, true);
    }
}
$app->useStoragePath($storage);
$app->useDatabasePath($storage);
$app->instance('config', new Repository([
    'app' => [
        'name' => 'VaulterAiMigrate',
        'env' => 'testing',
        'key' => 'base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
        'cipher' => 'AES-256-CBC',
    ],
    'database' => [
        'default' => 'testing',
        'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
        'connections' => [
            'testing' => $connection,
        ],
    ],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
]));

Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);

foreach ([
    EventServiceProvider::class,
    FilesystemServiceProvider::class,
    DatabaseServiceProvider::class,
    MigrationServiceProvider::class,
    CacheServiceProvider::class,
    EncryptionServiceProvider::class,
    AuthServiceProvider::class,
    BusServiceProvider::class,
    QueueServiceProvider::class,
    RoutingServiceProvider::class,
    TranslationServiceProvider::class,
    ValidationServiceProvider::class,
] as $baseProvider) {
    $app->register($baseProvider);
}

foreach ([
    VaulterServiceProvider::class,
    VaulterDriveServiceProvider::class,
    VaulterMediaServiceProvider::class,
    VaulterDocumentsServiceProvider::class,
    VaulterSheetsServiceProvider::class,
    OfficeServiceProvider::class,
    DocumentsAiServiceProvider::class,
    SheetsAiServiceProvider::class,
    VaulterCollaborationServiceProvider::class,
    CollaborationAiServiceProvider::class,
] as $provider) {
    $app->register($provider);
}

$app['config']->set('vaulter.database.write_guard', false);
$app['config']->set('vaulter.ids.strategy', 'uuid7');
$app['config']->set('vaulter.ids.host_keys', 'bigint');
$app['config']->set('vaulter.routes.middleware.authenticated', []);
$app['config']->set('vaulter-drive.access.permission_driver', 'database');
$app['config']->set('cache.default', 'array');
$app->forgetInstance(DatabaseOptions::class);

$sheetsCli = dirname($root).'/vaulter-sheets/packages/runtime/dist/cli.js';
if (is_file($sheetsCli)) {
    $app['config']->set('vaulter-sheets.runtime_command', ['node', $sheetsCli]);
}

$app->boot();

$modulePaths = SuiteDatabaseConnection::migrationPaths($app);
if ($modulePaths === []) {
    $reasons = $app->make(Kernel::class)->reasons();
    fwrite(STDERR, "Vaulter module migration paths are empty.\n".implode("\n", $reasons)."\n");
    exit(1);
}

$packagePaths = glob($root.'/packages/*/database/migrations', GLOB_ONLYDIR);
if ($packagePaths === false || $packagePaths === []) {
    fwrite(STDERR, "No vaulter-ai package migration directories found.\n");
    exit(1);
}

$paths = array_values(array_unique([...$modulePaths, ...$packagePaths]));
$migrator = $app->make('migrator');
$repository = $migrator->getRepository();
if (! $repository->repositoryExists()) {
    $repository->createRepository();
}

$ran = $migrator->run($paths);
echo 'migrated '.count($ran).' file(s) on '.($connection['database'] ?? '')."\n";
