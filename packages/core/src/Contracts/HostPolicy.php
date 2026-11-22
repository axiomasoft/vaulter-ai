<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ResourceEntry;

/**
 * Evaluates host profile constraints, command publication, and resource-level policy rules.
 */
interface HostPolicy
{
    public function profile(CallContext $context): HostProfile;

    /** @return list<string> */
    public function allowedCommands(CallContext $context, ResourceEntry $resource): array;

    public function allowsCommand(CallContext $context, ResourceEntry $resource, string $command): bool;
}
