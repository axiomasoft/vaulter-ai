<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function removeMatrixTree(string $path): void
{
    if (! is_dir($path) && ! is_link($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

function packZipFromDirectory(string $sourceDir, string $zipPath, string $version, array $rewriteConstraints = []): void
{
    $zip = new ZipArchive;
    expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue("Cannot open {$zipPath}");

    $excludedNames = ['vendor', 'node_modules', 'tests', 'reference', 'testkit', 'conformance', 'tmp', '.phpunit.cache', 'build', '.git'];
    $directoryIterator = new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS);
    $filtered = new RecursiveCallbackFilterIterator(
        $directoryIterator,
        static function (SplFileInfo $current) use ($excludedNames): bool {
            $name = $current->getFilename();
            if (str_starts_with($name, '.') || in_array($name, $excludedNames, true)) {
                return false;
            }

            return true;
        },
    );
    $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::SELF_FIRST);
    $baseLen = strlen(rtrim($sourceDir, '/')) + 1;

    foreach ($iterator as $entry) {
        /** @var SplFileInfo $entry */
        if ($entry->isLink()) {
            $zip->close();
            throw new RuntimeException("Refused symlink {$entry->getPathname()} while packing {$zipPath}");
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), $baseLen));
        if ($entry->isDir()) {
            continue;
        }
        if ($relative === 'composer.json') {
            $manifest = json_decode((string) file_get_contents($entry->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            $manifest['version'] = $version;
            unset($manifest['repositories']);
            foreach ($rewriteConstraints as $dep => $ver) {
                if (isset($manifest['require'][$dep])) {
                    $manifest['require'][$dep] = $ver;
                }
            }
            $zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } else {
            $bytes = file_get_contents($entry->getPathname());
            expect($bytes)->toBeString();
            $zip->addFromString($relative, (string) $bytes);
        }
    }

    $zip->close();
}

test('isolated Composer artifact installs verify core+documents, laravel-ai without AgenTools, and agentools', function (): void {
    $root = dirname(__DIR__, 3);
    $parentDir = dirname($root);
    $tmp = sys_get_temp_dir().'/vaulter-ai-matrix-'.bin2hex(random_bytes(5));
    $artifactsDir = $tmp.'/artifacts';
    mkdir($artifactsDir, 0775, true);

    try {
        // Pack first-party and local SDK ZIP dist archives without symlinks (offline Composer artifact repository).
        $localArchives = [
            [$root.'/packages/core', 'axiomasoft-vaulter-ai-core-1.0.0.zip', '1.0.0', []],
            [$root.'/packages/documents', 'axiomasoft-vaulter-ai-documents-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-ai-core' => '^1.0',
                'axiomasoft/vaulter-core' => '^1.0',
                'axiomasoft/vaulter-documents' => '^1.0',
                'axiomasoft/vaulter-drive' => '^1.0',
            ]],
            [$root.'/packages/sheets', 'axiomasoft-vaulter-ai-sheets-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-ai-core' => '^1.0',
                'axiomasoft/vaulter-sheets' => '^1.0',
                'axiomasoft/vaulter-core' => '^1.0',
                'axiomasoft/vaulter-documents' => '^1.0',
                'axiomasoft/vaulter-drive' => '^1.0',
                'axiomasoft/packman-plugins' => '0.1.1',
            ]],
            [$root.'/packages/laravel-ai', 'axiomasoft-vaulter-ai-laravel-ai-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-ai-core' => '^1.0',
            ]],
            [$root.'/packages/agentools', 'axiomasoft-vaulter-ai-agentools-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-ai-core' => '^1.0',
                'agentools/sdk' => '^1.0',
            ]],
            [$parentDir.'/agentools/php', 'agentools-sdk-1.0.0.zip', '1.0.0', []],
            [$parentDir.'/vaulter-sheets/packages/sheets', 'axiomasoft-vaulter-sheets-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-core' => '1.0.0',
                'axiomasoft/vaulter-media' => '1.0.0',
                'axiomasoft/vaulter-drive' => '1.0.0',
                'axiomasoft/vaulter-documents' => '1.0.0',
                'axiomasoft/packman' => '0.1.1',
                'axiomasoft/packman-plugins' => '0.1.1',
            ]],
            [$parentDir.'/vaulter/packages/core', 'axiomasoft-vaulter-core-1.0.0.zip', '1.0.0', [
                'axiomasoft/packman' => '0.1.1',
                'axiomasoft/packman-plugins' => '0.1.1',
                'axiomasoft/packman-schema' => '0.1.1',
            ]],
            [$parentDir.'/vaulter/packages/media', 'axiomasoft-vaulter-media-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-core' => '1.0.0',
                'axiomasoft/packman' => '0.1.1',
                'axiomasoft/packman-plugins' => '0.1.1',
                'axiomasoft/packman-schema' => '0.1.1',
            ]],
            [$parentDir.'/vaulter/packages/drive', 'axiomasoft-vaulter-drive-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-core' => '1.0.0',
                'axiomasoft/vaulter-media' => '1.0.0',
                'axiomasoft/packman' => '0.1.1',
                'axiomasoft/packman-plugins' => '0.1.1',
                'axiomasoft/packman-schema' => '0.1.1',
            ]],
            [$parentDir.'/vaulter/packages/documents', 'axiomasoft-vaulter-documents-1.0.0.zip', '1.0.0', [
                'axiomasoft/vaulter-core' => '1.0.0',
                'axiomasoft/vaulter-drive' => '1.0.0',
                'axiomasoft/packman' => '0.1.1',
                'axiomasoft/packman-plugins' => '0.1.1',
                'axiomasoft/packman-schema' => '0.1.1',
            ]],
            [$parentDir.'/packman/packages/core', 'axiomasoft-packman-0.1.1.zip', '0.1.1', []],
            [$parentDir.'/packman/packages/plugins', 'axiomasoft-packman-plugins-0.1.1.zip', '0.1.1', ['axiomasoft/packman' => '0.1.1']],
            [$parentDir.'/packman/packages/schema', 'axiomasoft-packman-schema-0.1.1.zip', '0.1.1', ['axiomasoft/packman' => '0.1.1']],
        ];

        foreach ($localArchives as [$srcDir, $zipName, $ver, $rewrites]) {
            expect(is_dir($srcDir))->toBeTrue("Missing source package directory {$srcDir}");
            packZipFromDirectory($srcDir, $artifactsDir.'/'.$zipName, $ver, $rewrites);
        }

        // Build offline path repository map for third-party vendor dependencies already locked in vendor/composer/installed.json.
        $installed = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $thirdPartyRepos = [];
        foreach ($installed['packages'] ?? [] as $pkg) {
            $name = $pkg['name'] ?? '';
            $version = $pkg['version'] ?? '';
            if (! is_string($name) || ! is_string($version) || $name === '' || $version === '') {
                continue;
            }
            if (str_starts_with($name, 'axiomasoft/') || $name === 'agentools/sdk') {
                continue;
            }
            $vendorPkgDir = $root.'/vendor/'.$name;
            if (! is_file($vendorPkgDir.'/composer.json')) {
                continue;
            }
            $thirdPartyRepos[] = [
                'type' => 'path',
                'url' => $vendorPkgDir,
                'options' => [
                    'symlink' => false,
                    'versions' => [$name => $version],
                ],
            ];
        }

        $variants = [
            'core_only' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-documents', 'axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-ai-laravel-ai', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'expected_executors' => [],
                'has_text' => false,
                'has_sheets' => false,
                'run_agent_smoke' => false,
            ],
            'core_documents' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                    'axiomasoft/vaulter-ai-documents' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core', 'axiomasoft/vaulter-ai-documents'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-ai-laravel-ai', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'expected_executors' => [],
                'has_text' => true,
                'has_sheets' => false,
                'run_agent_smoke' => false,
            ],
            'core_sheets' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                    'axiomasoft/vaulter-ai-sheets' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core', 'axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-sheets'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-documents', 'axiomasoft/vaulter-ai-laravel-ai', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'expected_executors' => [],
                'has_text' => false,
                'has_sheets' => true,
                'run_agent_smoke' => false,
            ],
            'core_documents_sheets' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                    'axiomasoft/vaulter-ai-documents' => '1.0.0',
                    'axiomasoft/vaulter-ai-sheets' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core', 'axiomasoft/vaulter-ai-documents', 'axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-sheets'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-laravel-ai', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'expected_executors' => [],
                'has_text' => true,
                'has_sheets' => true,
                'run_agent_smoke' => false,
            ],
            'ai_text_no_agentools' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                    'axiomasoft/vaulter-ai-documents' => '1.0.0',
                    'axiomasoft/vaulter-ai-laravel-ai' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core', 'axiomasoft/vaulter-ai-documents', 'axiomasoft/vaulter-ai-laravel-ai', 'laravel/ai'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'expected_executors' => ['laravel-ai'],
                'has_text' => true,
                'has_sheets' => false,
                'run_agent_smoke' => true,
            ],
            'ai_agentools' => [
                'require' => [
                    'php' => '^8.4',
                    'laravel/framework' => '^13.0',
                    'axiomasoft/vaulter-ai-core' => '1.0.0',
                    'axiomasoft/vaulter-ai-documents' => '1.0.0',
                    'axiomasoft/vaulter-ai-agentools' => '1.0.0',
                ],
                'must_exist' => ['axiomasoft/vaulter-ai-core', 'axiomasoft/vaulter-ai-documents', 'axiomasoft/vaulter-ai-agentools', 'agentools/sdk'],
                'must_be_absent' => ['axiomasoft/vaulter-ai-sheets', 'axiomasoft/vaulter-ai-laravel-ai'],
                'expected_executors' => ['agentools'],
                'has_text' => true,
                'has_sheets' => false,
                'run_agent_smoke' => false,
            ],
        ];

        $composerHome = $tmp.'/composer-home';
        mkdir($composerHome, 0775, true);

        foreach ($variants as $name => $spec) {
            $consumerDir = $tmp.'/consumers/'.$name;
            mkdir($consumerDir, 0775, true);

            $composerJson = [
                'name' => 'vaulter-ai-test/consumer-'.$name,
                'type' => 'project',
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
                'repositories' => array_merge(
                    [['packagist.org' => false], ['type' => 'artifact', 'url' => $artifactsDir]],
                    $thirdPartyRepos,
                ),
                'require' => $spec['require'],
                'config' => [
                    'allow-plugins' => ['php-http/discovery' => false, 'tbachert/spi' => false],
                ],
            ];
            file_put_contents($consumerDir.'/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            $install = new Process(
                ['composer', 'install', '--no-dev', '--no-interaction', '--no-scripts', '--no-progress', '--prefer-dist'],
                $consumerDir,
                ['COMPOSER_HOME' => $composerHome],
                timeout: 120,
            );
            $install->mustRun();

            $locked = json_decode((string) file_get_contents($consumerDir.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            $installedNames = array_column($locked['packages'] ?? [], 'name');

            foreach ($spec['must_exist'] as $reqPkg) {
                expect(in_array($reqPkg, $installedNames, true))->toBeTrue("Consumer {$name} missing {$reqPkg}");
            }
            foreach ($spec['must_be_absent'] as $absentPkg) {
                expect(in_array($absentPkg, $installedNames, true))->toBeFalse("Consumer {$name} unexpectedly installed {$absentPkg}");
            }

            // Boot isolated Laravel container using ONLY the consumer's vendor/autoload.php & installed providers.
            $probeScript = $consumerDir.'/probe.php';
            file_put_contents($probeScript, <<<'PHP'
<?php
declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\ToolCall;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

$app = new Application(__DIR__);
$app->singleton(\Illuminate\Contracts\Http\Kernel::class, \Illuminate\Foundation\Http\Kernel::class);
$app->singleton(\Illuminate\Contracts\Console\Kernel::class, \Illuminate\Foundation\Console\Kernel::class);
$app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \Illuminate\Foundation\Exceptions\Handler::class);
$app->useDatabasePath(__DIR__.'/database');
$app->instance('config', new \Illuminate\Config\Repository([
    'app' => [
        'name' => 'VaulterMatrixConsumer',
        'env' => 'testing',
        'key' => 'base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
        'cipher' => 'AES-256-CBC',
    ],
    'database' => [
        'default' => 'sqlite',
        'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
        'connections' => [
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ],
    ],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
    'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => __DIR__.'/storage']]],
]));

Illuminate\Support\Facades\Facade::clearResolvedInstances();
Illuminate\Support\Facades\Facade::setFacadeApplication($app);

foreach ([
    \Illuminate\Events\EventServiceProvider::class,
    \Illuminate\Filesystem\FilesystemServiceProvider::class,
    \Illuminate\Database\DatabaseServiceProvider::class,
    \Illuminate\Database\MigrationServiceProvider::class,
    \Illuminate\Cache\CacheServiceProvider::class,
    \Illuminate\Encryption\EncryptionServiceProvider::class,
    \Illuminate\Auth\AuthServiceProvider::class,
    \Illuminate\Bus\BusServiceProvider::class,
    \Illuminate\Queue\QueueServiceProvider::class,
    \Illuminate\Routing\RoutingServiceProvider::class,
    \Illuminate\Translation\TranslationServiceProvider::class,
    \Illuminate\Validation\ValidationServiceProvider::class,
] as $baseProvider) {
    $app->register($baseProvider);
}

$providers = [
    \Vaulter\Ai\Core\OfficeServiceProvider::class,
];
if (class_exists(\Vaulter\Ai\Documents\DocumentsAiServiceProvider::class) || class_exists(\Vaulter\Ai\Sheets\SheetsAiServiceProvider::class)) {
    array_unshift(
        $providers,
        \Vaulter\VaulterServiceProvider::class,
        \Vaulter\Media\VaulterMediaServiceProvider::class,
        \Vaulter\Drive\VaulterDriveServiceProvider::class,
        \Vaulter\Documents\VaulterDocumentsServiceProvider::class,
    );
}
if (class_exists(\Vaulter\Ai\Documents\DocumentsAiServiceProvider::class)) {
    $providers[] = \Vaulter\Ai\Documents\DocumentsAiServiceProvider::class;
}
if (class_exists(\Vaulter\Sheets\VaulterSheetsServiceProvider::class)) {
    $providers[] = \Vaulter\Sheets\VaulterSheetsServiceProvider::class;
}
if (class_exists(\Vaulter\Ai\Sheets\SheetsAiServiceProvider::class)) {
    $providers[] = \Vaulter\Ai\Sheets\SheetsAiServiceProvider::class;
}
if (class_exists(\Vaulter\Ai\LaravelAi\LaravelAiServiceProvider::class)) {
    $providers[] = \Laravel\Ai\AiServiceProvider::class;
    $providers[] = \Vaulter\Ai\LaravelAi\LaravelAiServiceProvider::class;
}
if (class_exists(\Vaulter\Ai\AgenTools\AgenToolsServiceProvider::class)) {
    $providers[] = \Vaulter\Ai\AgenTools\AgenToolsServiceProvider::class;
}

foreach ($providers as $provider) {
    $app->register($provider);
}
$app->boot();

$migrationPaths = [];
if (class_exists(\Vaulter\Schema\ModuleSchemas::class)) {
    $migrationPaths = array_merge($migrationPaths, $app->make(\Vaulter\Schema\ModuleSchemas::class)->migrationPaths());
}
$migrationPaths[] = __DIR__.'/vendor/axiomasoft/vaulter-ai-core/database/migrations';
if (class_exists(\Vaulter\Ai\Documents\DocumentsAiServiceProvider::class)) {
    $migrationPaths[] = __DIR__.'/vendor/axiomasoft/vaulter-ai-documents/database/migrations';
}
$migrator = $app->make('migrator');
$migrator->getRepository()->createRepository();
$migrator->run($migrationPaths);

$registry = $app->make(OfficeRegistry::class);
$executors = [];
foreach (['laravel-ai', 'agentools'] as $k) {
    if ($registry->executor($k) !== null) {
        $executors[] = $k;
    }
}

$toolNames = [];
$agentSmokeOk = false;
if (getenv('RUN_AGENT_SMOKE') === '1') {
    $app['config']->set('ai.default', 'openai');
    $app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'sk-matrix-smoke']);
    $app['config']->set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-matrix',
    ]);
    $executor = $app->make(\Vaulter\Ai\LaravelAi\LaravelOfficeExecutor::class);
    $ctx = new CallContext('t1', 'p1', 'user/1');
    foreach ($executor->toolsFor($ctx) as $t) {
        $toolNames[] = $t->name();
    }
    \Vaulter\Ai\LaravelAi\OfficeAgent::fake([
        new ToolCall('tc-m-1', 'office_search', ['query' => 'demo']),
        'Verified search in AgenTools-free consumer.',
    ]);
    $runs = $app->make(Runs::class);
    $run = $runs->createOrFind($ctx, 'laravel-ai', 'evt-matrix-1', 'rev-matrix', ['prompt' => 'search demo']);
    $finished = $executor->executeRun($ctx, $run->runId);
    $agentSmokeOk = $finished->state === 'completed' && count($runs->listSteps($ctx, $run->runId)) === 1;
}

