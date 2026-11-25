<?php

declare(strict_types=1);

/*
 * The package graph is enforced here rather than trusted to review: every
 * package may only reach the namespaces its composer.json makes available,
 * so an isolated install of any package keeps autoloading. Core is the
 * contract layer, owners (documents, sheets, collaboration) and model
 * adapters (laravel-ai, agentools) sit on top of it and never on each other,
 * except collaboration, which extends the documents owner.
 */

const AI_CORE = 'Vaulter\Ai\Core';
const AI_DOCUMENTS = 'Vaulter\Ai\Documents';
const AI_SHEETS = 'Vaulter\Ai\Sheets';
const AI_COLLABORATION = 'Vaulter\Ai\Collaboration';
const AI_LARAVEL_AI = 'Vaulter\Ai\LaravelAi';
const AI_AGENTOOLS = 'Vaulter\Ai\AgenTools';

/** Host Vaulter namespaces no adapter or core class may depend on. */
const HOST_VAULTER = [
    'Vaulter\Attributes',
    'Vaulter\Collaboration',
    'Vaulter\Configuration',
    'Vaulter\Database',
    'Vaulter\Documents',
    'Vaulter\Drive',
    'Vaulter\Events',
    'Vaulter\Exceptions',
    'Vaulter\Kernel',
    'Vaulter\Laravel',
    'Vaulter\Modules',
    'Vaulter\Schema',
    'Vaulter\Sheets',
    'Vaulter\Support',
    'Vaulter\Tenancy',
    'Vaulter\ValueObjects',
];

arch('core depends on no owner, adapter, host package or model SDK')
    ->expect(AI_CORE)
    ->not->toUse([AI_DOCUMENTS, AI_SHEETS, AI_COLLABORATION, AI_LARAVEL_AI, AI_AGENTOOLS, 'Laravel\Ai', 'AgenTools', ...HOST_VAULTER]);

arch('the documents owner depends on no other owner or adapter')
    ->expect(AI_DOCUMENTS)
    ->not->toUse([AI_SHEETS, AI_COLLABORATION, AI_LARAVEL_AI, AI_AGENTOOLS, 'Laravel\Ai', 'AgenTools', 'Vaulter\Sheets', 'Vaulter\Collaboration']);

arch('the sheets owner depends on no other owner or adapter')
    ->expect(AI_SHEETS)
    ->not->toUse([AI_DOCUMENTS, AI_COLLABORATION, AI_LARAVEL_AI, AI_AGENTOOLS, 'Laravel\Ai', 'AgenTools', 'Vaulter\Collaboration']);

arch('the collaboration owner builds on documents only')
    ->expect(AI_COLLABORATION)
    ->not->toUse([AI_SHEETS, AI_LARAVEL_AI, AI_AGENTOOLS, 'Laravel\Ai', 'AgenTools', 'Vaulter\Sheets']);

arch('the laravel/ai adapter depends on core and its SDK only')
    ->expect(AI_LARAVEL_AI)
    ->not->toUse([AI_DOCUMENTS, AI_SHEETS, AI_COLLABORATION, AI_AGENTOOLS, 'AgenTools', ...HOST_VAULTER]);

arch('the AgenTools adapter depends on core and its SDK only')
    ->expect(AI_AGENTOOLS)
    ->not->toUse([AI_DOCUMENTS, AI_SHEETS, AI_COLLABORATION, AI_LARAVEL_AI, 'Laravel\Ai', ...HOST_VAULTER]);

arch('production code never reaches into test code or test tooling')
    ->expect([AI_CORE, AI_DOCUMENTS, AI_SHEETS, AI_COLLABORATION, AI_LARAVEL_AI, AI_AGENTOOLS])
    ->not->toUse(['Vaulter\Ai\Tests', 'Pest', 'PHPUnit', 'Orchestra\Testbench'])
    ->ignoring([
        'Vaulter\Ai\Core\Tests',
        'Vaulter\Ai\Documents\Tests',
        'Vaulter\Ai\Sheets\Tests',
        'Vaulter\Ai\Collaboration\Tests',
        'Vaulter\Ai\LaravelAi\Tests',
        'Vaulter\Ai\AgenTools\Tests',
    ]);
