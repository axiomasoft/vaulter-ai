<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Support\ServiceProvider;
use Override;
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

        $this->app->singleton(LaravelOfficeExecutor::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-laravel-ai.php' => $this->app->configPath('vaulter-ai-laravel-ai.php'),
        ], 'vaulter-ai-laravel-ai-config');

        $registry = $this->app->make(OfficeRegistry::class);
        if (! $registry->isFrozen()) {
            $registry->registerExecutor($this->app->make(LaravelOfficeExecutor::class));
        }
    }
}
