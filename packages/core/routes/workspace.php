<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Ai\Core\Http\ProposalWorkspaceController;
use Vaulter\Ai\Core\Http\RunWorkspaceController;

$prefix = config('vaulter-ai.workspace_prefix', 'office');
$middleware = config('vaulter-ai.workspace_middleware', ['web']);

Route::prefix(is_string($prefix) ? trim($prefix, '/') : 'office')
    ->middleware(is_array($middleware) ? $middleware : ['web'])
    ->group(static function (): void {
        Route::get('/proposals', [ProposalWorkspaceController::class, 'index'])->name('vaulter-ai.workspace.proposals.index');
        Route::get('/proposals/{id}', [ProposalWorkspaceController::class, 'show'])->name('vaulter-ai.workspace.proposals.show');
        Route::post('/proposals/{id}/decision', [ProposalWorkspaceController::class, 'decide'])->name('vaulter-ai.workspace.proposals.decide');
        Route::get('/runs/{id}', [RunWorkspaceController::class, 'show'])->name('vaulter-ai.workspace.runs.show');
        Route::post('/runs/{id}/resume', [RunWorkspaceController::class, 'resume'])->name('vaulter-ai.workspace.runs.resume');
    });
