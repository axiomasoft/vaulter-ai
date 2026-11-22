<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

Route::get('/_fixture/livez', static fn (): JsonResponse => response()->json(['ok' => true]));

Route::get('/_fixture/readyz', static function (OfficeRegistry $registry): JsonResponse {
    try {
        DB::select('SELECT 1');
    } catch (Throwable) {
        return response()->json(['status' => 'not_ready'], 503);
    }

    if (! Schema::hasTable('v_ai_runs') || ! Schema::hasTable('v_ai_text_commands')) {
        return response()->json(['status' => 'not_ready'], 503);
    }

    $profile = config('stand.profile');
    $expectedExecutor = $profile === 'ai-agentools' ? 'agentools' : 'laravel-ai';
    if ($registry->executor($expectedExecutor) === null) {
        return response()->json(['status' => 'not_ready'], 503);
    }

    return response()->json(['status' => 'ready']);
});
