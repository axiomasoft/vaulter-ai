<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Override;
use PDO;
use Pdo\Pgsql;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Console\PruneOfficeData;
use Vaulter\Ai\Core\Console\RecoverOfficeRuns;
use Vaulter\Ai\Core\Console\RotateProviderSecrets;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Http\IdlePollBackoff;
use Vaulter\Ai\Core\Http\NullCallContextResolver;
use Vaulter\Ai\Core\Operations\Describe;
use Vaulter\Ai\Core\Operations\DispatchOfficeOperation;
use Vaulter\Ai\Core\Operations\Read;
use Vaulter\Ai\Core\Operations\RunStateReducer;
use Vaulter\Ai\Core\Operations\Search;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\ConfigHostPolicy;
use Vaulter\Ai\Core\Persistence\ConfigProviderSecretStore;
use Vaulter\Ai\Core\Persistence\DatabaseProviderSecretStore;
use Vaulter\Ai\Core\Persistence\DefaultProposalApprovalPolicy;
use Vaulter\Ai\Core\Persistence\DefaultTransactionRunner;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\PayloadCipher;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\ReadTokens;
use Vaulter\Ai\Core\Persistence\RegistryCommandWriteCapability;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\SearchPages;
use Vaulter\Ai\Core\Persistence\SystemClock;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Schema\OfficeCoreSchema;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;
use Vaulter\Ai\Core\Workspace\ChatEventWakeup;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;
use Vaulter\Ai\Core\Workspace\ProposalDiffBuilder;

/**
 * Service provider for axiomasoft/vaulter-ai-core.
 * Registers Office services for the current request or queue job.
 */
