<?php

declare(strict_types=1);

return [
    'queue' => env('VAULTER_AI_AGENT_QUEUE', 'office-agent'),
    'worker_timeout_seconds' => (int) env('VAULTER_AI_WORKER_TIMEOUT', 75),
    'queue_retry_after_seconds' => (int) env('VAULTER_AI_QUEUE_RETRY_AFTER', 100),
    'stop_grace_period_seconds' => (int) env('VAULTER_AI_STOP_GRACE_PERIOD', 90),
    'tool_timeout_seconds' => (int) env('VAULTER_AI_TOOL_TIMEOUT', 30),
    'tries' => (int) env('VAULTER_AI_JOB_TRIES', 3),
    'backoff_seconds' => [5, 15, 30],
    'default_model' => env('VAULTER_AI_MODEL'),
    'provider_connections' => [
        'office_model' => env('VAULTER_AI_PROVIDER', 'openai'),
    ],
];
