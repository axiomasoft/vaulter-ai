<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\ResourceEntry;

/**
 * Evaluates domain write readiness for a resource/command (for example 3.5 strict-profile journal checks).
 */
interface CommandWriteCapability
{
    public function canWrite(CallContext $context, ResourceEntry $resource, CommandDescriptor $descriptor): bool;
}
