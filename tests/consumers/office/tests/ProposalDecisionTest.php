<?php

declare(strict_types=1);

use App\Models\StandActor;
use App\Models\StandOwner;
use App\Providers\StandServiceProvider;
use Carbon\Laravel\ServiceProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Laravel\Ai\AiServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\VaulterServiceProvider;

if (! class_exists(StandActor::class)) {
    require_once dirname(__DIR__).'/host/app/Models/StandActor.php';
}
if (! class_exists(StandOwner::class)) {
    require_once dirname(__DIR__).'/host/app/Models/StandOwner.php';
}
if (! class_exists(StandServiceProvider::class)) {
    require_once dirname(__DIR__).'/host/app/Providers/StandServiceProvider.php';
}

/**
 * Consumer stand acceptance test (hosts.md 7.1): boots tests/consumers/office/host directly,
 * exercises routes/web.php + resources/views/proposals.blade.php under two reviewers,
 * P/Q decision race, permission revocations, restart recovery, and two-resource partial resume.
 */
function bootConsumerStandHost(string $sqliteFile, string $runtimeTmpDir): Application
{
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$sqliteFile);
    putenv('CACHE_STORE=array');
    putenv('QUEUE_CONNECTION=sync');
    putenv('SESSION_DRIVER=array');
    putenv('FIXTURE_TOKEN=stand-test-fixture-token');
    putenv('STAND_PROFILE=ai-text');
    putenv('VAULTER_AI_STAND_APPROVAL=human');
    putenv('VAULTER_AI_DETERMINISTIC_SCRIPT=1');
    putenv('APP_PACKAGES_CACHE='.$runtimeTmpDir.'/bootstrap/packages.php');
    putenv('APP_SERVICES_CACHE='.$runtimeTmpDir.'/bootstrap/services.php');
    putenv('VIEW_COMPILED_PATH='.$runtimeTmpDir.'/views');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = $sqliteFile;
    $_ENV['FIXTURE_TOKEN'] = 'stand-test-fixture-token';
    $_ENV['VAULTER_AI_STAND_APPROVAL'] = 'human';
    $_ENV['APP_PACKAGES_CACHE'] = $runtimeTmpDir.'/bootstrap/packages.php';
    $_ENV['APP_SERVICES_CACHE'] = $runtimeTmpDir.'/bootstrap/services.php';
    $_ENV['VIEW_COMPILED_PATH'] = $runtimeTmpDir.'/views';

    foreach ([$runtimeTmpDir.'/bootstrap', $runtimeTmpDir.'/storage/framework/views', $runtimeTmpDir.'/storage/logs', $runtimeTmpDir.'/views'] as $dir) {
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    $manifest = [
        'axiomasoft/packman' => ['providers' => [PackmanServiceProvider::class]],
        'axiomasoft/packman-plugins' => ['providers' => [PluginsServiceProvider::class]],
        'axiomasoft/packman-schema' => ['providers' => [SchemaServiceProvider::class]],
        'axiomasoft/vaulter-core' => ['providers' => [VaulterServiceProvider::class]],
        'axiomasoft/vaulter-drive' => ['providers' => [VaulterDriveServiceProvider::class], 'aliases' => ['Vaulter' => Vaulter::class]],
        'axiomasoft/vaulter-media' => ['providers' => [VaulterMediaServiceProvider::class]],
        'axiomasoft/vaulter-documents' => ['providers' => [VaulterDocumentsServiceProvider::class]],
        'axiomasoft/vaulter-ai-core' => ['providers' => [OfficeServiceProvider::class]],
        'axiomasoft/vaulter-ai-documents' => ['providers' => [DocumentsAiServiceProvider::class]],
        'axiomasoft/vaulter-ai-laravel-ai' => ['providers' => [LaravelAiServiceProvider::class]],
        'laravel/ai' => ['providers' => [AiServiceProvider::class]],
        'nesbot/carbon' => ['providers' => [ServiceProvider::class]],
    ];
    file_put_contents($runtimeTmpDir.'/bootstrap/packages.php', '<?php return '.var_export($manifest, true).";\n");

    file_put_contents($runtimeTmpDir.'/.env', implode("\n", [
        'APP_ENV=testing',
        'APP_KEY=base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE='.$sqliteFile,
        'CACHE_STORE=array',
        'QUEUE_CONNECTION=sync',
        'SESSION_DRIVER=array',
        'FIXTURE_TOKEN=stand-test-fixture-token',
        'STAND_PROFILE=ai-text',
        'VAULTER_AI_STAND_APPROVAL=human',
        'VAULTER_AI_DETERMINISTIC_SCRIPT=1',
    ])."\n");

    /** @var Application $app */
    $app = require dirname(__DIR__).'/host/bootstrap/app.php';
    $app->useEnvironmentPath($runtimeTmpDir);
    $app->useStoragePath($runtimeTmpDir.'/storage');
    $kernel = $app->make(HttpKernel::class);
    $kernel->bootstrap();
    restore_error_handler();
    restore_exception_handler();

    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => $sqliteFile,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $app['config']->set('stand.approval', 'human');
    $app['config']->set('vaulter-ai.profiles.documents.approval', 'human');
    $app['config']->set('view.compiled', $runtimeTmpDir.'/views');

    return $app;
}

