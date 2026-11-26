<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Kernel;

final class CollaborationAiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->scoped(LiveTextCommandOwner::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(OfficeRegistry::class, static function (OfficeRegistry $registry, Container $app): void {
            if (! $app->make(Kernel::class)->isActive('collaboration')) {
                return;
            }
            $registry->registerOwner(ReplaceExact::COMMAND, 'journal', $app->make(LiveTextCommandOwner::class));
        });
    }
}
