<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Sheets\Commands\FillFormula;
use Vaulter\Ai\Sheets\Host\SheetDocumentAccess;
use Vaulter\Ai\Sheets\Host\SheetResourceDirectory;
use Vaulter\Ai\Sheets\Persistence\SheetsTransactionRunner;
use Vaulter\Ai\Sheets\Read\SheetReader;
use Vaulter\Ai\Sheets\Writing\SheetCommandOwner;
use Vaulter\Laravel\VaulterPluginRegistrar;

/**
 * Service provider for axiomasoft/vaulter-ai-sheets.
 * Registers SheetReader, FillFormula (sheet.fill_formula@1), and SheetCommandOwner.
 */
final class SheetsAiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai-sheets.php', 'vaulter-ai-sheets');

        $this->app->scoped(SheetDocumentAccess::class);
        $this->app->scoped(SheetResourceDirectory::class);
        $this->app->scoped(SheetsTransactionRunner::class);
        $this->app->scoped(SheetReader::class);
        $this->app->scoped(FillFormula::class);
        $this->app->scoped(SheetCommandOwner::class);

        // Keep VaulterResourceDirectory/VaulterTransactionRunner authoritative whenever vaulter-ai-documents is registered.
        $this->app->scopedIf(ResourceDirectory::class, SheetResourceDirectory::class);
        $documentsTxClass = 'Vaulter\\Ai\\Documents\\Persistence\\VaulterTransactionRunner';
        if (! $this->app->bound($documentsTxClass)) {
            $this->app->scoped(TransactionRunner::class, SheetsTransactionRunner::class);
        }

        if (class_exists(VaulterPluginRegistrar::class)) {
            VaulterPluginRegistrar::register($this->app, AiSheetsModule::class, dirname(__DIR__).'/composer.json');
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-sheets.php' => $this->app->configPath('vaulter-ai-sheets.php'),
        ], 'vaulter-ai-sheets-config');

        $this->callAfterResolving(OfficeRegistry::class, static function (OfficeRegistry $registry, Container $app): void {
            if ($registry->isFrozen()) {
                return;
            }
            $registry->registerReader($app->make(SheetReader::class));
            $registry->registerCommand($app->make(FillFormula::class));
            $registry->registerOwner(FillFormula::COMMAND, 'snapshot', $app->make(SheetCommandOwner::class));
        });
    }
}
