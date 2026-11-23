#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Optional standalone live-model smoke runner for Laravel AI (separate from deterministic CI acceptance).
 * Exits 0 cleanly with an explicit notice when live credentials are not provided unless --require-key is passed.
 * When VAULTER_AI_LIVE_TEST=1 and OPENAI_API_KEY are set, boots an isolated Laravel container, invokes
 * LaravelOfficeExecutor against the live provider, verifies the committed receipt, and reports token usage & cost.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\OfficeServiceProvider;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelAiServiceProvider;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Documents\VaulterDocumentsServiceProvider;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\VaulterDriveServiceProvider;
use Vaulter\Media\VaulterMediaServiceProvider;
use Vaulter\Schema\ModuleSchemas;
use Vaulter\VaulterServiceProvider;

$args = array_slice($argv, 1);
$requireKey = in_array('--require-key', $args, true);

$liveOptIn = getenv('VAULTER_AI_LIVE_TEST') === '1';
$rawKey = getenv('OPENAI_API_KEY');
$apiKey = is_string($rawKey) ? trim($rawKey) : '';
$configured = $liveOptIn && $apiKey !== '';

if (! $configured) {
    $payload = [
        'mode' => 'live-smoke',
        'configured' => false,
        'executed' => false,
        'ok' => ! $requireKey,
        'status' => $requireKey ? 'error' : 'skipped',
        'reason' => ! $liveOptIn
            ? 'VAULTER_AI_LIVE_TEST=1 is required to enable live model invocation.'
            : 'OPENAI_API_KEY is required for live model invocation.',
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($requireKey ? 1 : 0);
}

$provider = getenv('VAULTER_AI_PROVIDER');
$providerName = is_string($provider) && trim($provider) !== '' ? trim($provider) : 'openai';
$model = getenv('VAULTER_AI_MODEL');
$modelName = is_string($model) && trim($model) !== '' ? trim($model) : 'gpt-4o-mini';

$rawInCost = getenv('VAULTER_AI_INPUT_COST_PER_1M');
$rawOutCost = getenv('VAULTER_AI_OUTPUT_COST_PER_1M');
$inCostPer1M = is_string($rawInCost) && is_numeric($rawInCost) ? (float) $rawInCost : 0.15;
$outCostPer1M = is_string($rawOutCost) && is_numeric($rawOutCost) ? (float) $rawOutCost : 0.60;

if (! class_exists('LiveSmokeActor')) {
    final class LiveSmokeActor extends User
    {
        protected $table = 'users';

        protected $guarded = [];

        public $timestamps = false;
    }
}
if (! class_exists('LiveSmokeOwner')) {
    final class LiveSmokeOwner extends Model
    {
        protected $table = 'ai_test_owners';

        protected $guarded = [];

        public $timestamps = false;
    }
}

$baseDir = sys_get_temp_dir().'/vaulter-ai-live-smoke-'.bin2hex(random_bytes(4));
mkdir($baseDir.'/storage', 0775, true);

try {
    $app = new Application($baseDir);
    $app->detectEnvironment(static fn (): string => 'testing');
    $app->instance('env', 'testing');
    $app->singleton(HttpKernelContract::class, HttpKernel::class);
    $app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);
    $app->singleton(ExceptionHandlerContract::class, ExceptionHandler::class);
    $app->useDatabasePath($baseDir.'/database');
    $app->instance('config', new ConfigRepository([
        'app' => [
            'name' => 'VaulterAiLiveSmoke',
            'env' => 'testing',
            'key' => 'base64:'.base64_encode(random_bytes(32)),
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
        'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $baseDir.'/storage']]],
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
        VaulterServiceProvider::class,
        VaulterMediaServiceProvider::class,
        VaulterDriveServiceProvider::class,
        VaulterDocumentsServiceProvider::class,
        OfficeServiceProvider::class,
        DocumentsAiServiceProvider::class,
        AiServiceProvider::class,
        LaravelAiServiceProvider::class,
    ] as $providerClass) {
        $app->register($providerClass);
    }
    $app->boot();

    $app['config']->set('vaulter.ids.strategy', 'uuid7');
    $app['config']->set('vaulter.ids.host_keys', 'bigint');
    $app['config']->set('vaulter-drive.access.permission_driver', 'database');
    $app['config']->set('vaulter-documents.tiptap.block_ids', true);
    $app['config']->set('vaulter-ai-documents.default_tenant_id', '61');
    $app['config']->set('vaulter-ai-documents.default_project_scope', 'default');
    $app['config']->set('vaulter-ai-laravel-ai.default_model', $modelName);
    $app['config']->set('vaulter-ai-laravel-ai.provider_connections', ['office_model' => $providerName]);
    $providerConfig = [
        'driver' => $providerName,
        'key' => $apiKey,
    ];
    $providerUrl = getenv('VAULTER_AI_PROVIDER_URL') ?: getenv('OPENAI_BASE_URL');
    if (is_string($providerUrl) && trim($providerUrl) !== '') {
        $providerConfig['url'] = trim($providerUrl);
    }
    $app['config']->set('ai.default', $providerName);
    $app['config']->set('ai.providers.'.$providerName, $providerConfig);
    $app['config']->set('vaulter-ai.secret_store.connections', [
        'office_model' => $providerConfig + ['model' => $modelName],
    ]);
    $app['config']->set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'live-smoke-rev-1',
    ]);

    Schema::create('users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
    });
    Schema::create('ai_test_owners', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    $coreDir = dirname(__DIR__, 2).'/core';
    $docsDir = dirname(__DIR__, 2).'/documents';
    $migrationPaths = $app->make(ModuleSchemas::class)->migrationPaths();
    $migrationPaths[] = $coreDir.'/database/migrations';
    $migrationPaths[] = $docsDir.'/database/migrations';
    $migrator = $app->make('migrator');
    $migrator->getRepository()->createRepository();
    $migrator->run($migrationPaths);

    $actor = LiveSmokeActor::query()->create(['name' => 'Smoke Editor', 'email' => 'smoke@example.test']);
    $owner = LiveSmokeOwner::query()->create(['name' => 'Smoke Workspace']);
    $drive = Vaulter::drive($owner)->asSystem('live-smoke-seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));

    $node = Documents::in($drive)->create('Live Smoke Contract', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-42', 'level' => 2], 'content' => [['type' => 'text', 'text' => '4.2 Payment Term']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                ['type' => 'text', 'text' => 'Pay within '],
                ['type' => 'text', 'text' => '15', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' working days.'],
            ]],
        ],
    ]);

    $context = new CallContext(
        tenantId: '61',
        projectScope: 'default',
        effectiveSubject: 'user/'.$actor->getKey(),
        profile: 'documents',
        policyRevision: 'live-smoke-rev-1',
        driveId: $drive->snapshot()->id,
        actor: $actor,
    );

    $app->instance(RunContextResolver::class, new class($context) implements RunContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(string $tenantId, string $runId): ?CallContext
        {
            return $this->ctx->tenantId === $tenantId ? $this->ctx : null;
        }
    });

    $observedUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
    ];
    $observedMeta = [
        'provider' => $providerName,
        'model' => $modelName,
    ];
    $promptInvocations = 0;

    Event::listen(AgentPrompted::class, static function (AgentPrompted $event) use (&$observedUsage, &$observedMeta, &$promptInvocations): void {
        $promptInvocations++;
        $usage = $event->response->usage;
        $observedUsage['input_tokens'] += $usage->inputTokens;
        $observedUsage['output_tokens'] += $usage->outputTokens;
        $observedUsage['cache_read_input_tokens'] += $usage->cacheReadInputTokens ?? 0;
        $observedUsage['cache_write_input_tokens'] += $usage->cacheWriteInputTokens ?? 0;
        $observedUsage['reasoning_tokens'] += $usage->reasoningTokens ?? 0;
        if ($event->response->meta->provider !== null) {
            $observedMeta['provider'] = $event->response->meta->provider;
        }
        if ($event->response->meta->model !== null) {
            $observedMeta['model'] = $event->response->meta->model;
        }
    });

    /** @var LaravelOfficeExecutor $executor */
    $executor = $app->make(LaravelOfficeExecutor::class);
    /** @var Runs $runs */
    $runs = $app->make(Runs::class);
    $sourceEventId = 'live-smoke-'.bin2hex(random_bytes(6));

    $startedAt = hrtime(true);
    $executor->dispatch($context, [
        'channel' => 'laravel-ai',
        'source_event_id' => $sourceEventId,
        'resource_id' => $node->id,
        'prompt' => "On resource {$node->id}, read paragraph block p42, prepare text.replace_exact@1 replacing '15' with '30', commit the proposal, and verify the committed receipt in at_commit mode.",
    ]);
    $elapsedMs = max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000));

    $run = $runs->createOrFind($context, 'laravel-ai', $sourceEventId, 'live-smoke-rev-1', ['prompt' => '']);
    $steps = $runs->listSteps($context, $run->runId);

    $commitReceipt = null;
    $verifyOutcome = null;
    $verifyReceipt = null;
    foreach ($steps as $step) {
        if ($step->operation === OfficeOperation::Commit && $step->state === 'finished' && $step->receiptId !== null) {
            $commitReceipt = $step->receiptId;
        }
        if ($step->operation === OfficeOperation::Verify) {
            $result = is_array($step->compactResult['result'] ?? null) ? $step->compactResult['result'] : [];
            $verifyOutcome = $result['outcome'] ?? null;
            $verifyReceipt = $result['receipt_id'] ?? $step->receiptId;
        }
    }

    $ownerDb = $app->make(VaulterDatabase::class)->connection();
    $ownerCommandCount = $ownerDb->table('v_ai_text_commands')->where('resource_id', $node->id)->count();
    $ownerReceipt = $ownerDb->table('v_ai_text_commands')->where('resource_id', $node->id)->value('receipt_id');

    // Run live chat streaming + tool turn smoke against the same real provider via ChatWorkspace + ChatTurn + LlmPort.
    $chatNode = Documents::in($drive)->create('Live Smoke Chat Contract', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-42', 'level' => 2], 'content' => [['type' => 'text', 'text' => '4.2 Payment Term']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                ['type' => 'text', 'text' => 'Pay within '],
                ['type' => 'text', 'text' => '15', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' working days.'],
            ]],
        ],
    ]);
    /** @var ChatWorkspace $chat */
    $chat = $app->make(ChatWorkspace::class);
    /** @var ChatTurn $chatTurn */
    $chatTurn = $app->make(ChatTurn::class);
    /** @var LlmPort $llmPort */
    $llmPort = $app->make(LlmPort::class);
    /** @var ProviderSecretStore $secrets */
    $secrets = $app->make(ProviderSecretStore::class);

    $chatSessionId = $chat->open($context);
    $chatRunId = $chat->post(
        $context,
        $chatSessionId,
        'chat-live-smoke-1',
        "Read paragraph p42 on resource {$chatNode->id}, replace '15' with '45' using text.replace_exact@1, commit, and verify with mode at_commit.",
        [$chatNode->id],
        ['kind' => 'text_block', 'resource_id' => $chatNode->id, 'block_id' => 'p42', 'excerpt' => 'Pay within 15 working days.'],
    );
    $chatDelay = $chatTurn->execute($context, $chatRunId, $llmPort);
    $chatRun = $runs->find($context, $chatRunId);
    $chatEvents = $chat->events($context, $chatSessionId, 0);
    $chatEventTypes = array_values(array_unique(array_column($chatEvents, 'type')));
    $chatOwnerCount = $ownerDb->table('v_ai_text_commands')->where('resource_id', $chatNode->id)->count();
    $chatOwnerReceipt = $ownerDb->table('v_ai_text_commands')->where('resource_id', $chatNode->id)->value('receipt_id');
    $chatEventsJson = json_encode($chatEvents, JSON_THROW_ON_ERROR);
    $chatLeakedSecret = str_contains($chatEventsJson, $apiKey);
    $chatProviderStatus = $secrets->status('office_model');

    $costUsd = round(
        ($observedUsage['input_tokens'] / 1_000_000) * $inCostPer1M
        + ($observedUsage['output_tokens'] / 1_000_000) * $outCostPer1M,
        8,
    );

    $ok = $promptInvocations >= 1
        && $run->state === 'completed'
        && $ownerCommandCount === 1
        && is_string($commitReceipt)
        && $commitReceipt !== ''
        && $ownerReceipt === $commitReceipt
        && $verifyOutcome === 'passed'
        && $verifyReceipt === $commitReceipt
        && $chatDelay === null
        && $chatRun?->state === 'completed'
        && $chatOwnerCount === 1
        && is_string($chatOwnerReceipt)
        && $chatOwnerReceipt !== ''
        && in_array('tool.committed', $chatEventTypes, true)
        && in_array('tool.verified', $chatEventTypes, true)
        && in_array('run.finished', $chatEventTypes, true)
        && ! $chatLeakedSecret;

    $report = [
        'mode' => 'live-smoke',
        'configured' => true,
        'executed' => true,
        'ok' => $ok,
        'provider' => $observedMeta['provider'],
        'model' => $observedMeta['model'],
        'prompt_invocations' => $promptInvocations,
        'run_id' => $run->runId,
        'run_state' => $run->state,
        'step_count' => count($steps),
        'owner_command_count' => $ownerCommandCount,
        'receipt_id' => $commitReceipt,
        'verify_receipt_id' => $verifyReceipt,
        'verify_outcome' => $verifyOutcome,
        'chat' => [
            'session_id' => $chatSessionId,
            'run_id' => $chatRunId,
            'run_state' => $chatRun?->state,
            'event_types' => $chatEventTypes,
            'owner_command_count' => $chatOwnerCount,
            'receipt_id' => $chatOwnerReceipt,
            'provider_status' => $chatProviderStatus,
            'secret_leaked' => $chatLeakedSecret,
        ],
        'usage' => $observedUsage + [
            'total_tokens' => $observedUsage['input_tokens'] + $observedUsage['output_tokens'],
        ],
        'cost_usd' => $costUsd,
        'currency' => 'USD',
        'latency_ms' => $elapsedMs,
    ];

    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $failure) {
    $safeError = str_replace($apiKey, '[REDACTED]', $failure->getMessage());
    echo json_encode([
        'mode' => 'live-smoke',
        'configured' => true,
        'executed' => true,
        'ok' => false,
        'provider' => $providerName,
        'model' => $modelName,
        'error_class' => $failure::class,
        'error' => $safeError,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    if (is_dir($baseDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($baseDir);
    }
}