final class OfficeServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai.php', 'vaulter-ai');

        $this->app->scoped(Clock::class, SystemClock::class);
        $this->app->scoped(WireValidator::class);
        $this->app->scoped(WireErrorMapper::class);
        $this->app->scoped(OfficeRegistry::class);

        $this->app->scopedIf(DefaultTransactionRunner::class);
        $this->app->scopedIf(TransactionRunner::class, DefaultTransactionRunner::class);

        $this->app->scoped(OfficeDatabase::class, static fn (Container $app): OfficeDatabase => new OfficeDatabase(
            $app->make(DatabaseManager::class),
            $app->make(ConfigRepository::class),
            $app->make(TransactionRunner::class),
        ));

        $this->app->scoped(PayloadCipher::class, static fn (Container $app): PayloadCipher => new PayloadCipher(
            $app->make(ConfigRepository::class),
        ));

        $this->app->scoped(OfficeCoreSchema::class);
        $this->app->scoped(SearchPages::class);
        $this->app->scoped(ReadTokens::class);
        $this->app->scoped(Proposals::class);
        $this->app->scoped(OwnerRoutes::class);
        $this->app->scoped(Runs::class);

        $this->app->scoped(HostPolicy::class, ConfigHostPolicy::class);
        $this->app->scopedIf(ProposalApprovalPolicy::class, DefaultProposalApprovalPolicy::class);
        $this->app->scopedIf(ConfigProviderSecretStore::class);
        $this->app->scopedIf(DatabaseProviderSecretStore::class);
        $this->app->scopedIf(ProviderSecretStore::class, DatabaseProviderSecretStore::class);
        $this->app->scopedIf(RegistryCommandWriteCapability::class);
        $this->app->scopedIf(CommandWriteCapability::class, RegistryCommandWriteCapability::class);
        $this->app->scoped(CallContextResolver::class, NullCallContextResolver::class);

        $this->app->scoped(Search::class);
        $this->app->scoped(Read::class);
        $this->app->scoped(Describe::class);
        $this->app->scoped(Office::class, DispatchOfficeOperation::class);
        $this->app->scoped(RunStateReducer::class);
        $this->app->scoped(StepCommitCoordinator::class);
        $this->app->scoped(ProposalDiffBuilder::class);
        $this->app->scoped(ResolveProposal::class);
        $this->app->scoped(OfficeWorkspaceService::class);
        $this->app->scoped(ToolCallLedger::class);
        $this->app->scopedIf(ChatEventWakeup::class, static fn (Container $app): ChatEventWakeup => self::chatWakeup(
            $app->make(OfficeDatabase::class),
            $app->make(ConfigRepository::class),
            $app->make(HttpFactory::class),
        ));
        $this->app->scoped(ChatWorkspace::class);
        $this->app->scoped(ChatTools::class);
        $this->app->scoped(ChatTurn::class);
        $this->app->scoped(ChannelChatBridge::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai.php' => $this->app->configPath('vaulter-ai.php'),
        ], 'vaulter-ai-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/workspace.php');

        if ($this->app->runningInConsole()) {
            $this->commands([RotateProviderSecrets::class, RecoverOfficeRuns::class, PruneOfficeData::class]);
        }

        $this->app->booted(function (): void {
            $this->callAfterResolving(OfficeRegistry::class, static fn (OfficeRegistry $registry) => $registry->freeze());
        });
    }

    private static function chatWakeup(OfficeDatabase $db, ConfigRepository $config, HttpFactory $http): ChatEventWakeup
    {
        // For optional Centrifugo >= 6.9, enable uni_http_stream and set the base URL,
        // HTTP API key and connection-token HMAC secret in vaulter-ai.chat.centrifugo.
        return new class($db, $config, $http) implements ChatEventWakeup
        {
            public function __construct(
                private readonly OfficeDatabase $db,
                private readonly ConfigRepository $config,
                private readonly HttpFactory $http,
            ) {}

            public function notify(string $sessionId): void
            {
                $channel = $this->channel($sessionId);
                if ($this->db->driver() === 'pgsql') {
                    $this->db->connection()->statement('SELECT pg_notify(?, ?)', [$channel, '']);
                }
                $broker = $this->broker();
                if ($broker !== null) {
                    $this->db->afterCommit(function () use ($broker, $channel): void {
                        try {
                            $this->http->createPendingRequest()->withToken($broker['api_key'], 'apikey')->timeout(1)
                                ->withoutRedirecting()->post($broker['url'].'/api/publish', [
                                    'channel' => $channel,
                                    'data' => ['wake' => true],
                                ]);
                        } catch (ConnectionException) {
                            // The database event remains available by cursor on reconnect.
                        }
                    });
                }
            }

            /** @return Closure(int): bool */
            public function subscribe(string $sessionId): Closure
            {
                $channel = $this->channel($sessionId);
                $broker = $this->broker();
                $wait = $broker === null ? null : $this->centrifugo($broker, $channel);
                if ($wait !== null) {
                    return $wait;
                }
                if ($this->db->driver() === 'sqlite') {
                    $backoff = new IdlePollBackoff;

                    return static function (int $timeout) use ($backoff): bool {
                        usleep(min($timeout * 1000, $backoff->after(0)));

                        return true;
                    };
                }
                if ($this->db->driver() !== 'pgsql') {
                    throw OfficeException::temporarilyUnavailable();
                }

                // The listener needs a dedicated, session-pinned connection to the writer.
                $config = $this->db->connection()->getConfig();
                if (! is_array($config)) {
                    throw OfficeException::temporarilyUnavailable();
                }
                $config['options'] = [PDO::ATTR_PERSISTENT => false] + (is_array($config['options'] ?? null) ? $config['options'] : []);
                $pdo = (new PostgresConnector)->connect($config);
                if (! $pdo instanceof Pgsql) {
                    throw OfficeException::temporarilyUnavailable();
                }
                $pdo->exec('LISTEN '.$channel);

                return static fn (int $timeout): bool => $pdo->getNotify(PDO::FETCH_ASSOC, $timeout) !== false;
            }

            private function channel(string $sessionId): string
            {
                return 'vaulter_ai_'.substr(hash('sha256', $this->db->table('ai_chat_sessions').':'.$sessionId), 0, 48);
            }

            /** @return array{url:string,api_key:string,token_secret:string}|null */
            private function broker(): ?array
            {
                $broker = $this->config->get('vaulter-ai.chat.centrifugo');
                if (! is_array($broker)) {
                    return null;
                }
                $url = $broker['url'] ?? null;
                $key = $broker['api_key'] ?? null;
                $secret = $broker['token_secret'] ?? null;
                if (! is_string($url) || ! is_string($key) || ! is_string($secret) || $key === '' || strlen($secret) < 32
                    || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('~\Ahttps?://~i', $url) !== 1) {
                    throw OfficeException::invalidInput('Invalid Centrifugo chat configuration.');
                }

                return ['url' => rtrim($url, '/'), 'api_key' => $key, 'token_secret' => $secret];
            }

            /**
             * @param  array{url:string,api_key:string,token_secret:string}  $broker
             * @return Closure(int): bool|null
             */
            private function centrifugo(#[\SensitiveParameter] array $broker, string $channel): ?Closure
            {
                $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
                $claims = json_encode(['sub' => $channel, 'exp' => time() + 60, 'channels' => [$channel]], JSON_THROW_ON_ERROR);
                $unsigned = $encode('{"alg":"HS256","typ":"JWT"}').'.'.$encode($claims);
                $token = $unsigned.'.'.$encode(hash_hmac('sha256', $unsigned, $broker['token_secret'], true));
                try {
                    $response = $this->http->createPendingRequest()->withOptions(['stream' => true, 'read_timeout' => 2])
                        ->connectTimeout(2)->timeout(2)->withoutRedirecting()
                        ->post($broker['url'].'/connection/uni_http_stream', ['token' => $token]);
                } catch (ConnectionException) {
                    return null;
                }

                $stream = $response->toPsrResponse()->getBody()->detach();
                if (! is_resource($stream)) {
                    return null;
                }
                $frame = $response->successful() ? fgets($stream, 8192) : false;
                $connected = is_string($frame) ? json_decode($frame, true) : null;
                $connect = is_array($connected) ? ($connected['connect'] ?? null) : null;
                $version = is_array($connect) ? ($connect['version'] ?? null) : null;
                $subs = is_array($connect) ? ($connect['subs'] ?? null) : null;
                if (! is_string($version) || preg_match('/^v?(\d+\.\d+\.\d+)/', $version, $parts) !== 1
                    || version_compare($parts[1], '6.9.0', '<') || ! is_array($subs) || ! isset($subs[$channel])) {
                    fclose($stream);

                    return null;
                }
                $buffer = '';

                return static function (int $timeout) use ($stream, $channel, &$buffer): bool {
                    $deadline = hrtime(true) + $timeout * 1_000_000;
                    do {
                        $left = (int) ceil(($deadline - hrtime(true)) / 1000);
                        if ($left < 1) {
                            return false;
                        }
                        stream_set_timeout($stream, intdiv($left, 1000000), $left % 1000000);
                        $chunk = fgets($stream, 8192);
                        if ($chunk === false && ! feof($stream)) {
                            return false;
                        }
                        if ($chunk === false || strlen($buffer) + strlen($chunk) >= 8192) {
                            throw OfficeException::temporarilyUnavailable();
                        }
                        $buffer .= $chunk;
                        if (! str_ends_with($buffer, "\n")) {
                            continue;
                        }
                        $frame = json_decode($buffer, true);
                        $buffer = '';
                        if (is_array($frame) && ($frame['channel'] ?? null) === $channel && isset($frame['pub'])) {
                            return true;
                        }
                        if (is_array($frame) && (isset($frame['disconnect']) || isset($frame['unsubscribe']))) {
                            throw OfficeException::temporarilyUnavailable();
                        }
                    } while (hrtime(true) < $deadline);

                    return false;
                };
            }
        };
    }
}
