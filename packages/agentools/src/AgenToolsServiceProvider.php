<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\ToolDescriptorRegistry;
use AgenTools\Contracts\ToolPort;
use Illuminate\Contracts\Container\Container;
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

        $this->app->scoped(OfficeToolDescriptorRegistry::class);
        $this->app->scoped(ToolDescriptorRegistry::class, OfficeToolDescriptorRegistry::class);
        $this->app->scoped(ActionKeyMap::class);
        $this->app->scoped(OfficeToolPort::class);
        $this->app->scoped(ToolPort::class, OfficeToolPort::class);
        $this->app->scoped(AgenToolsOfficeExecutor::class);
        $this->app->scoped(AgenToolsLlmPort::class);
        $this->app->scopedIf(LlmPort::class, AgenToolsLlmPort::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-agentools.php' => $this->app->configPath('vaulter-ai-agentools.php'),
        ], 'vaulter-ai-agentools-config');

        $this->callAfterResolving(OfficeRegistry::class, static function (OfficeRegistry $registry, Container $app): void {
            $registry->registerLazyExecutor(
                AgenToolsOfficeExecutor::KEY,
                static fn (): AgenToolsOfficeExecutor => $app->make(AgenToolsOfficeExecutor::class),
            );
        });
    }
}
