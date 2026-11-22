<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Actions;

use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;

/** For the host's authenticated approval application. The two contexts are resolved by the host. */
final readonly class ResolveProposal
{
    public function __construct(
        private OfficeDatabase $db,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private ResourceDirectory $directory,
        private Commit $commit,
        private Dispatcher $bus,
        private Clock $clock,
    ) {}

    /** Returns false if a competing approval or rejection already decided the proposal. */
    public function resolve(CommandScope $scope, string $proposalId, string $digest, CallContext $approver, bool $approved): bool
    {
        $initiator = $scope->context;
        if ($approver->tenantId !== $initiator->tenantId || $approver->projectScope !== $initiator->projectScope
            || $this->directory->findAccessible($approver, $scope->resourceId, 'edit') === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $this->db->transaction(function () use ($scope, $proposalId, $digest, $approver, $approved, $initiator): bool {
            $proposal = $this->proposals->findInTransaction($initiator, $scope->resourceId, $proposalId, lock: true);
            if ($proposal === null || ! $proposal->hasPayload) {
                throw OfficeException::resourceUnavailable();
            }
            $this->commit->authorize($scope, $proposal);
            $plan = $proposal->plan;
            if ($plan->approval !== ApprovalMode::Human || ! hash_equals($plan->digest, $digest)) {
                throw OfficeException::preconditionFailed();
            }
            if ($proposal->decision !== 'pending') {
                return false;
            }
            if (new DateTimeImmutable($plan->expiresAt) <= $this->clock->now()) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }
            if (! $this->proposals->decide($initiator, $scope->resourceId, $proposalId, $approver->effectiveSubject, $approved)) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }
            if ($approved) {
                $this->routes->bindInTransaction($scope, $plan);
                $this->db->query('ai_dispatches')->insert([
                    'event_id' => $this->db->newUuid(),
                    'proposal_id' => $proposalId,
                    'available_at' => $this->db->utcTimestamp($this->clock->now()),
                ]);
                $this->db->afterCommit(function () use ($initiator, $proposalId): void {
                    $this->bus->dispatch(new DispatchApprovedCommand($initiator->tenantId, $proposalId));
                });
            }

            return true;
        }, $initiator->tenantId);
    }
}
