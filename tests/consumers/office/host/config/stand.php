<?php

declare(strict_types=1);

return [
    'profile' => env('STAND_PROFILE', 'ai-text'),
    'fixture_token' => env('FIXTURE_TOKEN', ''),
    'deterministic_script' => (bool) env('VAULTER_AI_DETERMINISTIC_SCRIPT', true),
];
