<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Exceptions;

use LogicException;

final class OfficeRegistryException extends LogicException
{
    public static function duplicateCommand(string $command): self
    {
        return new self("Duplicate Office command registration for [{$command}].");
    }

    public static function duplicateReader(string $resourceType): self
    {
        return new self("Duplicate Office resource reader registration for [{$resourceType}].");
    }

    public static function duplicateOwner(string $command, string $writeMode): self
    {
        return new self("Duplicate Office command owner registration for [{$command}] in write mode [{$writeMode}].");
    }

    public static function duplicateExecutor(string $key): self
    {
        return new self("Duplicate Office executor registration for [{$key}].");
    }

    public static function registryFrozen(): self
    {
        return new self('Office registry is frozen after application boot.');
    }
}
