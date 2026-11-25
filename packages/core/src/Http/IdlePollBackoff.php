<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

/**
 * Delay between polls of an event stream that has nothing new to send.
 *
 * Each empty poll doubles the delay up to a cap and any delivered event resets it, so a
 * busy run is still streamed at the base interval while an idle tab stops issuing a
 * database round trip every 200 ms.
 */
final class IdlePollBackoff
{
    public const int BASE_MICROSECONDS = 200_000;

    public const int MAX_MICROSECONDS = 2_000_000;

    private int $delay = self::BASE_MICROSECONDS;

    /** Microseconds to wait after a poll that delivered `$events` events (0 = poll again at once). */
    public function after(int $events): int
    {
        if ($events > 0) {
            $this->delay = self::BASE_MICROSECONDS;

            return 0;
        }

        $delay = $this->delay;
        $this->delay = min(self::MAX_MICROSECONDS, $delay * 2);

        return $delay;
    }
}
