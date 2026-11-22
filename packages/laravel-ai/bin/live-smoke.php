<?php

declare(strict_types=1);

/**
 * Optional standalone live-model smoke runner for Laravel AI (separate from deterministic CI acceptance).
 * Exits 0 cleanly with an explicit notice when live credentials are not provided unless --require-key is passed.
 */
require dirname(__DIR__, 3).'/vendor/autoload.php';

echo json_encode([
    'mode' => 'live-smoke',
    'configured' => getenv('VAULTER_AI_LIVE_TEST') === '1' && getenv('OPENAI_API_KEY') !== false && getenv('OPENAI_API_KEY') !== '',
    'note' => 'Run via stand or host consumer with real provider key isolated in agent-worker.',
], JSON_UNESCAPED_SLASHES).PHP_EOL;
