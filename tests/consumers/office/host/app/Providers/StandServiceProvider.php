<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\StandActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Responses\Data\ToolCall;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Schema\ModuleSchemas;

final class StandServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CallContextResolver::class, static fn (): CallContextResolver => new class implements CallContextResolver
        {
            public function resolve(Request $request): ?CallContext
            {
                $bearer = $request->bearerToken();
                $cookieToken = $request->cookie('stand_token');
                $rawToken = is_string($bearer) && $bearer !== ''
                    ? $bearer
                    : (is_string($cookieToken) && $cookieToken !== '' ? $cookieToken : null);

                if ($rawToken !== null) {
                    $row = DB::table('stand_tokens')->where('token_hash', hash('sha256', $rawToken))->first();
                    if ($row === null) {
                        return null;
                    }

                    $actor = StandActor::query()->find($row->user_id);
                    if ($actor === null) {
                        return null;
                    }

                    return new CallContext(
                        tenantId: (string) $row->tenant_id,
                        projectScope: (string) $row->project_scope,
                        effectiveSubject: 'user/'.$actor->getKey(),
                        profile: (string) $row->profile,
                        policyRevision: (string) $row->policy_revision,
                        driveId: (string) $row->drive_id,
                        actor: $actor,
                    );
                }

                $user = $request->user();
                if (! $user instanceof StandActor) {
                    return null;
                }

                $tokenRow = DB::table('stand_tokens')
                    ->where('user_id', $user->getKey())
                    ->orderByDesc('created_at')
                    ->first();

                return new CallContext(
                    tenantId: $tokenRow !== null ? (string) $tokenRow->tenant_id : '61',
                    projectScope: $tokenRow !== null ? (string) $tokenRow->project_scope : 'default',
                    effectiveSubject: 'user/'.$user->getKey(),
                    profile: $tokenRow !== null ? (string) $tokenRow->profile : 'documents',
                    policyRevision: $tokenRow !== null ? (string) $tokenRow->policy_revision : 'stand-rev-1',
                    driveId: $tokenRow !== null ? (string) $tokenRow->drive_id : null,
                    actor: $user,
                );
            }
        });

        $this->app->singleton(RunContextResolver::class, static fn (): RunContextResolver => new class implements RunContextResolver
        {
            public function resolve(string $tenantId, string $runId): ?CallContext
            {
                $row = DB::table('stand_run_contexts')
                    ->where('tenant_id', $tenantId)
                    ->where('run_id', $runId)
                    ->first();
                if ($row !== null) {
                    $actor = StandActor::query()->find($row->user_id);
                    if ($actor === null) {
                        return null;
                    }

                    return new CallContext(
                        tenantId: (string) $row->tenant_id,
                        projectScope: (string) $row->project_scope,
                        effectiveSubject: 'user/'.$actor->getKey(),
                        profile: (string) $row->profile,
                        policyRevision: (string) $row->policy_revision,
                        driveId: (string) $row->drive_id,
                        actor: $actor,
                    );
                }

                $runRow = DB::table('v_ai_runs')
                    ->where('tenant_id', $tenantId)
                    ->where('run_id', $runId)
                    ->first();
                if ($runRow === null) {
                    return null;
                }

                $subject = (string) $runRow->effective_subject;
                $actorId = str_starts_with($subject, 'user/') ? (int) substr($subject, 5) : 0;
                $actor = StandActor::query()->find($actorId);
                if ($actor === null) {
                    return null;
                }

                $tokenRow = DB::table('stand_tokens')
                    ->where('tenant_id', $tenantId)
                    ->where('project_scope', (string) $runRow->project_scope)
                    ->where('user_id', $actor->getKey())
                    ->orderByDesc('created_at')
                    ->first();

                return new CallContext(
                    tenantId: (string) $runRow->tenant_id,
                    projectScope: (string) $runRow->project_scope,
                    effectiveSubject: $subject,
                    profile: $tokenRow !== null ? (string) $tokenRow->profile : 'documents',
                    policyRevision: (string) $runRow->profile_revision,
                    driveId: $tokenRow !== null ? (string) $tokenRow->drive_id : null,
                    actor: $actor,
                );
            }
        });

        $this->app->singleton(ApprovedCommandContextResolver::class, static fn (): ApprovedCommandContextResolver => new class implements ApprovedCommandContextResolver
        {
            public function resolve(string $tenantId, string $proposalId): ?CallContext
            {
                $route = DB::table('v_ai_owner_routes')
                    ->where('tenant_id', $tenantId)
                    ->where('proposal_id', $proposalId)
                    ->first();

                $proposalRow = DB::table('v_ai_proposals')
                    ->where('tenant_id', $tenantId)
                    ->where('proposal_id', $proposalId)
                    ->first();

                $source = $route ?? $proposalRow;
                if ($source === null) {
                    return null;
                }

                $subject = (string) $source->effective_subject;
                $projectScope = (string) $source->project_scope;
                $actorId = str_starts_with($subject, 'user/') ? (int) substr($subject, 5) : 0;
                $actor = StandActor::query()->find($actorId);
                if ($actor === null) {
                    return null;
                }

                $command = $proposalRow !== null && is_string($proposalRow->command ?? null) ? $proposalRow->command : '';
                $profile = str_starts_with($command, 'sheet.') ? 'sheets' : 'documents';
                $tokenRow = DB::table('stand_tokens')
                    ->where('tenant_id', $tenantId)
                    ->where('project_scope', $projectScope)
                    ->where('user_id', $actor->getKey())
                    ->orderByDesc('created_at')
                    ->first();

                return new CallContext(
                    tenantId: (string) $source->tenant_id,
                    projectScope: $projectScope,
                    effectiveSubject: $subject,
                    profile: $profile,
                    policyRevision: $tokenRow !== null ? (string) $tokenRow->policy_revision : 'stand-rev-1',
                    driveId: $tokenRow !== null ? (string) $tokenRow->drive_id : null,
                    actor: $actor,
                );
            }
        });
    }

    public function boot(): void
    {
        $profile = (string) config('stand.profile', 'ai-text');
        config()->set('vaulter.ids.strategy', 'uuid7');
        config()->set('vaulter.ids.host_keys', 'bigint');
        config()->set('vaulter.routes.middleware.authenticated', ['api']);
        config()->set('vaulter-drive.access.permission_driver', 'database');
        config()->set('vaulter-ai-documents.default_tenant_id', '61');
        config()->set('vaulter-ai-documents.default_project_scope', 'default');
        config()->set('vaulter-ai-documents.read.live_fallback_on_lag', true);
        $approval = (string) config('stand.approval', 'automatic');
        if (! in_array($approval, ['automatic', 'human'], true)) {
            $approval = 'automatic';
        }
        config()->set('vaulter-ai.profiles.documents', [
            'executor' => $profile === 'ai-agentools' ? 'agentools' : 'laravel-ai',
            'provider_connection' => 'office_model',
            'commands' => ['text.replace_exact@1'],
            'approval' => $approval,
            'revision' => 'stand-rev-1',
        ]);
        config()->set('vaulter-ai.profiles.sheets', [
            'executor' => $profile === 'ai-agentools' ? 'agentools' : 'laravel-ai',
            'provider_connection' => 'office_model',
            'commands' => ['sheet.fill_formula@1'],
            'approval' => $approval,
            'revision' => 'stand-rev-1',
        ]);

        if ($this->app->bound(ModuleSchemas::class)) {
            $this->loadMigrationsFrom($this->app->make(ModuleSchemas::class)->migrationPaths());
        }
        $this->loadMigrationsFrom(base_path('database/migrations'));

        Artisan::command('stand:verify-schema', function (): int {
            $requiredTables = ['v_ai_runs', 'v_ai_run_steps', 'v_ai_proposals', 'v_ai_owner_routes', 'v_ai_text_commands', 'v_outbox', 'jobs'];
            foreach ($requiredTables as $table) {
                if (! Schema::hasTable($table)) {
                    $this->error("Missing required table: {$table}");

                    return 1;
                }
            }

            $profile = config('stand.profile');
            $registry = app(OfficeRegistry::class);
            if ($profile === 'ai-text') {
                if ($registry->executor('laravel-ai') === null || $registry->executor('agentools') !== null) {
                    $this->error('Invalid executor composition for ai-text.');

                    return 1;
                }
            } elseif ($profile === 'ai-agentools') {
                if ($registry->executor('agentools') === null || $registry->executor('laravel-ai') !== null) {
                    $this->error('Invalid executor composition for ai-agentools.');

                    return 1;
                }
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                $missing = DB::selectOne(<<<'SQL'
                WITH fks AS (
                    SELECT c.conrelid, c.conname, c.conkey
                    FROM pg_catalog.pg_constraint c
                    JOIN pg_catalog.pg_class r ON r.oid = c.conrelid
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.connamespace
                    WHERE c.contype = 'f'
                      AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                      AND (r.relname LIKE 'v\_ai\_%' ESCAPE '\' OR r.relname LIKE 'stand\_%' ESCAPE '\')
                ),
                valid_indexes AS (
                    SELECT i.indrelid, (string_to_array(i.indkey::text, ' '))::smallint[] AS indkey_arr
                    FROM pg_catalog.pg_index i
                    WHERE i.indisvalid AND i.indisready AND i.indpred IS NULL
                )
                SELECT string_agg(fk.conrelid::regclass::text || '.' || fk.conname, ', ' ORDER BY fk.conrelid::regclass::text, fk.conname) AS missing
                FROM fks fk
                LEFT JOIN valid_indexes idx
                  ON idx.indrelid = fk.conrelid
                 AND idx.indkey_arr[1:cardinality(fk.conkey)] = fk.conkey
                WHERE idx.indrelid IS NULL
                SQL);

                if ($missing !== null && $missing->missing !== null) {
                    $this->error('Unindexed foreign keys: '.$missing->missing);

                    return 1;
                }
            }

            $this->info('Schema and executor verification passed for profile ['.$profile.'].');

            return 0;
        });

        if (config('stand.deterministic_script') === true && class_exists(OfficeAgent::class)) {
            $runs = $this->app->make(Runs::class);
            $routes = $this->app->make(OwnerRoutes::class);
            $db = $this->app->make(OfficeDatabase::class);

            OfficeAgent::fake(static function (string $prompt) use ($runs, $routes, $db): mixed {
                $activeRunId = Context::get('vaulter.ai.run_id');
                $runCtx = is_string($activeRunId) && $activeRunId !== ''
                    ? DB::table('stand_run_contexts')->where('run_id', $activeRunId)->first()
                    : DB::table('stand_run_contexts')->orderByDesc('created_at')->first();
                if ($runCtx === null) {
                    return 'No stand run context found.';
                }
                $actor = StandActor::query()->find($runCtx->user_id);
                if ($actor === null) {
                    return 'Actor missing.';
                }
                $context = new CallContext(
                    tenantId: (string) $runCtx->tenant_id,
                    projectScope: (string) $runCtx->project_scope,
                    effectiveSubject: 'user/'.$actor->getKey(),
                    profile: (string) $runCtx->profile,
                    policyRevision: (string) $runCtx->policy_revision,
                    driveId: (string) $runCtx->drive_id,
                    actor: $actor,
                );

                $steps = $runs->listSteps($context, (string) $runCtx->run_id);
                $ops = array_map(static fn ($s): string => $s->operation->value, $steps);
                $latest = static function (OfficeOperation $op) use ($steps): ?array {
                    $match = null;
                    foreach ($steps as $s) {
                        if ($s->operation === $op) {
                            $match = $s->compactResult;
                        }
                    }

                    return $match;
                };

                if (! in_array(OfficeOperation::Read->value, $ops, true)) {
                    return new ToolCall('stand_tc_read', 'office_read', [
                        'resource_id' => (string) $runCtx->resource_id,
                        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
                    ]);
                }
                if (! in_array(OfficeOperation::Prepare->value, $ops, true)) {
                    return new ToolCall('stand_tc_prepare', 'office_prepare', [
                        'resource_id' => (string) $runCtx->resource_id,
                        'command' => 'text.replace_exact@1',
                        'read_token' => (string) ($latest(OfficeOperation::Read)['result']['read_token'] ?? ''),
                        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
                    ]);
                }

                $prepareRes = $latest(OfficeOperation::Prepare)['result'] ?? [];
                $proposalId = (string) ($prepareRes['proposal_id'] ?? '');
                $digest = (string) ($prepareRes['digest'] ?? '');

                $commitStep = $runs->findCommitStepByProposal($context, (string) $runCtx->run_id, $proposalId);
                if ($commitStep === null || $commitStep->state === 'dispatched') {
                    // If kill fault requested before settling owner route, arm one-shot SIGKILL hook and run commit.
                    if ($runCtx->fault_mode === 'kill_after_owner_commit') {
                        DB::table('stand_run_contexts')
                            ->where('run_id', $runCtx->run_id)
                            ->update(['fault_mode' => null]);

                        $routesTable = $db->table('ai_owner_routes');
                        $db->connection()->beforeExecuting(static function (string $sql) use ($routesTable): void {
                            if (str_contains($sql, $routesTable) && str_contains(strtolower($sql), 'update') && str_contains($sql, 'receipt_id')) {
                                if (function_exists('posix_kill')) {
                                    posix_kill(getmypid(), SIGKILL);
                                }
                                throw new RuntimeException('fault injected');
                            }
                        });
                    }

                    return new ToolCall('stand_tc_commit_'.($commitStep === null ? '1' : 'retry_2'), 'office_commit', [
                        'resource_id' => (string) $runCtx->resource_id,
                        'proposal_id' => $proposalId,
                        'digest' => $digest,
                    ]);
                }

                if (! in_array(OfficeOperation::Verify->value, $ops, true)) {
                    $receiptId = $commitStep->receiptId ?? $routes->forProposal($context, $proposalId)?->receiptId ?? '';

                    return new ToolCall('stand_tc_verify', 'office_verify', [
                        'resource_id' => (string) $runCtx->resource_id,
                        'receipt_id' => (string) $receiptId,
                        'mode' => 'at_commit',
                    ]);
                }

                return 'Completed Office task and verified receipt.';
            });
        }
    }
}
