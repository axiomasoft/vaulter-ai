<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;

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
    if ($profile === 'ai-sheets' && ! Schema::hasTable('v_sheets_commands')) {
        return response()->json(['status' => 'not_ready'], 503);
    }
    if ($profile === 'ai-collaboration' && ! Schema::hasTable('v_collaboration_commands')) {
        return response()->json(['status' => 'not_ready'], 503);
    }

    $expectedExecutor = $profile === 'ai-agentools' ? 'agentools' : 'laravel-ai';
    if ($registry->executor($expectedExecutor) === null) {
        return response()->json(['status' => 'not_ready'], 503);
    }

    return response()->json(['status' => 'ready']);
});

$renderWorkspacePanel = static function (
    Request $request,
    CallContextResolver $contexts,
    OfficeWorkspaceService $workspace,
    WireErrorMapper $errorMapper,
): Response|JsonResponse {
    $viewer = $contexts->resolve($request);
    if ($viewer === null) {
        return response()->json(['error' => ['code' => 'authentication_required']], 401);
    }

    $resourceId = is_string($request->query('resource_id')) && $request->query('resource_id') !== ''
        ? $request->query('resource_id')
        : null;
    $decision = is_string($request->query('decision')) && $request->query('decision') !== ''
        ? $request->query('decision')
        : null;
    $proposalId = is_string($request->query('proposal_id')) && $request->query('proposal_id') !== ''
        ? $request->query('proposal_id')
        : null;
    $runId = is_string($request->query('run_id')) && $request->query('run_id') !== ''
        ? $request->query('run_id')
        : null;

    try {
        $proposals = $proposalId !== null
            ? [$workspace->showProposal($viewer, $proposalId)]
            : $workspace->listProposals($viewer, $resourceId, $decision);
        $run = $runId !== null ? $workspace->showRun($viewer, $runId) : null;
    } catch (OfficeException $e) {
        $spec = $errorMapper->describe($e->errorCode);

        return response()->view('proposals', [
            'viewer' => $viewer,
            'proposals' => [],
            'run' => null,
            'errorCode' => $e->errorCode->value,
            'currentUrl' => $request->getRequestUri(),
        ], $spec['status'])->header('Cache-Control', 'private, no-store');
    }

    return response()->view('proposals', [
        'viewer' => $viewer,
        'proposals' => $proposals,
        'run' => $run,
        'errorCode' => null,
        'currentUrl' => $request->getRequestUri(),
    ])->header('Cache-Control', 'private, no-store');
};

Route::get('/office/workspace', $renderWorkspacePanel)->name('stand.proposals.workspace');
Route::get('/stand/proposals', $renderWorkspacePanel)->name('stand.proposals.index');

Route::get('/_stand-assets/vue.esm-browser.prod.js', static function (): Response {
    $candidates = [
        public_path('_stand-assets/vue.esm-browser.prod.js'),
        dirname(__DIR__, 5).'/node_modules/vue/dist/vue.esm-browser.prod.js',
    ];
    $path = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }
    if ($path === null) {
        abort(404);
    }

    return response((string) file_get_contents($path), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
});

Route::get('/_stand-assets/vaulter-ai-vue.js', static function (): Response {
    $bundled = public_path('_stand-assets/vaulter-ai-vue.js');
    if (is_file($bundled)) {
        return response((string) file_get_contents($bundled), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    $repoRoot = dirname(__DIR__, 5);
    $src = $repoRoot.'/packages/vue/dist/vaulter-ai-vue.js';
    if (! is_file($src)) {
        abort(404);
    }

    $cached = sys_get_temp_dir().'/vaulter-stand-ai-vue-'.md5((string) filemtime($src)).'.js';
    $esbuild = $repoRoot.'/node_modules/.bin/esbuild';
    if (! is_file($cached) && is_file($esbuild)) {
        $nodePath = $repoRoot.'/node_modules:'.$repoRoot.'/packages/vue/node_modules';
        $cmd = sprintf(
            'NODE_PATH=%s %s %s --bundle --format=esm --platform=browser --external:vue --outfile=%s 2>/dev/null',
            escapeshellarg($nodePath),
            escapeshellarg($esbuild),
            escapeshellarg($src),
            escapeshellarg($cached),
        );
        @exec($cmd, $out, $code);
    }

    $path = is_file($cached) ? $cached : $src;

    return response((string) file_get_contents($path), 200, [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
});

Route::get('/office/chat', static function (
    Request $request,
    CallContextResolver $contexts,
): Response|JsonResponse {
    $viewer = $contexts->resolve($request);
    if ($viewer === null) {
        return response()->json(['error' => ['code' => 'authentication_required']], 401);
    }

    $resourceId = is_string($request->query('resource_id')) && $request->query('resource_id') !== ''
        ? $request->query('resource_id')
        : '';
    $sessionId = is_string($request->query('session_id')) && $request->query('session_id') !== ''
        ? $request->query('session_id')
        : '';
    $blockId = is_string($request->query('block_id')) && $request->query('block_id') !== ''
        ? $request->query('block_id')
        : '';
    $excerpt = is_string($request->query('excerpt')) && $request->query('excerpt') !== ''
        ? $request->query('excerpt')
        : '';

    return response()->view('chat', [
        'viewer' => $viewer,
        'resourceId' => $resourceId,
        'sessionId' => $sessionId,
        'blockId' => $blockId,
        'excerpt' => $excerpt,
    ])->header('Cache-Control', 'private, no-store');
})->name('stand.chat');
