<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRun;

/**
 * Optional executor capability allowing workspace continuation of interrupted or partial runs.
 */
interface ResumableOfficeExecutor extends OfficeExecutor
{
    public function executeRun(CallContext $context, string $runId): OfficeRun;
}
