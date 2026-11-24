<?php

declare(strict_types=1);

return [
    'database' => [
        'connection' => env('VAULTER_AI_DB_CONNECTION'),
        'table_prefix' => env('VAULTER_AI_TABLE_PREFIX', 'v_'),
    ],
    'route_prefix' => env('VAULTER_AI_ROUTE_PREFIX', 'api/vaulter-ai/v1'),
    'middleware' => ['api'],
    'workspace_prefix' => env('VAULTER_AI_WORKSPACE_PREFIX', 'office'),
    'workspace_middleware' => ['web'],
    'default_profile' => env('VAULTER_AI_DEFAULT_PROFILE', 'documents'),
    'profiles' => [
        'documents' => [
            'executor' => 'laravel-ai',
            'provider_connection' => 'office_model',
            'commands' => ['text.replace_exact@1', 'text.insert_block@1', 'text.delete_block@1'],
            'approval' => 'human',
        ],
        'sheets' => [
            'executor' => 'laravel-ai',
            'provider_connection' => 'office_model',
            'commands' => ['sheet.fill_formula@1'],
            'approval' => 'human',
        ],
    ],
    'limits' => [
        'search_default_limit' => 5,
        'search_max_limit' => 50,
        'search_max_candidates' => 500,
        'read_default_limit' => 20,
        'read_max_rows' => 20,
        'read_max_bytes' => 16384,
        'prepare_max_targets' => 20,
        'read_token_ttl_seconds' => 900,
        'search_page_ttl_seconds' => 900,
        'proposal_ttl_seconds' => 3600,
    ],
    'encryption' => [
        'key_id' => env('VAULTER_AI_KEY_ID', 'app-key-v1'),
    ],
    'secret_store' => [
        'default_model' => env('VAULTER_AI_MODEL'),
        'key_version' => (int) env('VAULTER_AI_SECRET_KEY_VERSION', 1),
        'keys' => [],
        'connections' => [
            'office_model' => [
                'driver' => env('VAULTER_AI_PROVIDER', 'openai'),
                'key' => env('VAULTER_AI_PROVIDER_KEY', env('OPENAI_API_KEY')),
                'model' => env('VAULTER_AI_MODEL'),
                'url' => env('VAULTER_AI_PROVIDER_URL', env('OPENAI_BASE_URL')),
            ],
        ],
    ],
    'chat' => [
        'max_tool_calls' => 32,
        'max_output_bytes' => 65536,
        'max_retries' => 2,
        'retry_backoff_ms' => 100,
        'request_timeout_seconds' => 30,
    ],
];
