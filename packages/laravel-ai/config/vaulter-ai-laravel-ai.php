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
    'chat' => [
        'max_retries' => (int) env('VAULTER_AI_CHAT_MAX_RETRIES', 2),
        'retry_backoff_ms' => (int) env('VAULTER_AI_CHAT_RETRY_BACKOFF_MS', 100),
        'request_timeout_seconds' => (int) env('VAULTER_AI_CHAT_REQUEST_TIMEOUT', 30),
        'max_output_bytes' => (int) env('VAULTER_AI_CHAT_MAX_OUTPUT_BYTES', 65536),
        'max_tool_calls' => (int) env('VAULTER_AI_CHAT_MAX_TOOL_CALLS', 32),
    ],
];
