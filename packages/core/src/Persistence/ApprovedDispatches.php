<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Database\Query\Builder;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CommandScope;

final readonly class ApprovedDispatches
{
    public function __construct(private OfficeDatabase $db, private OwnerRoutes $routes, private Clock $clock) {}

    public function claim(CommandScope $scope, string $proposalId): ?string
    {
        return $this->db->transaction(function () use ($scope, $proposalId): ?string {
            $query = $this->query($scope, $proposalId);
            // Take the row lock before reading the clock on PostgreSQL. SQLite's write CAS serializes claimers.
            if ($this->db->driver() === 'pgsql' && (clone $query)->lockForUpdate()->first(['event_id']) === null) {
                return null;
            }
            $lease = $this->db->newUuid();
            $now = $this->clock->now();
            $stamp = $this->db->utcTimestamp($now);
            $claimed = $query->where(function (Builder $candidate) use ($stamp): void {
                $candidate->where('state', 'ready')->where('available_at', '<=', $stamp)
                    ->orWhere(fn (Builder $leased): Builder => $leased->where('state', 'leased')->where('lease_until', '<=', $stamp));
            })->increment('attempts', 1, [
                'state' => 'leased',
                'lease_token' => $lease,
                'lease_until' => $this->db->utcTimestamp($now->modify('+60 seconds')),
            ]);

            return $claimed === 1 ? $lease : null;
        }, $scope->context->tenantId);
    }

    /** @param 'ready'|'delivered'|'exhausted' $state */
    public function finish(CommandScope $scope, string $proposalId, string $lease, string $state, ?string $receiptId = null): bool
    {
        return $this->db->transaction(function () use ($scope, $proposalId, $lease, $state, $receiptId): bool {
            $query = $this->query($scope, $proposalId)
                ->where('state', 'leased')->where('lease_token', $lease);
            if ($this->db->driver() === 'pgsql' && (clone $query)->lockForUpdate()->first(['event_id']) === null) {
                return false;
            }
            $now = $this->db->utcTimestamp($this->clock->now());
            $changed = $query->where('lease_until', '>', $now)->update([
                'state' => $state, 'available_at' => $now, 'lease_token' => null, 'lease_until' => null,
            ]);
            if ($changed !== 1) {
                return false;
            }
            if ($receiptId !== null) {
                $this->routes->settleInTransaction($scope, $receiptId);
            }

            return true;
        }, $scope->context->tenantId);
    }

    private function query(CommandScope $scope, string $proposalId): Builder
    {
        return $this->db->query('ai_dispatches')->where('proposal_id', $proposalId)->whereExists(
            $this->db->query('ai_owner_routes')->select('proposal_id')->where([
                'proposal_id' => $proposalId,
                'tenant_id' => $scope->context->tenantId,
                'project_scope' => $scope->context->projectScope,
                'effective_subject' => $scope->context->effectiveSubject,
                'resource_id' => $scope->resourceId,
                'command_id' => $scope->commandId,
            ]),
        );
    }
}
