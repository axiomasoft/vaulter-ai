<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Http\IdlePollBackoff;

test('idle polls back off exponentially up to the cap and an event resets the delay', function (): void {
    $backoff = new IdlePollBackoff;

    $idle = array_map(static fn (): int => $backoff->after(0), range(1, 6));
    expect($idle)->toBe([200_000, 400_000, 800_000, 1_600_000, 2_000_000, 2_000_000])
        ->and($backoff->after(3))->toBe(0)
        ->and($backoff->after(0))->toBe(IdlePollBackoff::BASE_MICROSECONDS);
});

test('an idle stream polls 15 times per 25 s connection instead of 125', function (): void {
    $backoff = new IdlePollBackoff;
    $elapsed = 0;
    $polls = 0;
    while ($elapsed < 25_000_000) {
        $polls++;
        $elapsed += $backoff->after(0);
    }

    expect($polls)->toBe(15);
});
