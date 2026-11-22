<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use stdClass;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;

/**
 * Versioned Office SDK command definition and plan preparer.
 */
interface OfficeCommand
{
    public function descriptor(): CommandDescriptor;

    public function prepare(
        CallContext $context,
        ResourceEntry $resource,
        ReadTokenRecord $token,
        stdClass $arguments,
    ): PreparedPlan;
}
