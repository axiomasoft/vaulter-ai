<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vaulter\Ai\Core\Http\ChatWorkspaceController;
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
        Route::get('/chat/sessions/{id}', [ChatWorkspaceController::class, 'show'])->name('vaulter-ai.workspace.chat.show');
        Route::post('/chat/sessions/{id}/messages', [ChatWorkspaceController::class, 'post'])->name('vaulter-ai.workspace.chat.messages');
        Route::get('/chat/sessions/{id}/events', [ChatWorkspaceController::class, 'events'])->name('vaulter-ai.workspace.chat.events');
    });
