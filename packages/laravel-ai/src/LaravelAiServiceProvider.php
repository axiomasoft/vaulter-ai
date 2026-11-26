<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Service provider for axiomasoft/vaulter-ai-laravel-ai.
 * Registers LaravelOfficeExecutor into OfficeRegistry without adding external runtime dependencies.
 */
final class LaravelAiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai-laravel-ai.php', 'vaulter-ai-laravel-ai');

        $this->app->scoped(LaravelOfficeExecutor::class);
        $this->app->scoped(LaravelAiLlmPort::class);
        $this->app->scopedIf(LlmPort::class, LaravelAiLlmPort::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-laravel-ai.php' => $this->app->configPath('vaulter-ai-laravel-ai.php'),
        ], 'vaulter-ai-laravel-ai-config');

        $this->callAfterResolving(OfficeRegistry::class, static function (OfficeRegistry $registry, Container $app): void {
            if ($registry->isFrozen()) {
                return;
            }
            $registry->registerLazyExecutor(
                LaravelOfficeExecutor::KEY,
                static fn (): LaravelOfficeExecutor => $app->make(LaravelOfficeExecutor::class),
            );
        });
    }
}
