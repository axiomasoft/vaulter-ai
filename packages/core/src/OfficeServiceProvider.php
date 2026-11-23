<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Http\NullCallContextResolver;
use Vaulter\Ai\Core\Operations\Describe;
use Vaulter\Ai\Core\Operations\DispatchOfficeOperation;
use Vaulter\Ai\Core\Operations\Read;
use Vaulter\Ai\Core\Operations\RunStateReducer;
use Vaulter\Ai\Core\Operations\Search;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\ConfigHostPolicy;
use Vaulter\Ai\Core\Persistence\ConfigProviderSecretStore;
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
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Core\Workspace\ChatTurn;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;
use Vaulter\Ai\Core\Workspace\ProposalDiffBuilder;

/**
 * Service provider for axiomasoft/vaulter-ai-core.
 * Keeps singletons free of request/actor/tenant state for Octane safety.
 */
final class OfficeServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai.php', 'vaulter-ai');

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(WireValidator::class);
        $this->app->singleton(WireErrorMapper::class);
        $this->app->singleton(OfficeRegistry::class);

        $this->app->singletonIf(DefaultTransactionRunner::class);
        $this->app->singletonIf(TransactionRunner::class, DefaultTransactionRunner::class);

        $this->app->singleton(OfficeDatabase::class, static fn (Container $app): OfficeDatabase => new OfficeDatabase(
            $app->make(DatabaseManager::class),
            $app->make(ConfigRepository::class),
            $app->make(TransactionRunner::class),
        ));

        $this->app->singleton(PayloadCipher::class, static fn (Container $app): PayloadCipher => new PayloadCipher(
            $app->make(ConfigRepository::class),
        ));

        $this->app->singleton(OfficeCoreSchema::class);
        $this->app->singleton(SearchPages::class);
        $this->app->singleton(ReadTokens::class);
        $this->app->singleton(Proposals::class);
        $this->app->singleton(OwnerRoutes::class);
        $this->app->singleton(Runs::class);

        $this->app->singleton(HostPolicy::class, ConfigHostPolicy::class);
        $this->app->singletonIf(ProposalApprovalPolicy::class, DefaultProposalApprovalPolicy::class);
        $this->app->singletonIf(ConfigProviderSecretStore::class);
        $this->app->singletonIf(ProviderSecretStore::class, ConfigProviderSecretStore::class);
        $this->app->singletonIf(RegistryCommandWriteCapability::class);
        $this->app->singletonIf(CommandWriteCapability::class, RegistryCommandWriteCapability::class);
        $this->app->singleton(CallContextResolver::class, NullCallContextResolver::class);

        $this->app->singleton(Search::class);
        $this->app->singleton(Read::class);
        $this->app->singleton(Describe::class);
        $this->app->singleton(Office::class, DispatchOfficeOperation::class);
        $this->app->singleton(RunStateReducer::class);
        $this->app->singleton(StepCommitCoordinator::class);
        $this->app->singleton(ProposalDiffBuilder::class);
        $this->app->singleton(ResolveProposal::class);
        $this->app->singleton(OfficeWorkspaceService::class);
        $this->app->singleton(ToolCallLedger::class);
        $this->app->singleton(ChatWorkspace::class);
        $this->app->singleton(ChatTools::class);
        $this->app->singleton(ChatTurn::class);
        $this->app->singleton(ChannelChatBridge::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai.php' => $this->app->configPath('vaulter-ai.php'),
        ], 'vaulter-ai-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/workspace.php');

        $this->app->booted(function (): void {
            $this->app->make(OfficeRegistry::class)->freeze();
        });
    }
}
