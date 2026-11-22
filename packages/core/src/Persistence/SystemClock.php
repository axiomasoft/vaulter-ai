<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Vaulter\Ai\Core\Contracts\Clock;

/**
 * Default UTC wall-clock implementation.
 */
final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
