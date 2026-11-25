<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Registry;

use Closure;
use Vaulter\Ai\Core\Contracts\CommandOwner;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Contracts\OfficeExecutor;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeRegistryException;

/**
 * Central catalog of registered commands, resource readers, command owners, and agent executors.
 * Collisions fail fast at boot; freeze() seals the registry for Octane worker isolation.
 */
final class OfficeRegistry
{
    /** @var array<string, OfficeCommand> */
    private array $commands = [];

    /** @var array<string, ResourceReader> */
    private array $readers = [];

    /** @var array<string, CommandOwner> keyed by ownerKey */
    private array $owners = [];

    /** @var array<string, string> keyed by "{command}\0{writeMode}" -> ownerKey */
    private array $ownerBindings = [];

    /**
     * Built executors, or the factory that builds one on first lookup.
     *
     * @var array<string, OfficeExecutor|Closure(): OfficeExecutor>
     */
    private array $executors = [];

    private bool $frozen = false;

    public function registerCommand(OfficeCommand $command): void
    {
        $this->assertMutable();
        $descriptor = $command->descriptor();
        $expectedKey = $descriptor->name.'@'.$descriptor->version;
        if ($descriptor->command !== $expectedKey) {
            throw new OfficeRegistryException("Command string [{$descriptor->command}] must match name@version [{$expectedKey}].");
        }
        if (isset($this->commands[$descriptor->command])) {
            throw OfficeRegistryException::duplicateCommand($descriptor->command);
        }

        $this->commands[$descriptor->command] = $command;
    }

    public function registerReader(ResourceReader $reader): void
    {
        $this->assertMutable();
        $type = $reader->resourceType()->value;
        if (isset($this->readers[$type])) {
            throw OfficeRegistryException::duplicateReader($type);
        }

        $this->readers[$type] = $reader;
    }

    public function registerOwner(string $command, string $writeMode, CommandOwner $owner): void
    {
        $this->assertMutable();
        $bindingKey = $command."\0".$writeMode;
        if (isset($this->ownerBindings[$bindingKey])) {
            throw OfficeRegistryException::duplicateOwner($command, $writeMode);
        }

        $key = $owner->key();
        if (isset($this->owners[$key]) && $this->owners[$key] !== $owner) {
            throw new OfficeRegistryException("Duplicate Office command owner key [{$key}].");
        }

        $this->owners[$key] = $owner;
        $this->ownerBindings[$bindingKey] = $key;
    }

    public function registerExecutor(OfficeExecutor $executor): void
    {
        $this->assertMutable();
        $key = $executor->key();
        if (isset($this->executors[$key])) {
            throw OfficeRegistryException::duplicateExecutor($key);
        }

        $this->executors[$key] = $executor;
    }

    /**
     * Registers an executor that is only constructed when a run first asks for it.
     *
     * Executors pull in LLM ports, provider secret stores and the encrypter. Building them
     * while service providers boot makes every request and every console command pay for
     * that graph, and makes the application unbootable before APP_KEY exists (for example
     * `php artisan key:generate` on a fresh host, or Larastan booting the package).
     *
     * @param  Closure(): OfficeExecutor  $factory
     */
    public function registerLazyExecutor(string $key, Closure $factory): void
    {
        $this->assertMutable();
        if (isset($this->executors[$key])) {
            throw OfficeRegistryException::duplicateExecutor($key);
        }

        $this->executors[$key] = $factory;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function command(string $command): ?OfficeCommand
    {
        return $this->commands[$command] ?? null;
    }

    public function reader(ResourceType $resourceType): ?ResourceReader
    {
        return $this->readers[$resourceType->value] ?? null;
    }

    public function owner(string $ownerKey): ?CommandOwner
    {
        return $this->owners[$ownerKey] ?? null;
    }

    public function ownerFor(string $command, string $writeMode): ?CommandOwner
    {
        $ownerKey = $this->ownerBindings[$command."\0".$writeMode] ?? null;

        return $ownerKey === null ? null : ($this->owners[$ownerKey] ?? null);
    }

    public function executor(string $key): ?OfficeExecutor
    {
        $entry = $this->executors[$key] ?? null;
        if (! $entry instanceof Closure) {
            return $entry;
        }

        $executor = $entry();
        if ($executor->key() !== $key) {
            throw new OfficeRegistryException("Office executor registered as [{$key}] reports key [{$executor->key()}].");
        }

        // Memoising the built executor is not a registration, so it stays allowed after freeze().
        $this->executors[$key] = $executor;

        return $executor;
    }

    /**
     * @return list<CommandDescriptor>
     */
    public function allowedDescriptors(
        CallContext $context,
        HostPolicy $policy,
        ResourceEntry $resource,
        ?CommandWriteCapability $writeCapability = null,
    ): array {
        $allowed = [];
        foreach ($policy->allowedCommands($context, $resource) as $name) {
            $cmd = $this->command($name);
            if ($cmd === null) {
                continue;
            }
            $descriptor = $cmd->descriptor();
            if ($descriptor->resourceType !== $resource->resourceType || ! $policy->allowsCommand($context, $resource, $name)) {
                continue;
            }
            if ($writeCapability !== null && ! $writeCapability->canWrite($context, $resource, $descriptor)) {
                continue;
            }
            $allowed[] = $descriptor;
        }

        return $allowed;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw OfficeRegistryException::registryFrozen();
        }
    }
}
