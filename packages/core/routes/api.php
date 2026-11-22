<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Ai\Core\Http\OfficeController;

$prefix = config('vaulter-ai.route_prefix', 'api/vaulter-ai/v1');
$middleware = config('vaulter-ai.middleware', ['api']);

Route::prefix(is_string($prefix) ? trim($prefix, '/') : 'api/vaulter-ai/v1')
    ->middleware(is_array($middleware) ? $middleware : ['api'])
    ->group(static function (): void {
        Route::post('/operations', OfficeController::class)->name('vaulter-ai.operations');
    });
