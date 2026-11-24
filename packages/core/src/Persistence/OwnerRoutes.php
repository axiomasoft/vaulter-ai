<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Database\Query\Builder;
use LogicException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OwnerRoute;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\RequestFingerprint;

final readonly class OwnerRoutes
{
    public function __construct(private OfficeDatabase $db, private Clock $clock) {}

    public function find(CommandScope $scope): ?OwnerRoute
    {
        return $this->db->transaction(fn (): ?OwnerRoute => $this->record($this->query($scope)->first()), $scope->context->tenantId);
    }

    public function byReceipt(CallContext $context, string $resourceId, string $receiptId): ?OwnerRoute
    {
        return $this->db->transaction(fn (): ?OwnerRoute => $this->record($this->db->query('ai_owner_routes')
            ->where($this->scope($context, $resourceId))->where('receipt_id', $receiptId)->first()), $context->tenantId);
    }

    public function forProposal(CallContext $context, string $proposalId): ?OwnerRoute
    {
        return $this->db->transaction(fn (): ?OwnerRoute => $this->record($this->db->query('ai_owner_routes')
            ->where('tenant_id', $context->tenantId)->where('project_scope', $context->projectScope)
            ->where('effective_subject', $context->effectiveSubject)->where('proposal_id', $proposalId)
            ->first()), $context->tenantId);
    }

    public function forProposalInScope(CallContext $viewer, string $initiatorSubject, string $proposalId): ?OwnerRoute
    {
        return $this->db->transaction(fn (): ?OwnerRoute => $this->record($this->db->query('ai_owner_routes')
            ->where('tenant_id', $viewer->tenantId)->where('project_scope', $viewer->projectScope)
            ->where('effective_subject', $initiatorSubject)->where('proposal_id', $proposalId)
            ->first()), $viewer->tenantId);
    }

    /**
     * @param  list<string>  $proposalIds
     * @return array<string, OwnerRoute>
     */
    public function forProposalsInTransaction(CallContext $viewer, array $proposalIds): array
    {
        if ($proposalIds === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique($proposalIds)), 500) as $chunk) {
            $rows = $this->db->query('ai_owner_routes')
                ->where('tenant_id', $viewer->tenantId)
                ->where('project_scope', $viewer->projectScope)
                ->whereIn('proposal_id', $chunk)
                ->get();
            foreach ($rows as $row) {
                $route = $this->record($row);
                if ($route !== null) {
                    $out[$route->proposalId] = $route;
                }
            }
        }

        return $out;
    }

    public function bind(CommandScope $scope, PreparedPlan $plan): OwnerRoute
    {
        if ($this->db->connection()->transactionLevel() !== 0) {
            throw new LogicException('An Office command route must be committed before calling its owner.');
        }

        return $this->db->transaction(fn (): OwnerRoute => $this->bindInTransaction($scope, $plan), $scope->context->tenantId);
    }

    public function bindInTransaction(CommandScope $scope, PreparedPlan $plan): OwnerRoute
    {
        $now = $this->clock->now();
        $fingerprint = RequestFingerprint::of($plan->command, $plan->proposalId, $plan->digest);
        $this->db->query('ai_owner_routes')->insertOrIgnore($this->scope($scope->context, $scope->resourceId) + [
            'command_id' => $scope->commandId,
            'proposal_id' => $plan->proposalId,
            'request_fingerprint' => $this->db->binaryLiteral($fingerprint),
            'owner_key' => $plan->ownerKey,
            'created_at' => $this->db->utcTimestamp($now),
            'dedupe_until' => $this->db->utcTimestamp($now->modify('+7 days')),
        ]);
        $route = $this->record($this->query($scope)->first());
        if ($route === null || $route->proposalId !== $plan->proposalId || ! hash_equals($route->fingerprint, $fingerprint)) {
            throw new OfficeException(ErrorCode::IdempotencyConflict);
        }

        return $route;
    }

    public function settle(CommandScope $scope, string $receiptId): void
    {
        $this->db->transaction(fn () => $this->settleInTransaction($scope, $receiptId), $scope->context->tenantId);
    }

    public function settleInTransaction(CommandScope $scope, string $receiptId): void
    {
        $this->query($scope)->whereNull('receipt_id')->update(['receipt_id' => $receiptId]);
        if ($this->query($scope)->value('receipt_id') !== $receiptId) {
            throw new LogicException('The owner returned a different receipt for a settled command.');
        }
    }

    private function query(CommandScope $scope): Builder
    {
        return $this->db->query('ai_owner_routes')
            ->where($this->scope($scope->context, $scope->resourceId))
            ->where('command_id', $scope->commandId);
    }

    /** @return array<string, string> */
    private function scope(CallContext $context, string $resourceId): array
    {
        return [
            'tenant_id' => $context->tenantId,
            'project_scope' => $context->projectScope,
            'effective_subject' => $context->effectiveSubject,
            'resource_id' => $resourceId,
        ];
    }

    private function record(?object $row): ?OwnerRoute
    {
        if ($row === null) {
            return null;
        }

        $receipt = QueryRecord::raw($row, 'receipt_id');

        return new OwnerRoute(
            QueryRecord::str($row, 'command_id'),
            QueryRecord::str($row, 'resource_id'),
            QueryRecord::str($row, 'proposal_id'),
            $this->db->decodeBinary(QueryRecord::raw($row, 'request_fingerprint')),
            QueryRecord::str($row, 'owner_key'),
            is_string($receipt) ? $receipt : null,
            $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'dedupe_until')) ?? throw new LogicException('Invalid route expiration.'),
        );
    }
}