/**
 * @param  array<string, mixed>  $data
 */
function standRequest(Application $app, string $method, string $uri, string $bearerToken, array $data = [], array $headers = []): Response
{
    $server = [];
    if ($bearerToken !== '') {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearerToken;
    }
    foreach ($headers as $k => $v) {
        $upper = strtoupper(str_replace('-', '_', $k));
        if (in_array($upper, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $server[$upper] = $v;
        } else {
            $server['HTTP_'.$upper] = $v;
        }
    }
    $rawContent = null;
    if (($headers['Content-Type'] ?? '') === 'application/json') {
        $rawContent = json_encode($data, JSON_THROW_ON_ERROR);
        $data = [];
    }

    $request = Request::create($uri, $method, $data, [], [], $server, $rawContent);
    $response = $app->handle($request);
    $app->make(HttpKernel::class)->terminate($request, $response);

    return $response;
}

test('consumer stand web view and P/Q approval handlers enforce race safety, separate auth, revocation, and partial multi-resource recovery', function (): void {
    $runtimeDir = sys_get_temp_dir().'/vaulter-ai-stand-'.bin2hex(random_bytes(5));
    mkdir($runtimeDir, 0775, true);
    $dbPath = $runtimeDir.'/stand.sqlite';
    touch($dbPath);

    try {
        $app = bootConsumerStandHost($dbPath, $runtimeDir);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        // Seed stand workspace (writer, reviewer, reader, foreign, doc1, doc2).
        $seedResp = standRequest($app, 'POST', '/_fixture/seed', '', [], [
            'X-Fixture-Token' => 'stand-test-fixture-token',
            'Accept' => 'application/json',
        ]);
        expect($seedResp->getStatusCode())->toBe(200);
        $seed = json_decode((string) $seedResp->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $doc1 = (string) $seed['resource_id'];
        $doc2 = (string) $seed['second_resource_id'];
        $writerToken = (string) $seed['writer_token'];
        $reviewerToken = (string) $seed['reviewer_token'];
        $readerToken = (string) $seed['reader_token'];

        // Prepare independent proposals P (doc1) and Q (doc2) as Writer (initiator).
        $read1 = standRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $writerToken, [
            'version' => 1,
            'operation' => 'office.read',
            'input' => ['resource_id' => $doc1, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($read1->getStatusCode())->toBe(200, (string) $read1->getContent());
        $read1Data = json_decode((string) $read1->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $prepP = standRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $writerToken, [
            'version' => 1,
            'operation' => 'office.prepare',
            'input' => [
                'resource_id' => $doc1,
                'command' => 'text.replace_exact@1',
                'read_token' => $read1Data['result']['read_token'],
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
            ],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($prepP->getStatusCode())->toBe(200, (string) $prepP->getContent());
        $pData = json_decode((string) $prepP->getContent(), true, 512, JSON_THROW_ON_ERROR)['result'];

        $read2 = standRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $writerToken, [
            'version' => 1,
            'operation' => 'office.read',
            'input' => ['resource_id' => $doc2, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($read2->getStatusCode())->toBe(200, (string) $read2->getContent());
        $read2Data = json_decode((string) $read2->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $prepQ = standRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $writerToken, [
            'version' => 1,
            'operation' => 'office.prepare',
            'input' => [
                'resource_id' => $doc2,
                'command' => 'text.replace_exact@1',
                'read_token' => $read2Data['result']['read_token'],
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '25'],
            ],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($prepQ->getStatusCode())->toBe(200, (string) $prepQ->getContent());
        $qData = json_decode((string) $prepQ->getContent(), true, 512, JSON_THROW_ON_ERROR)['result'];

        // Reviewer opens routes/web.php -> /office/workspace rendering resources/views/proposals.blade.php.
        $panelResp = standRequest($app, 'GET', '/office/workspace', $reviewerToken);
        expect($panelResp->getStatusCode())->toBe(200);
        $html = (string) $panelResp->getContent();
        expect($html)->toContain($pData['proposal_id'])
            ->and($html)->toContain($pData['digest'])
            ->and($html)->toContain($qData['proposal_id'])
            ->and($html)->toContain('Расчёты / 4.2 Срок оплаты')
            ->and($html)->toContain('data-action="approve"')
            ->and($html)->toContain('data-action="reject"');

        // Pause queue so browser disconnect after decision leaves human_status != owner_status.
        $realDispatcher = $app->make(Dispatcher::class);
        Bus::fake([DispatchApprovedCommand::class]);
        $approveForm = standRequest($app, 'POST', '/office/proposals/'.$pData['proposal_id'].'/decision', $reviewerToken, [
            'digest' => $pData['digest'],
            'approved' => '1',
            'redirect_to' => '/office/workspace',
        ]);
        expect($approveForm->getStatusCode())->toBe(303);

        // Writer (2nd reviewer) tries to reject P -> loses CAS, Q stays pending.
        $rejectSecond = standRequest($app, 'POST', '/office/proposals/'.$pData['proposal_id'].'/decision', $writerToken, [
            'digest' => $pData['digest'],
            'approved' => false,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($rejectSecond->getStatusCode())->toBe(200);
        $raceBody = json_decode((string) $rejectSecond->getContent(), true)['data'];
        expect($raceBody['won'])->toBeFalse()
            ->and($raceBody['proposal']['approval']['human_status'])->toBe('approved')
            ->and($raceBody['proposal']['execution']['owner_status'])->toBe('pending_dispatch');

        $qView = json_decode((string) standRequest($app, 'GET', '/office/proposals/'.$qData['proposal_id'], $writerToken, [], ['Accept' => 'application/json'])->getContent(), true)['data'];
        expect($qView['approval']['human_status'])->toBe('pending');

        // Execute queued dispatch once, then reboot a brand new consumer stand container from the same SQLite DB.
        Bus::swap($realDispatcher);
        $app->call([new DispatchApprovedCommand('61', $pData['proposal_id']), 'handle']);
        $app->flush();

        $restartedApp = bootConsumerStandHost($dbPath, $runtimeDir);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($restartedApp);
        $restartedHtml = (string) standRequest($restartedApp, 'GET', '/office/workspace', $reviewerToken)->getContent();
        expect($restartedHtml)->toContain('Принято человеком')
            ->and($restartedHtml)->toContain('Сохранено владельцем')
            ->and($restartedHtml)->toContain('Проверено');

        expect(DB::table('v_ai_text_commands')->where('resource_id', $doc1)->count())->toBe(1);

        // Reader without edit gets no approval action in Blade view, and revoking reader access returns 404 without leaking diff.
        $readerHtml = (string) standRequest($restartedApp, 'GET', '/office/workspace', $readerToken)->getContent();
        expect($readerHtml)->toContain($pData['proposal_id'])
            ->and($readerHtml)->not->toContain('data-action="approve"');

        $driveOwner = StandOwner::query()->firstOrFail();
        $readerActor = StandActor::query()->where('email', 'like', 'reader-%')->firstOrFail();
        $drive = Vaulter::drive($driveOwner)->asSystem('revoke-reader');
        foreach ($drive->access()->grants($drive->root()->id) as $grant) {
            if ((string) $grant->subject->id === (string) $readerActor->getKey()) {
                $drive->access()->revoke($drive->root()->id, $grant->id);
            }
        }
        $revokedResp = standRequest($restartedApp, 'GET', '/office/workspace?proposal_id='.$pData['proposal_id'], $readerToken);
        expect($revokedResp->getStatusCode())->toBe(404)
            ->and((string) $revokedResp->getContent())->not->toContain('Pay within');

        // Multi-resource partial run on stand host: doc1 succeeds, doc2 hits precondition_failed, resume continues only doc2.
        $writerActor = StandActor::query()->findOrFail($seed['writer_user_id']);
        $writerCtx = new CallContext('61', 'default', 'user/'.$writerActor->getKey(), 'documents', 'stand-rev-1', (string) $seed['drive_id'], $writerActor);
        $restartedApp['config']->set('vaulter-ai.profiles.documents.approval', 'automatic');

        $doc2Snap = Documents::in($drive)->content($doc2);
        Documents::in($drive)->update($doc2, [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['blockId' => 'h-app2', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Приложение 2']]],
                ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                    ['type' => 'text', 'text' => 'Penalty 22 percent.'],
                ]],
            ],
        ], $doc2Snap->stateVersion);

        /** @var OfficeWorkspaceService $workspace */
        $workspace = $restartedApp->make(OfficeWorkspaceService::class);
        $doc1Node = Documents::in($drive)->content($doc1);
        Documents::in($drive)->update($doc1, [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['blockId' => 'h-calc', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Расчёты']]],
                ['type' => 'heading', 'attrs' => ['blockId' => 'h-42', 'level' => 2], 'content' => [['type' => 'text', 'text' => '4.2 Срок оплаты']]],
                ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                    ['type' => 'text', 'text' => '😀 Pay within '],
                    ['type' => 'text', 'text' => '45', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' working days.'],
                ]],
            ],
        ], $doc1Node->stateVersion);

        $multiRun = $workspace->createDraftRun($writerCtx, 'stand-draft', 'stand-multi-1', 'Update both docs', [
            [
                'resource_id' => $doc1,
                'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
                'command' => 'text.replace_exact@1',
                'arguments' => ['block_id' => 'p42', 'expected' => '45', 'replacement' => '60'],
            ],
            [
                'resource_id' => $doc2,
                'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
                'command' => 'text.replace_exact@1',
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '25'],
            ],
        ]);
        expect($multiRun['state'])->toBe('partially_applied')
            ->and($multiRun['confirmed_steps'])->toHaveCount(1)
            ->and($multiRun['remaining_steps'])->toHaveCount(1)
            ->and($multiRun['remaining_steps'][0]['requires_reread'])->toBeTrue();

        $resumed = standRequest($restartedApp, 'POST', '/office/runs/'.$multiRun['run_id'].'/resume', $writerToken, [
            'arguments_by_resource' => [
                $doc2 => ['block_id' => 'p42', 'expected' => '22', 'replacement' => '25'],
            ],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($resumed->getStatusCode())->toBe(200);
        $resumedData = json_decode((string) $resumed->getContent(), true)['data'];
        expect($resumedData['state'])->toBe('completed')
            ->and($resumedData['confirmed_steps'])->toHaveCount(2)
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc1)->count())->toBe(2)
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc2)->count())->toBe(1);
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($runtimeDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($runtimeDir);
    }
});
