<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets;

use Override;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use Vaulter\Ai\Sheets\Host\DriveWorkbookOperations;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Modules\Activation;
use Vaulter\Modules\PluginModuleSpec;
use Vaulter\Modules\WithModuleRuntime;

/**
 * Vaulter extension plugin contributing actor-scoped DriveWorkbookOperations for vaulter-ai-sheets.
 */
final class AiSheetsModule implements ExtensionPlugin, WithModuleRuntime, WithSlots
{
    use ContributesToSlots;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'vaulter.ai-sheets',
            version: '1.0.0',
            requires: ['vaulter.core', 'vaulter.drive', 'vaulter.documents', 'vaulter.sheets'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    public function moduleRuntime(): PluginModuleSpec
    {
        return new PluginModuleSpec(
            plugin: self::class,
            activation: Activation::Auto,
        );
    }

    /** @return list<array{slot: class-string, id: string, implementation: class-string, priority?: int, key?: string|null}> */
    #[Override]
    public function slotContributions(): array
    {
        return [
            [
                'slot' => DriveExtension::class,
                'id' => 'ai-sheets.workbook-operations',
                'implementation' => DriveWorkbookOperations::class,
                'key' => DriveWorkbookOperations::class,
            ],
        ];
    }
}