$descriptorNames = [];
if (class_exists(\Vaulter\Ai\AgenTools\OfficeToolDescriptorRegistry::class)) {
    $descRegistry = $app->make(\Vaulter\Ai\AgenTools\OfficeToolDescriptorRegistry::class);
    foreach ($descRegistry->all() as $d) {
        $descriptorNames[] = $d->name;
    }
}

echo json_encode([
    'has_ai_runs' => Schema::hasTable('v_ai_runs'),
    'has_text_commands' => Schema::hasTable('v_ai_text_commands'),
    'has_sheets_commands' => Schema::hasTable('v_sheets_commands'),
    'has_reader_text' => $registry->reader(ResourceType::Text) !== null,
    'has_replace_cmd' => $registry->command('text.replace_exact@1') !== null,
    'has_reader_sheet' => $registry->reader(ResourceType::Sheet) !== null,
    'has_fill_formula_cmd' => $registry->command('sheet.fill_formula@1') !== null,
    'executors' => $executors,
    'laravel_tools' => $toolNames,
    'agentools_descriptors' => $descriptorNames,
    'agent_smoke_ok' => $agentSmokeOk,
], JSON_THROW_ON_ERROR).PHP_EOL;
PHP);

            $probe = new Process(
                ['php', $probeScript],
                $consumerDir,
                ['RUN_AGENT_SMOKE' => $spec['run_agent_smoke'] ? '1' : '0'],
                timeout: 30,
            );
            $probe->mustRun();
            $out = json_decode(trim($probe->getOutput()), true, 512, JSON_THROW_ON_ERROR);

            expect($out['has_ai_runs'])->toBeTrue("v_ai_runs missing in {$name}")
                ->and($out['has_text_commands'])->toBe($spec['has_text'])
                ->and($out['has_sheets_commands'])->toBe($spec['has_sheets'])
                ->and($out['executors'])->toBe($spec['expected_executors'])
                ->and($out['has_reader_text'])->toBe($spec['has_text'])
                ->and($out['has_replace_cmd'])->toBe($spec['has_text'])
                ->and($out['has_reader_sheet'])->toBe($spec['has_sheets'])
                ->and($out['has_fill_formula_cmd'])->toBe($spec['has_sheets']);

            if ($spec['run_agent_smoke']) {
                expect($out['agent_smoke_ok'])->toBeTrue("Agent smoke failed in {$name}")
                    ->and($out['laravel_tools'])->toHaveCount(7)
                    ->and($out['agentools_descriptors'])->toBeEmpty();
            }
            if ($name === 'ai_agentools') {
                expect($out['agentools_descriptors'])->toHaveCount(7)
                    ->and($out['laravel_tools'])->toBeEmpty();
            }
        }
    } finally {
        removeMatrixTree($tmp);
    }
});
