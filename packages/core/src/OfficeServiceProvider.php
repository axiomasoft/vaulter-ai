<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Console\RotateProviderSecrets;
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
            $this->commands([RotateProviderSecrets::class]);
        }

        $this->app->booted(function (): void {
            $this->callAfterResolving(OfficeRegistry::class, static fn (OfficeRegistry $registry) => $registry->freeze());
        });
    }
}
