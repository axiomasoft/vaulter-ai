<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use Closure;

interface ChatEventWakeup
{
    public function notify(string $sessionId): void;

    /** @return Closure(int): bool Wait up to the given number of milliseconds for a wakeup. */
    public function subscribe(string $sessionId): Closure;
}
