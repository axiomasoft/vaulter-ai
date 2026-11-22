<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Vaulter\Ai\Core\Contracts\Clock;

/**
 * Deterministic controllable UTC clock for expiry, snapshot, and wire fixture tests.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct(string $iso = '2026-11-20T12:00:00+00:00')
    {
        $this->current = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(string $iso): void
    {
        $this->current = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->current = $this->current->modify(($seconds >= 0 ? '+' : '').$seconds.' seconds');
    }
}
