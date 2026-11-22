<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use DateTimeImmutable;

/**
 * Clock abstraction enabling deterministic UTC timestamps in production and tests.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
