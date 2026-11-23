<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Default CommandWriteCapability checking owner bindings whenever a resource reports its writeMode.
 */
final readonly class RegistryCommandWriteCapability implements CommandWriteCapability
{
    public function __construct(
        private OfficeRegistry $registry,
    ) {}

    public function canWrite(CallContext $context, ResourceEntry $resource, CommandDescriptor $descriptor): bool
    {
        if ($resource->writeMode === null) {
            return true;
        }

        return $this->registry->ownerFor($descriptor->command, $resource->writeMode) !== null;
    }
}
