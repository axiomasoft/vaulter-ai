<?php

declare(strict_types=1);

return [
    'default_tenant_id' => env('VAULTER_AI_SHEETS_DEFAULT_TENANT_ID', env('VAULTER_AI_DOCUMENTS_DEFAULT_TENANT_ID')),
    'default_project_scope' => env('VAULTER_AI_SHEETS_DEFAULT_PROJECT_SCOPE', env('VAULTER_AI_DOCUMENTS_DEFAULT_PROJECT_SCOPE', 'default')),
];
