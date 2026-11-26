<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Override;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\ProposalSuggestionPort;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Persistence\RegistryCommandWriteCapability;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\Commands\DeleteBlock;
use Vaulter\Ai\Documents\Commands\InsertBlock;
use Vaulter\Ai\Documents\Commands\ReplaceExact;
use Vaulter\Ai\Documents\Console\RebuildTextIndex;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Ai\Documents\Host\DocumentsCommandWriteCapability;
use Vaulter\Ai\Documents\Host\TextDocumentAccess;
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
use Vaulter\Ai\Documents\Suggestions\ProposalSuggestionBridge;
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

        $this->app->scoped(RichTextBlockExtractor::class);
        $this->app->scoped(DefaultDocumentScopeResolver::class);
        $this->app->scoped(ProposalSuggestionBridge::class);
        $this->app->scoped(ProposalSuggestionPort::class, ProposalSuggestionBridge::class);
        $this->app->scoped(DocumentScopeResolver::class, DefaultDocumentScopeResolver::class);

        $this->app->scoped(AiDocumentsSchema::class);
        $this->app->scoped(VaulterTransactionRunner::class);
        $this->app->scoped(TransactionRunner::class, VaulterTransactionRunner::class);
        $this->app->scoped(TextCommands::class);
        $this->app->scoped(IndexText::class);
        $this->app->scoped(TextProjectionOutboxConsumer::class);
        $this->app->scoped(TextProjectionReader::class);
        $this->app->scoped(TextReader::class);
        $this->app->scoped(ReplaceExact::class);
        $this->app->scoped(InsertBlock::class);
        $this->app->scoped(DeleteBlock::class);

        $this->app->scoped(ResourceDirectory::class, VaulterResourceDirectory::class);
        $this->app->scoped(DocumentsCommandWriteCapability::class, static fn (Container $app): DocumentsCommandWriteCapability => new DocumentsCommandWriteCapability(
            registry: $app->make(OfficeRegistry::class),
            access: $app->make(TextDocumentAccess::class),
            fallback: $app->make(RegistryCommandWriteCapability::class),
            directory: $app->make(ResourceDirectory::class),
        ));
        $this->app->scoped(CommandWriteCapability::class, DocumentsCommandWriteCapability::class);

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

        $this->app->make(AiDocumentsSchema::class)->assertSameConnectionAsCore();

        $this->callAfterResolving(OfficeRegistry::class, static function (OfficeRegistry $registry, Container $app): void {
            $singleTextOwner = $app->make(SingleTextOwner::class);
            $registry->registerReader($app->make(TextReader::class));
            $registry->registerCommand($app->make(ReplaceExact::class));
            $registry->registerCommand($app->make(InsertBlock::class));
            $registry->registerCommand($app->make(DeleteBlock::class));
            $registry->registerOwner(ReplaceExact::COMMAND, 'snapshot', $singleTextOwner);
            $registry->registerOwner(InsertBlock::COMMAND, 'snapshot', $singleTextOwner);
            $registry->registerOwner(DeleteBlock::COMMAND, 'snapshot', $singleTextOwner);
        });

        $this->registerProjectionListeners();
    }

    private function registerProjectionListeners(): void
    {
        $events = $this->app->make(Dispatcher::class);
        foreach ([DocumentCreated::class, DocumentContentUpdated::class, DocumentRestored::class, NodeRestored::class] as $event) {
            $events->listen($event, IndexText::class);
        }
    }
}
