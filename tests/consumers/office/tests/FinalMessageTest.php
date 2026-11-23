<?php

declare(strict_types=1);

use App\Models\StandActor;
use App\Models\StandOwner;
use App\Providers\StandServiceProvider;
use Carbon\Laravel\ServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
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
 * In-process PHP profile of hosts.md 7.2. The authorized HTTP client profile is remote_client.py.
 */
function bootFinalMessageHost(string $sqliteFile, string $runtimeTmpDir): Application
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
 * @param  array<string, string>  $headers
 */
function finalMessageRequest(Application $app, string $method, string $uri, string $bearerToken, array $data = [], array $headers = []): Response
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

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function postChannelEvent(Application $app, string $token, array $body): array
{
    $response = finalMessageRequest($app, 'POST', '/office/channel/events', $token, $body, [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]);

    return [
        'status' => $response->getStatusCode(),
        'raw' => (string) $response->getContent(),
        'json' => json_decode((string) $response->getContent(), true) ?? [],
    ];
}

test('channel emulator keeps a partial unpublished, dedupes the final message, and reconnects through the decision handler under ACL', function (): void {
    $runtimeDir = sys_get_temp_dir().'/vaulter-ai-final-'.bin2hex(random_bytes(5));
    mkdir($runtimeDir, 0775, true);
    $dbPath = $runtimeDir.'/stand.sqlite';
    touch($dbPath);

    try {
        $app = bootFinalMessageHost($dbPath, $runtimeDir);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        $seedResp = finalMessageRequest($app, 'POST', '/_fixture/seed', '', [], [
            'X-Fixture-Token' => 'stand-test-fixture-token',
            'Accept' => 'application/json',
        ]);
        expect($seedResp->getStatusCode())->toBe(200);
        $seed = json_decode((string) $seedResp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $doc = (string) $seed['resource_id'];
        $writerToken = (string) $seed['writer_token'];
        $reviewerToken = (string) $seed['reviewer_token'];
        $readerToken = (string) $seed['reader_token'];
        $foreignToken = (string) $seed['foreign_token'];

        $partial = postChannelEvent($app, $writerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-2',
            'phase' => 'partial',
            'resource_id' => $doc,
        ]);
        expect($partial['status'])->toBe(200, $partial['raw'])
            ->and($partial['json']['published'])->toBeFalse()
            ->and($partial['json']['run_id'])->toBeNull()
            ->and($partial['json']['run_count'])->toBe(0)
            ->and($partial['json']['channel'])->toBe('laravel-ai')
            ->and(DB::table('v_ai_runs')->count())->toBe(0)
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(0)
            ->and(DB::table('v_outbox')->where('aggregate_id', $doc)->where('type', 'office.text_command_committed')->count())->toBe(0);

        $again = postChannelEvent($app, $writerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-2',
            'phase' => 'partial',
            'resource_id' => $doc,
        ]);
        expect($again['status'])->toBe(200, $again['raw'])
            ->and($again['json']['published'])->toBeFalse()
            ->and(DB::table('v_ai_runs')->count())->toBe(0);

        $final = postChannelEvent($app, $writerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-3',
            'phase' => 'final',
            'resource_id' => $doc,
        ]);
        expect($final['status'])->toBe(200, $final['raw'])
            ->and($final['json']['published'])->toBeTrue()
            ->and($final['json']['duplicate'])->toBeFalse()
            ->and($final['json']['channel'])->toBe('laravel-ai')
            ->and($final['json']['source_event_id'])->toBe('event-3')
            ->and($final['json']['run_count'])->toBe(1);
        $runId = (string) $final['json']['run_id'];
        expect($runId)->not->toBe('')
            ->and(DB::table('v_ai_runs')->count())->toBe(1)
            ->and(DB::table('v_ai_runs')->where('run_id', $runId)->value('source_event_id'))->toBe('event-3')
            ->and(DB::table('v_ai_runs')->where('run_id', $runId)->value('state'))->toBe('awaiting_approval')
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(0);

        $duplicate = postChannelEvent($app, $writerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-3',
            'phase' => 'final',
            'resource_id' => $doc,
        ]);
        expect($duplicate['status'])->toBe(200, $duplicate['raw'])
            ->and($duplicate['json']['duplicate'])->toBeTrue()
            ->and($duplicate['json']['run_id'])->toBe($runId)
            ->and($duplicate['json']['run_count'])->toBe(1)
            ->and(DB::table('v_ai_runs')->count())->toBe(1)
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(0);

        $hijack = postChannelEvent($app, $readerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-4',
            'phase' => 'partial',
            'resource_id' => $doc,
        ]);
        expect($hijack['status'])->toBe(404, $hijack['raw'])
            ->and($hijack['json']['error']['code'])->toBe('resource_unavailable')
            ->and(DB::table('v_ai_runs')->count())->toBe(1);

        $readerFinal = postChannelEvent($app, $readerToken, [
            'session_id' => 'session-reader',
            'event_id' => 'event-9',
            'phase' => 'final',
            'resource_id' => $doc,
        ]);
        expect($readerFinal['status'])->toBe(404, $readerFinal['raw'])
            ->and(DB::table('v_ai_runs')->count())->toBe(1);

        $before = finalMessageRequest($app, 'GET', '/office/workspace?run_id='.$runId, $writerToken);
        expect($before->getStatusCode())->toBe(200, (string) $before->getContent());
        $beforeHtml = (string) $before->getContent();
        expect($beforeHtml)->not->toContain('data-testid="stand-confirmed-steps"')
            ->and($beforeHtml)->not->toContain('Сохранено владельцем');

        $listed = finalMessageRequest($app, 'GET', '/office/proposals?resource_id='.urlencode($doc), $reviewerToken, [], [
            'Accept' => 'application/json',
        ]);
        expect($listed->getStatusCode())->toBe(200, (string) $listed->getContent());
        $proposals = json_decode((string) $listed->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($proposals)->toHaveCount(1);
        $proposalId = (string) $proposals[0]['proposal_id'];
        $digest = (string) $proposals[0]['digest'];

        $decided = finalMessageRequest($app, 'POST', '/office/proposals/'.$proposalId.'/decision', $reviewerToken, [
            'digest' => $digest,
            'approved' => true,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($decided->getStatusCode())->toBe(200, (string) $decided->getContent())
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(1)
            ->and(DB::table('v_outbox')->where('aggregate_id', $doc)->where('type', 'office.text_command_committed')->count())->toBe(1);

        $afterDuplicate = postChannelEvent($app, $writerToken, [
            'session_id' => 'session-1',
            'event_id' => 'event-3',
            'phase' => 'final',
            'resource_id' => $doc,
        ]);
        expect($afterDuplicate['json']['run_id'])->toBe($runId)
            ->and($afterDuplicate['json']['run_count'])->toBe(1)
            ->and(DB::table('v_ai_runs')->count())->toBe(1)
            ->and(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(1);

        $reconnect = finalMessageRequest($app, 'GET', '/office/runs/'.$runId, $writerToken, [], [
            'Accept' => 'application/json',
        ]);
        expect($reconnect->getStatusCode())->toBe(200, (string) $reconnect->getContent());
        $run = json_decode((string) $reconnect->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($run['run_id'])->toBe($runId)
            ->and($run['channel'])->toBe('laravel-ai')
            ->and($run['confirmed_steps'])->toHaveCount(1)
            ->and($run['confirmed_steps'][0]['status'])->toBeIn(['committed', 'completed']);
        $receiptId = (string) $run['confirmed_steps'][0]['execution']['receipt_id'];
        $commandId = (string) $run['confirmed_steps'][0]['execution']['command_id'];
        expect($receiptId)->not->toBe('')
            ->and($commandId)->not->toBe('');

        $panel = (string) finalMessageRequest($app, 'GET', '/office/workspace?run_id='.$runId, $writerToken)->getContent();
        expect($panel)->toContain('data-testid="stand-confirmed-steps"')
            ->and($panel)->toContain($receiptId)
            ->and($panel)->toContain('Сохранено владельцем');

        $foreign = finalMessageRequest($app, 'GET', '/office/runs/'.$runId, $foreignToken, [], [
            'Accept' => 'application/json',
        ]);
        expect($foreign->getStatusCode())->toBe(404)
            ->and((string) $foreign->getContent())->not->toContain($receiptId)
            ->and((string) $foreign->getContent())->not->toContain('Pay within');

        $reader = finalMessageRequest($app, 'GET', '/office/runs/'.$runId, $readerToken, [], [
            'Accept' => 'application/json',
        ]);
        expect($reader->getStatusCode())->toBe(200, (string) $reader->getContent());
        $readerRun = json_decode((string) $reader->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($readerRun['confirmed_steps'])->toHaveCount(1)
            ->and($readerRun['confirmed_steps'][0]['execution']['receipt_id'])->toBe($receiptId);

        $status = finalMessageRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $writerToken, [
            'version' => 1,
            'operation' => 'office.status',
            'input' => ['resource_id' => $doc, 'command_id' => $commandId],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($status->getStatusCode())->toBe(200, (string) $status->getContent());
        $statusBody = json_decode((string) $status->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expect($statusBody['ok'])->toBeTrue()
            ->and($statusBody['result']['state'])->toBe('committed')
            ->and($statusBody['result']['receipt_id'])->toBe($receiptId)
            ->and($statusBody['result']['command_id'])->toBe($commandId);

        $foreignStatus = finalMessageRequest($app, 'POST', '/api/vaulter-ai/v1/operations', $foreignToken, [
            'version' => 1,
            'operation' => 'office.status',
            'input' => ['resource_id' => $doc, 'command_id' => $commandId],
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $foreignStatusBody = json_decode((string) $foreignStatus->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expect($foreignStatusBody['ok'])->toBeFalse()
            ->and($foreignStatusBody['error']['code'])->toBe('resource_unavailable')
            ->and((string) $foreignStatus->getContent())->not->toContain($receiptId);

        $driveOwner = StandOwner::query()->firstOrFail();
        $writerActor = StandActor::query()->findOrFail($seed['writer_user_id']);
        $drive = Vaulter::drive($driveOwner)->asSystem('revoke-writer');
        foreach ($drive->access()->grants($drive->root()->id) as $grant) {
            if ((string) $grant->subject->id === (string) $writerActor->getKey()) {
                $drive->access()->revoke($drive->root()->id, $grant->id);
            }
        }
        $revoked = finalMessageRequest($app, 'GET', '/office/runs/'.$runId, $writerToken, [], [
            'Accept' => 'application/json',
        ]);
        expect($revoked->getStatusCode())->toBe(404)
            ->and((string) $revoked->getContent())->not->toContain($receiptId)
            ->and((string) $revoked->getContent())->not->toContain('working days');
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
