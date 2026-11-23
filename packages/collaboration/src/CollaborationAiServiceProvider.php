<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration;

use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Writing\LiveTextOwner;
use Vaulter\Kernel;

final class CollaborationAiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(LiveTextCommandOwner::class);
        $this->app->alias(LiveTextCommandOwner::class, LiveTextOwner::class);
    }

    public function boot(): void
    {
        $registry = $this->app->make(OfficeRegistry::class);
        if (! $registry->isFrozen() && $this->app->make(Kernel::class)->isActive('collaboration')) {
            $registry->registerOwner(ReplaceExact::COMMAND, 'journal', $this->app->make(LiveTextCommandOwner::class));
        }
    }
}
