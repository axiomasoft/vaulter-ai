<?php

declare(strict_types=1);

return [
    'channel' => env('VAULTER_AI_AGENTOOLS_CHANNEL', 'agentools'),
    'lease_seconds' => (int) env('VAULTER_AI_AGENTOOLS_LEASE_SECONDS', 90),
];
