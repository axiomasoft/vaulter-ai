<?php

declare(strict_types=1);

return [
    'projector_version' => 1,
    'default_tenant_id' => env('VAULTER_AI_DOCUMENTS_DEFAULT_TENANT_ID'),
    'default_project_scope' => env('VAULTER_AI_DOCUMENTS_DEFAULT_PROJECT_SCOPE', 'default'),
    'read' => [
        // When projection lags behind after_receipt, fail closed with projection_pending unless
        // the host explicitly enables authorized live fallback from Documents.
        'live_fallback_on_lag' => env('VAULTER_AI_DOCUMENTS_LIVE_FALLBACK_ON_LAG', false),
        'snippet_max_chars' => 140,
        'default_outline_limit' => 20,
    ],
    'projection' => [
        // Automatic synchronous/after-commit indexing on Vaulter Documents events.
        'auto_index' => env('VAULTER_AI_DOCUMENTS_AUTO_INDEX', true),
    ],
];
