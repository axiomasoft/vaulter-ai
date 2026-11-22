<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Console\RebuildTextIndex;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Host\VaulterResourceDirectory;
use Vaulter\Ai\Documents\Persistence\TextCommands;
use Vaulter\Ai\Documents\Persistence\VaulterTransactionRunner;
use Vaulter\Ai\Documents\Projection\DefaultDocumentScopeResolver;
use Vaulter\Ai\Documents\Projection\IndexText;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Projection\TextProjectionOutboxConsumer;
use Vaulter\Ai\Documents\Projection\TextProjectionReader;
use Vaulter\Ai\Documents\Read\TextReader;
use Vaulter\Ai\Documents\Schema\AiDocumentsSchema;
use Vaulter\Ai\Documents\Writing\SingleTextOwner;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Events\Documents\DocumentCreated;
use Vaulter\Documents\Events\Revisions\DocumentRestored;
use Vaulter\Drive\Events\Nodes\NodeRestored;
use Vaulter\Laravel\VaulterPluginRegistrar;

/**
 * Service provider for axiomasoft/vaulter-ai-documents.
 * Registers AI Documents migrations, projector listeners, TextReader, and ReplaceExact descriptor.
 */
final class DocumentsAiServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vaulter-ai-documents.php', 'vaulter-ai-documents');

        $this->app->singleton(RichTextBlockExtractor::class);
        $this->app->singleton(DefaultDocumentScopeResolver::class);
        $this->app->singleton(DocumentScopeResolver::class, DefaultDocumentScopeResolver::class);

        $this->app->singleton(AiDocumentsSchema::class);
        $this->app->singleton(VaulterTransactionRunner::class);
        $this->app->singleton(TransactionRunner::class, VaulterTransactionRunner::class);
        $this->app->singleton(TextCommands::class);
        $this->app->singleton(IndexText::class);
        $this->app->singleton(TextProjectionOutboxConsumer::class);
        $this->app->singleton(TextProjectionReader::class);
        $this->app->singleton(TextReader::class);
        $this->app->singleton(ReplaceExact::class);

        $this->app->singleton(ResourceDirectory::class, VaulterResourceDirectory::class);

        if (class_exists(VaulterPluginRegistrar::class)) {
            VaulterPluginRegistrar::register($this->app, AiDocumentsModule::class, dirname(__DIR__).'/composer.json');
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/vaulter-ai-documents.php' => $this->app->configPath('vaulter-ai-documents.php'),
        ], 'vaulter-ai-documents-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildTextIndex::class]);
        }

        $registry = $this->app->make(OfficeRegistry::class);
        if (! $registry->isFrozen()) {
            $registry->registerReader($this->app->make(TextReader::class));
            $registry->registerCommand($this->app->make(ReplaceExact::class));
            $registry->registerOwner(ReplaceExact::COMMAND, 'snapshot', $this->app->make(SingleTextOwner::class));
        }

        $this->registerProjectionListeners();
    }

    private function registerProjectionListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);
        $indexer = $this->app->make(IndexText::class);
        $config = $this->app->make(ConfigRepository::class);

        $autoEnabled = static fn (): bool => (bool) $config->get('vaulter-ai-documents.projection.auto_index', true);

        $events->listen(DocumentCreated::class, static function (DocumentCreated $event) use ($indexer, $autoEnabled): void {
            if ($autoEnabled()) {
                $indexer->handleDocumentCreated($event);
            }
        });

        $events->listen(DocumentContentUpdated::class, static function (DocumentContentUpdated $event) use ($indexer, $autoEnabled): void {
            if ($autoEnabled()) {
                $indexer->handleDocumentContentUpdated($event);
            }
        });

        $events->listen(DocumentRestored::class, static function (DocumentRestored $event) use ($indexer, $autoEnabled): void {
            if ($autoEnabled()) {
                $indexer->handleDocumentRestored($event);
            }
        });

        $events->listen(NodeRestored::class, static function (NodeRestored $event) use ($indexer, $autoEnabled): void {
            if ($autoEnabled()) {
                $indexer->handleNodeRestored($event);
            }
        });
    }
}
