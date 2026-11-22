<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents;

use Override;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Ai\Documents\Projection\TextProjectionOutboxConsumer;
use Vaulter\Events\Outbox\OutboxConsumer;

/**
 * Vaulter extension plugin declaring the durable AI Documents OutboxConsumer contribution.
 */
final class AiDocumentsModule implements ExtensionPlugin, WithSlots
{
    use ContributesToSlots;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.ai-documents',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.drive', 'vaulter.documents'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            [
                'slot' => OutboxConsumer::class,
                'id' => TextProjectionOutboxConsumer::ID,
                'implementation' => TextProjectionOutboxConsumer::class,
            ],
        ];
    }
}
