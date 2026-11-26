<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Query\Builder;
use Throwable;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Jobs\ResumeOfficeRun;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\QueryRecord;

final class RecoverOfficeRuns extends Command
{
    protected $signature = 'office:recover-runs {--limit=100 : Maximum expired leases per table}';

    protected $description = 'Release expired Office worker leases and queue recovery';

    public function handle(OfficeDatabase $db, Clock $clock, Dispatcher $bus): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $stamp = $db->utcTimestamp($db->clockNow($clock));
        $expired = ['lease_token' => null, 'lease_until' => null];
        $queueFailures = 0;
        $counts = $db->transaction(function () use ($db, $bus, $limit, $stamp, $expired, &$queueFailures): array {
            $runs = $db->query('ai_runs')->where('lease_until', '<=', $stamp);
            $calls = $db->query('ai_tool_calls')->where('lease_until', '<=', $stamp);
            $dispatches = $db->query('ai_dispatches')->where('lease_until', '<=', $stamp)->where('state', 'leased');
            $runCount = 0;
            foreach ($this->candidates($db, $runs, $limit, 'run_id')->get() as $row) {
                $runId = QueryRecord::str($row, 'run_id');
                $state = QueryRecord::str($row, 'state');
                $nextState = $state === 'running' ? 'reconcile' : $state;
                $changed = (clone $runs)->where('run_id', $runId)->where('lease_token', QueryRecord::str($row, 'lease_token'))
                    ->update($expired + ['state' => $nextState, 'updated_at' => $stamp]);
                $runCount += $changed;
                if ($changed === 1 && ! in_array($state, ['completed', 'rejected'], true)) {
                    $restore = $db->query('ai_runs')->where('run_id', $runId)
                        ->where('state', $nextState)->where('updated_at', $stamp)->whereNull('lease_token');
                    $this->dispatch($db, $bus, new ResumeOfficeRun(QueryRecord::str($row, 'tenant_id'), $runId),
                        $restore, $row, $state, $queueFailures);
                }
            }
            $callCount = 0;
            foreach ($this->candidates($db, $calls, $limit, 'run_id')->orderBy('call_id')->get() as $row) {
                $callCount += (clone $calls)->where([
                    'run_id' => QueryRecord::str($row, 'run_id'),
                    'call_id' => QueryRecord::str($row, 'call_id'),
                    'lease_token' => QueryRecord::str($row, 'lease_token'),
                ])->update($expired + ['updated_at' => $stamp]);
            }
            $dispatchCount = 0;
            foreach ($this->candidates($db, $dispatches, $limit, 'event_id')->get() as $row) {
                $proposal = QueryRecord::str($row, 'proposal_id');
                $changed = (clone $dispatches)->where('event_id', QueryRecord::str($row, 'event_id'))
                    ->where('lease_token', QueryRecord::str($row, 'lease_token'))
                    ->update($expired + ['state' => 'ready', 'available_at' => $stamp]);
                $dispatchCount += $changed;
                $tenant = $db->query('ai_owner_routes')->where('proposal_id', $proposal)->value('tenant_id');
                if ($changed === 1 && is_string($tenant)) {
                    $restore = $db->query('ai_dispatches')->where('proposal_id', $proposal)->where('state', 'ready')
                        ->where('available_at', $stamp)->where('attempts', QueryRecord::int($row, 'attempts'))->whereNull('lease_token');
                    $this->dispatch($db, $bus, new DispatchApprovedCommand($tenant, $proposal), $restore, $row, 'leased', $queueFailures);
                }
            }

            return [$runCount, $callCount, $dispatchCount];
        });
        $this->info(sprintf('Recovered %d run, %d tool and %d dispatch leases.', ...$counts));
        if ($queueFailures > 0) {
            $this->error("Could not queue {$queueFailures} recoveries. Their expired leases can be recovered again.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function dispatch(
        OfficeDatabase $db, Dispatcher $bus, ResumeOfficeRun|DispatchApprovedCommand $job,
        Builder $restore, object $row, string $state, int &$failures,
    ): void {
        $db->afterCommit(static function () use ($bus, $job, $restore, $row, $state, &$failures): void {
            try {
                $bus->dispatch($job);
            } catch (Throwable) {
                $failures++;
                // A concurrent claimant may have taken the work since the transaction committed.
                $restore->update([
                    'state' => $state,
                    'lease_token' => QueryRecord::str($row, 'lease_token'),
                    'lease_until' => QueryRecord::str($row, 'lease_until'),
                ]);
            }
        });
    }

    private function candidates(OfficeDatabase $db, Builder $query, int $limit, string $id): Builder
    {
        $candidates = (clone $query)->orderBy('lease_until')->orderBy($id)->limit($limit);

        return $db->driver() === 'pgsql' ? $candidates->lock('for update skip locked') : $candidates;
    }
}
