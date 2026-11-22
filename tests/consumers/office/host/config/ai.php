<?php

declare(strict_types=1);

return [
    'default' => env('VAULTER_AI_PROVIDER', 'openai'),
    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY', ''),
        ],
    ],
];
