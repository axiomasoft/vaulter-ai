<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Contracts\ToolPort;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Service provider for axiomasoft/vaulter-ai-agentools.
 * Opt-in adapter wiring OfficeToolPort, OfficeToolDescriptorRegistry, ActionKeyMap, and AgenToolsLlmPort.
 */
final class AgenToolsServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai-agentools.php', 'vaulter-ai-agentools');

        $this->app->singleton(OfficeToolDescriptorRegistry::class);
        $this->app->singleton(ToolDescriptorRegistry::class, OfficeToolDescriptorRegistry::class);
        $this->app->singleton(ActionKeyMap::class);
        $this->app->singleton(OfficeToolPort::class);
        $this->app->singleton(ToolPort::class, OfficeToolPort::class);
        $this->app->singleton(AgenToolsOfficeExecutor::class);
        $this->app->singleton(AgenToolsLlmPort::class);
        $this->app->singletonIf(LlmPort::class, AgenToolsLlmPort::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-agentools.php' => $this->app->configPath('vaulter-ai-agentools.php'),
        ], 'vaulter-ai-agentools-config');

        $registry = $this->app->make(OfficeRegistry::class);
        if (! $registry->isFrozen()) {
            $registry->registerLazyExecutor(
                AgenToolsOfficeExecutor::KEY,
                fn (): AgenToolsOfficeExecutor => $this->app->make(AgenToolsOfficeExecutor::class),
            );
        }
    }
}
