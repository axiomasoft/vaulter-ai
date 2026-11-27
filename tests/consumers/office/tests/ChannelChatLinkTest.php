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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiServiceProvider;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Ai\AgenTools\AgenToolsServiceProvider;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\LaravelAi\ExecuteOfficeRun;
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

function bootChannelChatHost(string $sqliteFile, string $runtimeTmpDir, string $profile): Application
{
    if (getenv('STAND_PG_ASYNC') === '1' || getenv('DB_CONNECTION') === 'pgsql') {
        if (is_file('/app/bootstrap/app.php')) {
            file_put_contents($runtimeTmpDir.'/.env', "APP_ENV=testing\n");
            putenv('VAULTER_AI_STAND_APPROVAL=human');
            putenv('STAND_PROFILE='.$profile);
            $_ENV['VAULTER_AI_STAND_APPROVAL'] = 'human';
            $_ENV['STAND_PROFILE'] = $profile;
            $_SERVER['VAULTER_AI_STAND_APPROVAL'] = 'human';
            $_SERVER['STAND_PROFILE'] = $profile;

            /** @var Application $app */
            $app = require '/app/bootstrap/app.php';
            $app->useEnvironmentPath($runtimeTmpDir);
            $kernel = $app->make(HttpKernel::class);
            $kernel->bootstrap();
            restore_error_handler();
            restore_exception_handler();

            $app['config']->set('stand.profile', $profile);
            $app['config']->set('stand.approval', 'human');
            $app['config']->set('vaulter-ai.profiles.documents.approval', 'human');
            $app['config']->set('vaulter-ai.profiles.documents_human.approval', 'human');
            $app['config']->set('vaulter-ai.profiles.sheets.approval', 'human');
            $app['config']->set('vaulter-ai.profiles.sheets_human.approval', 'human');
            if (is_dir($runtimeTmpDir.'/views')) {
                $app['config']->set('view.compiled', $runtimeTmpDir.'/views');
            }

            return $app;
        }
    }

    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$sqliteFile);
    putenv('CACHE_STORE=array');
    putenv('QUEUE_CONNECTION=sync');
    putenv('SESSION_DRIVER=array');
    putenv('FIXTURE_TOKEN=stand-test-fixture-token');
    putenv('STAND_PROFILE='.$profile);
    putenv('VAULTER_AI_STAND_APPROVAL=human');
    putenv('VAULTER_AI_DETERMINISTIC_SCRIPT=1');
    putenv('APP_PACKAGES_CACHE='.$runtimeTmpDir.'/bootstrap/packages.php');
    putenv('APP_SERVICES_CACHE='.$runtimeTmpDir.'/bootstrap/services.php');
    putenv('VIEW_COMPILED_PATH='.$runtimeTmpDir.'/views');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = $sqliteFile;
    $_ENV['FIXTURE_TOKEN'] = 'stand-test-fixture-token';
    $_ENV['STAND_PROFILE'] = $profile;
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
        'nesbot/carbon' => ['providers' => [ServiceProvider::class]],
    ];
    if ($profile === 'ai-agentools') {
        $manifest['axiomasoft/vaulter-ai-agentools'] = ['providers' => [AgenToolsServiceProvider::class]];
    } else {
        $manifest['axiomasoft/vaulter-ai-laravel-ai'] = ['providers' => [LaravelAiServiceProvider::class]];
        $manifest['laravel/ai'] = ['providers' => [AiServiceProvider::class]];
    }
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
        'STAND_PROFILE='.$profile,
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
    $app['config']->set('stand.profile', $profile);
    $app['config']->set('stand.approval', 'human');
    $app['config']->set('vaulter-ai.profiles.documents.approval', 'human');
    $app['config']->set('view.compiled', $runtimeTmpDir.'/views');

    return $app;
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 */
function channelChatReq(Application $app, string $method, string $uri, string $bearerToken, array $data = [], array $headers = []): Response
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

