<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use stdClass;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\PreparedPlan;

/**
 * Domain owner responsible for executing, tracking, and verifying committed mutations.
 */
interface CommandOwner
{
    public function key(): string;

    public function supports(string $command, ?string $writeMode = null): bool;

    public function commit(CommandScope $scope, PreparedPlan $plan): CommandOutcome;

    public function status(CommandScope $scope): CommandOutcome;

    public function verify(CallContext $context, string $resourceId, string $receiptId, string $mode): stdClass;
}
