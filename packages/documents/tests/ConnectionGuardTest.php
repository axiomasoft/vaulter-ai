<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Documents\DocumentsAiServiceProvider;
use Vaulter\Ai\Documents\Schema\AiDocumentsSchema;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Database\VaulterDatabase;

uses(OfficeTestCase::class);

test('the documents schema allows writer activation when its connection matches OfficeDatabase', function (): void {
    $office = $this->app->make(OfficeDatabase::class)->connection()->getName();
    $documents = $this->app->make(VaulterDatabase::class)->connection()->getName();

    expect($office)->toBe($documents);

    $this->app->make(AiDocumentsSchema::class)->assertSameConnectionAsCore();

    expect($this->app->make(OfficeRegistry::class)->ownerFor('text.replace_exact@1', 'snapshot'))->not->toBeNull();
});

test('writer activation is refused when the documents connection differs from OfficeDatabase', function (): void {
    $testing = $this->app['config']->get('database.connections.testing');
    expect($testing)->toBeArray();

    $this->app['config']->set('database.connections.office_split', $testing);
    $this->app['config']->set('vaulter-ai.database.connection', 'office_split');
    $this->app->forgetInstance(OfficeDatabase::class);
    $this->app->forgetInstance(AiDocumentsSchema::class);

    $schema = $this->app->make(AiDocumentsSchema::class);
    expect(fn () => $schema->assertSameConnectionAsCore())
        ->toThrow(RuntimeException::class, 'DocumentsDatabase connection [testing] differs from OfficeDatabase connection [office_split]');

    $registry = new OfficeRegistry;
    $this->app->instance(OfficeRegistry::class, $registry);

    expect(fn () => (new DocumentsAiServiceProvider($this->app))->boot())
        ->toThrow(RuntimeException::class, 'OfficeDatabase connection [office_split]');
    expect($registry->ownerFor('text.replace_exact@1', 'snapshot'))->toBeNull();
});