test('external channel links partial/final/duplicate/approval cleanly to a persistent ChatWorkspace session across profiles', function (string $profile, string $expectedChannel): void {
    $runtimeDir = sys_get_temp_dir().'/vaulter-ai-chatchain-'.bin2hex(random_bytes(5));
    mkdir($runtimeDir, 0775, true);
    $dbPath = $runtimeDir.'/stand.sqlite';
    touch($dbPath);

    try {
        $asyncPg = getenv('STAND_PG_ASYNC') === '1' || getenv('DB_CONNECTION') === 'pgsql';
        $fixtureToken = getenv('FIXTURE_TOKEN') ?: 'stand-test-fixture-token';
        $app = bootChannelChatHost($dbPath, $runtimeDir, $profile);
        if (! $asyncPg) {
            Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        }

        $seedResp = channelChatReq($app, 'POST', '/_fixture/seed', '', ['approval' => 'human'], [
            'X-Fixture-Token' => $fixtureToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);
        $seed = json_decode((string) $seedResp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $doc = (string) $seed['resource_id'];
        $writerToken = (string) $seed['writer_token'];
        $reviewerToken = (string) $seed['reviewer_token'];

        $extSession = 'ext-sess-'.bin2hex(random_bytes(4));
        $partEvt = 'evt-p1-'.bin2hex(random_bytes(3));
        $finalEvt = 'evt-f1-'.bin2hex(random_bytes(3));
        $unlinkEvt = 'evt-unlinked-'.bin2hex(random_bytes(3));

        // 1. Partial event links a chat_session_id with ZERO runs and ZERO chat messages.
        $partialResp = channelChatReq($app, 'POST', '/office/channel/events', $writerToken, [
            'session_id' => $extSession,
            'event_id' => $partEvt,
            'phase' => 'partial',
            'resource_id' => $doc,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        expect($partialResp->getStatusCode())->toBe(200);
        $partial = json_decode((string) $partialResp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $chatSessionId = (string) ($partial['chat_session_id'] ?? '');
        expect($partial['channel'])->toBe($expectedChannel)
            ->and($partial['run_id'])->toBeNull()
            ->and($partial['run_count'])->toBe(0)
            ->and($chatSessionId)->not->toBe('');

        $shownPartial = json_decode((string) channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId, $writerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($shownPartial['runs'])->toBe([])
            ->and($shownPartial['messages'])->toBe([]);

        // 2. Final event creates 1 run linked into that same chat_session_id and mirrors awaiting_approval state.
        if (! $asyncPg && $expectedChannel === 'laravel-ai') {
            Bus::fake([ExecuteOfficeRun::class]);
        }
        $finalResp = channelChatReq($app, 'POST', '/office/channel/events', $writerToken, [
            'session_id' => $extSession,
            'event_id' => $finalEvt,
            'phase' => 'final',
            'resource_id' => $doc,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $final = json_decode((string) $finalResp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $runId = (string) ($final['run_id'] ?? '');
        expect($final['chat_session_id'])->toBe($chatSessionId)
            ->and($final['run_count'])->toBe(1)
            ->and($runId)->not->toBe('');

        if ($expectedChannel === 'laravel-ai') {
            $context = $app->make(CallContextResolver::class)->resolve(Request::create(
                '/office/channel/events', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$writerToken],
            ));
            expect($context)->toBeInstanceOf(CallContext::class);
            $runs = $app->make(Runs::class);
            $chat = $app->make(ChatWorkspace::class);
            if ($asyncPg) {
                $deadline = microtime(true) + 25.0;
                while (microtime(true) < $deadline) {
                    $eventTypes = array_column($chat->events($context, $chatSessionId, 0), 'type');
                    if (in_array('approval.required', $eventTypes, true)) {
                        break;
                    }
                    usleep(200_000);
                }
            } else {
                expect($runs->listSteps($context, $runId))->toBe([]);
                Bus::assertDispatched(ExecuteOfficeRun::class);
                $job = Bus::dispatched(ExecuteOfficeRun::class)->sole();
                $app->call([$job, 'handle']);
            }
            expect(array_column($chat->events($context, $chatSessionId, 0), 'type'))->toContain('approval.required');
            expect(array_column($chat->show($context, $chatSessionId)['tool_calls'], 'state'))->toContain('awaiting_approval');

            $otherDoc = (string) $seed['second_resource_id'];
            $unlinked = $runs->createOrFind($context, $expectedChannel, $unlinkEvt, $context->policyRevision, [
                'resource_id' => $doc,
                'session_id' => $extSession,
                'chat_session_id' => $chatSessionId,
            ]);
            foreach ([
                ['session_id' => $extSession, 'resource_id' => $otherDoc],
                ['session_id' => 'wrong-session', 'resource_id' => $doc],
            ] as $mismatch) {
                $response = channelChatReq($app, 'POST', '/office/channel/events', $writerToken, $mismatch + [
                    'event_id' => $unlinkEvt,
                    'phase' => 'final',
                ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
                expect($response->getStatusCode())->toBe(409)
                    ->and($chat->sessionBindingForRun($context, $unlinked->runId))->toBeNull();
            }
            expect(DB::table('stand_channel_inbox')->where('event_id', $unlinkEvt)->exists())->toBeFalse();
        }

        // Duplicate final event keeps 1 run_id and same chat_session_id without duplicating chat messages or events.
        $dupResp = channelChatReq($app, 'POST', '/office/channel/events', $writerToken, [
            'session_id' => $extSession,
            'event_id' => $finalEvt,
            'phase' => 'final',
            'resource_id' => $doc,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        $dup = json_decode((string) $dupResp->getContent(), true, 512, JSON_THROW_ON_ERROR);
        expect($dup['duplicate'])->toBeTrue()
            ->and($dup['chat_session_id'])->toBe($chatSessionId)
            ->and($dup['run_id'])->toBe($runId);

        $shownAfterFinal = json_decode((string) channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId, $writerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($shownAfterFinal['runs'])->toHaveCount(1)
            ->and($shownAfterFinal['runs'][0]['run_id'])->toBe($runId)
            ->and($shownAfterFinal['messages'])->toHaveCount(1);

        // 3. Reviewer approves proposal P; chat session & SSE tail receive settlement events.
        $proposals = json_decode((string) channelChatReq($app, 'GET', '/office/proposals?resource_id='.urlencode($doc), $reviewerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($proposals)->toHaveCount(1);
        $proposalId = (string) $proposals[0]['proposal_id'];
        $digest = (string) $proposals[0]['digest'];

        channelChatReq($app, 'POST', '/office/proposals/'.$proposalId.'/decision', $reviewerToken, [
            'digest' => $digest,
            'approved' => true,
        ], ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
        if ($asyncPg) {
            $deadline = microtime(true) + 25.0;
            while (microtime(true) < $deadline) {
                $committedCount = DB::table('v_ai_text_commands')->where('resource_id', $doc)->count();
                $shownProbe = json_decode((string) channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId, $writerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
                if ($committedCount === 1 && ($shownProbe['last_seq'] ?? 0) >= 4) {
                    break;
                }
                usleep(200_000);
            }
        }
        expect(DB::table('v_ai_text_commands')->where('resource_id', $doc)->count())->toBe(1);

        $shownAfterCommit = json_decode((string) channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId, $writerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($shownAfterCommit['last_seq'])->toBeGreaterThanOrEqual(4);

        // Verify SSE endpoint streams approval.required + tool.committed using bounded resolver reads (no 25s sleep).
        $innerResolver = $app->make(CallContextResolver::class);
        $app->instance(CallContextResolver::class, new class($innerResolver) implements CallContextResolver
        {
            private int $reads = 0;

            public function __construct(private readonly CallContextResolver $inner) {}

            public function resolve(Request $request): ?CallContext
            {
                if (str_contains($request->getPathInfo(), '/events') && $this->reads++ >= 2) {
                    return null;
                }

                return $this->inner->resolve($request);
            }
        });
        $sseResp = channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId.'/events?cursor=0', $writerToken);
        expect($sseResp)->toBeInstanceOf(StreamedResponse::class);
        ob_start();
        try {
            ob_start();
            try {
                $sseResp->sendContent();
            } finally {
                ob_end_flush();
            }
            $collected = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $app->instance(CallContextResolver::class, $innerResolver);
        expect($collected)->toContain('event: approval.required')
            ->and($collected)->toContain('event: tool.committed');

        // 4. Revoke writer access -> chat session show & SSE replay redact cleanly.
        $driveOwner = StandOwner::query()->findOrFail($seed['owner_id']);
        $writerActor = StandActor::query()->findOrFail($seed['writer_user_id']);
        $drive = Vaulter::drive($driveOwner)->asSystem('revoke-channel-chat');
        foreach ($drive->access()->grants($drive->root()->id) as $grant) {
            if ((string) $grant->subject->id === (string) $writerActor->getKey()) {
                $drive->access()->revoke($drive->root()->id, $grant->id);
            }
        }
        $redacted = json_decode((string) channelChatReq($app, 'GET', '/office/chat/sessions/'.$chatSessionId, $writerToken)->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        expect($redacted['messages'][0]['hidden'] ?? false)->toBeTrue();
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
})->with(static function (): array {
    if (getenv('STAND_PG_ASYNC') === '1' || getenv('DB_CONNECTION') === 'pgsql') {
        $profile = getenv('STAND_PROFILE') ?: 'ai-text';
        $channel = $profile === 'ai-agentools' ? 'agentools' : 'laravel-ai';

        return ["{$profile} profile" => [$profile, $channel]];
    }

    return [
        'laravel-ai profile' => ['ai-text', 'laravel-ai'],
        'agentools profile' => ['ai-agentools', 'agentools'],
    ];
});
