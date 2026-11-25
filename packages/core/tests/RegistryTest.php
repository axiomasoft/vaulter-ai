<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Contracts\OfficeExecutor;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Exceptions\OfficeRegistryException;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

beforeEach(function (): void {
    $this->bootVaulterDomain = false;
    $this->bootDocumentsAi = false;
});

function makeTextCommand(): OfficeCommand
{
    return new class implements OfficeCommand
    {
        public function descriptor(): CommandDescriptor
        {
            return new CommandDescriptor(
                command: 'text.replace_exact@1',
                name: 'text.replace_exact',
                version: 1,
                resourceType: ResourceType::Text,
                effect: 'single_resource_write',
                inputSchema: (object) [
                    'type' => 'object',
                    'required' => ['block_id', 'expected', 'replacement'],
                    'properties' => (object) [
                        'block_id' => (object) ['type' => 'string', 'minLength' => 1],
                        'expected' => (object) ['type' => 'string', 'minLength' => 1],
                        'replacement' => (object) ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                verification: 'text_and_marks',
            );
        }

        public function prepare(CallContext $context, ResourceEntry $resource, ReadTokenRecord $token, stdClass $arguments): PreparedPlan
        {
            throw OfficeException::operationUnknown();
        }
    };
}

test('OfficeRegistry rejects duplicate command (name, version) and locks after freeze', function (): void {
    $registry = new OfficeRegistry;
    $cmd = makeTextCommand();
    $registry->registerCommand($cmd);

    expect(static fn () => $registry->registerCommand($cmd))
        ->toThrow(OfficeRegistryException::class, 'text.replace_exact@1');

    $registry->freeze();

    expect(static fn () => $registry->registerCommand(makeTextCommand()))
        ->toThrow(OfficeRegistryException::class, 'frozen');
});

test('office.describe enforces host policy and resource visibility identically', function (): void {
    $directory = new InMemoryResourceDirectory;
    $directory->put('tenant-a', 'proj-1', 'user/1', new ResourceEntry('doc-1', ResourceType::Text, 'Contract A'));
    $this->app->instance(ResourceDirectory::class, $directory);

    $this->app->instance(HostPolicy::class, new class implements HostPolicy
    {
        public function profile(CallContext $context): HostProfile
        {
            return new HostProfile(
                name: $context->profile,
                executor: 'laravel-ai',
                providerConnection: null,
                commands: $context->profile === 'read_only' ? [] : ['text.replace_exact@1'],
                approval: ApprovalMode::Human,
            );
        }

        public function allowedCommands(CallContext $context, ResourceEntry $resource): array
        {
            return $this->profile($context)->commands;
        }

        public function allowsCommand(CallContext $context, ResourceEntry $resource, string $command): bool
        {
            return in_array($command, $this->allowedCommands($context, $resource), true);
        }
    });

    // Verify booted container registry is frozen.
    expect(fn () => $this->app->make(OfficeRegistry::class)->registerCommand(makeTextCommand()))
        ->toThrow(OfficeRegistryException::class, 'frozen');

    $registry = $this->freshRegistry();
    $registry->registerCommand(makeTextCommand());
    $office = $this->app->make(Office::class);

    // Authorized caller with allowed profile gets descriptor.
    $ok = $office->handle(
        new CallContext('tenant-a', 'proj-1', 'user/1', profile: 'documents'),
        new OfficeRequest(1, OfficeOperation::Describe, (object) [
            'resource_id' => 'doc-1',
            'command' => 'text.replace_exact@1',
        ]),
    );
    expect($ok->ok)->toBeTrue();
    expect($ok->result?->command)->toBe('text.replace_exact@1');
    expect($ok->result?->verification)->toBe('text_and_marks');

    // Host policy denying command returns resource_unavailable.
    $deniedByPolicy = $office->handle(
        new CallContext('tenant-a', 'proj-1', 'user/1', profile: 'read_only'),
        new OfficeRequest(1, OfficeOperation::Describe, (object) [
            'resource_id' => 'doc-1',
            'command' => 'text.replace_exact@1',
        ]),
    );
    expect($deniedByPolicy->ok)->toBeFalse();
    expect($deniedByPolicy->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    // Unauthorized subject or wrong project scope returns identical resource_unavailable.
    $wrongProject = $office->handle(
        new CallContext('tenant-a', 'proj-2', 'user/1', profile: 'documents'),
        new OfficeRequest(1, OfficeOperation::Describe, (object) [
            'resource_id' => 'doc-1',
            'command' => 'text.replace_exact@1',
        ]),
    );
    expect($wrongProject->ok)->toBeFalse();
    expect($wrongProject->errorCode)->toBe(ErrorCode::ResourceUnavailable);
});

function makeExecutor(string $key): OfficeExecutor
{
    return new class($key) implements OfficeExecutor
    {
        public function __construct(private readonly string $key) {}

        public function key(): string
        {
            return $this->key;
        }

        public function dispatch(CallContext $context, array $payload): void {}
    };
}

test('OfficeRegistry builds a lazily registered executor once, on first lookup', function (): void {
    $registry = new OfficeRegistry;
    $built = 0;
    $registry->registerLazyExecutor('lazy', static function () use (&$built): OfficeExecutor {
        $built++;

        return makeExecutor('lazy');
    });

    expect($built)->toBe(0);

    $registry->freeze();
    $first = $registry->executor('lazy');

    expect($first)->not->toBeNull()
        ->and($first?->key())->toBe('lazy')
        ->and($registry->executor('lazy'))->toBe($first)
        ->and($built)->toBe(1)
        ->and($registry->executor('missing'))->toBeNull();
});

test('OfficeRegistry rejects duplicate executor keys across eager and lazy registration', function (): void {
    $registry = new OfficeRegistry;
    $registry->registerExecutor(makeExecutor('dup'));

    expect(static fn () => $registry->registerLazyExecutor('dup', static fn (): OfficeExecutor => makeExecutor('dup')))
        ->toThrow(OfficeRegistryException::class, 'dup');

    $lazyFirst = new OfficeRegistry;
    $lazyFirst->registerLazyExecutor('dup', static fn (): OfficeExecutor => makeExecutor('dup'));

    expect(static fn () => $lazyFirst->registerExecutor(makeExecutor('dup')))
        ->toThrow(OfficeRegistryException::class, 'dup');
});

test('OfficeRegistry refuses lazy registration after freeze and a factory that builds another key', function (): void {
    $registry = new OfficeRegistry;
    $registry->registerLazyExecutor('declared', static fn (): OfficeExecutor => makeExecutor('actual'));
    $registry->freeze();

    expect(static fn () => $registry->registerLazyExecutor('late', static fn (): OfficeExecutor => makeExecutor('late')))
        ->toThrow(OfficeRegistryException::class, 'frozen')
        ->and(static fn () => $registry->executor('declared'))
        ->toThrow(OfficeRegistryException::class, 'reports key [actual]');
});
