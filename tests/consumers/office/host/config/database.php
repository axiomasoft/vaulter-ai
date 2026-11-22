<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'postgres'),
            'port' => (int) env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'vaulter_ai_office'),
            'username' => env('DB_USERNAME', 'vaulter'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
];
