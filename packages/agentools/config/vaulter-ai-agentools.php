<?php

declare(strict_types=1);

return [
    'channel' => env('VAULTER_AI_AGENTOOLS_CHANNEL', 'agentools'),
    'lease_seconds' => (int) env('VAULTER_AI_AGENTOOLS_LEASE_SECONDS', 90),
    'chat' => [
        'max_retries' => (int) env('VAULTER_AI_CHAT_MAX_RETRIES', 2),
        'retry_backoff_ms' => (int) env('VAULTER_AI_CHAT_RETRY_BACKOFF_MS', 100),
        'request_timeout_seconds' => (int) env('VAULTER_AI_CHAT_REQUEST_TIMEOUT', 30),
        'max_output_bytes' => (int) env('VAULTER_AI_CHAT_MAX_OUTPUT_BYTES', 65536),
        'max_tool_calls' => (int) env('VAULTER_AI_CHAT_MAX_TOOL_CALLS', 32),
    ],
];
