<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;

final class PruneOfficeData extends Command
{
    protected $signature = 'office:prune-data {--limit=100 : Maximum expired records per table}';

    protected $description = 'Prune expired Office data and retain command identity for reconciliation';

    public function handle(OfficeDatabase $db, Clock $clock, ConfigRepository $config): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $stamp = $db->utcTimestamp($db->clockNow($clock));
        $total = $db->transaction(function () use ($db, $config, $stamp, $limit): int {
            $total = 0;
            foreach ([
                ['ai_read_tokens', 'token_hash', 'expires_at'],
                ['ai_search_pages', 'snapshot_id', 'expires_at'],
                ['ai_run_inputs', 'run_id', 'expires_at'],
                ['ai_chat_sessions', 'session_id', 'retention_until'],
            ] as [$table, $id, $expiry]) {
                $query = $db->query($table)->where($expiry, '<=', $stamp);
                $total += $this->deleteBatch($query, $id, $expiry, $limit);
            }

            $proposals = $db->query('ai_proposals')->where('expires_at', '<=', $stamp);
            $activeDispatches = $db->query('ai_dispatches')->select('proposal_id')->whereIn('state', ['ready', 'leased']);
            $proposalIds = (clone $proposals)->select('proposal_id')->whereNotIn('proposal_id', $activeDispatches);
            $payloads = $db->query('ai_proposal_payloads')->whereIn('proposal_id', $proposalIds);
            $total += $this->deleteBatch($payloads, 'proposal_id', 'proposal_id', $limit);
            $unreferenced = (clone $proposals)
                ->whereNotIn('proposal_id', $db->query('ai_owner_routes')->select('proposal_id'))
                ->whereNotIn('proposal_id', $db->query('ai_tool_calls')->select('proposal_id')->whereNotNull('proposal_id'))
                ->whereNotIn('proposal_id', $db->query('ai_run_steps')->select('proposal_id')->whereNotNull('proposal_id'));
            $total += $this->deleteBatch($unreferenced, 'proposal_id', 'expires_at', $limit);

            $prefix = $config->get('vaulter.database.table_prefix', 'v_');
            $evidence = is_string($prefix) && preg_match('/\A[a-z][a-z0-9_]*_\z/', $prefix) === 1
                ? $prefix.'ai_text_command_evidence'
                : $db->table('ai_text_command_evidence');
            if ($db->connection()->getSchemaBuilder()->hasTable($evidence)) {
                $total += $this->deleteBatch($db->connection()->table($evidence)->where('expires_at', '<=', $stamp),
                    'receipt_id', 'expires_at', $limit);
            }

            return $total;
        });
        $this->info("Pruned {$total} expired records.");

        return self::SUCCESS;
    }

    private function deleteBatch(Builder $query, string $id, string $order, int $limit): int
    {
        $batch = (clone $query)->select($id)->orderBy($order)->limit($limit);
        if ($order !== $id) {
            $batch->orderBy($id);
        }

        return $query->whereIn($id, $batch)->delete();
    }
}
