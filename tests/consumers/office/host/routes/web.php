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
