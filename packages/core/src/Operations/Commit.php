<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use DateTimeImmutable;
use LogicException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\OwnerRoute;
use Vaulter\Ai\Core\Data\StoredProposal;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Protocol\RequestFingerprint;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class Commit
{
    public function __construct(
        private ResourceDirectory $directory,
        private HostPolicy $policy,
        private OfficeRegistry $registry,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private Clock $clock,
        private ?ProposalApprovalPolicy $approvalPolicy = null,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $resourceId = $input->resource_id ?? null;
        $commandId = $input->command_id ?? null;
        $proposalId = $input->proposal_id ?? null;
        $digest = $input->digest ?? null;
        if (! is_string($resourceId) || ! is_string($commandId) || ! is_string($proposalId) || ! is_string($digest)) {
            throw OfficeException::invalidInput();
        }

        $scope = new CommandScope($context, $resourceId, $commandId);
        $route = $this->routes->find($scope);
        $proposal = $this->proposals->find($context, $resourceId, $route->proposalId ?? $proposalId);
        if ($proposal === null || ($route === null && ! $proposal->hasPayload)) {
            throw OfficeException::resourceUnavailable();
        }
        $this->authorize($scope, $proposal);

        $plan = $proposal->plan;
        if ($route === null) {
            if (! hash_equals($plan->digest, $digest)) {
                throw OfficeException::preconditionFailed();
            }
            if ($proposal->decision !== 'approved') {
                throw new OfficeException($proposal->decision === 'pending' ? ErrorCode::ApprovalRequired : ErrorCode::PreconditionFailed);
            }
            if (new DateTimeImmutable($plan->expiresAt) <= $this->clock->now()) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }
            if ($this->registry->owner($plan->ownerKey) === null) {
                throw OfficeException::temporarilyUnavailable();
            }
            $route = $this->routes->bind($scope, $plan);
        }

        $outcome = $this->execute($scope, $proposal, $route, $proposalId, $digest);
        if ($outcome->state === 'rejected') {
            throw new OfficeException($outcome->reason ?? throw new LogicException('The owner omitted the rejection reason.'));
        }
        if ($outcome->receiptId !== null && $outcome->receiptId !== $route->receiptId) {
            $this->routes->settle($scope, $outcome->receiptId);
        }

        return CommandResult::response(OfficeOperation::Commit, $outcome);
    }

    public function authorize(CommandScope $scope, StoredProposal $proposal): void
    {
        $entry = $this->directory->findAccessible($scope->context, $scope->resourceId, 'edit');
        $profile = $this->policy->profile($scope->context);
        if ($entry === null || ! $this->policy->allowsCommand($scope->context, $entry, $proposal->plan->command)) {
            throw OfficeException::resourceUnavailable();
        }
        if ($profile->revision !== $proposal->policyRevision || $profile->approval !== $proposal->plan->approval) {
            throw OfficeException::preconditionFailed();
        }
        if ($proposal->plan->approval === ApprovalMode::Human
            && $proposal->decision === 'approved'
            && $this->approvalPolicy !== null
            && ! $this->approvalPolicy->isApprovalStillValidForCommit($scope->context, $entry, $proposal)) {
            throw OfficeException::resourceUnavailable();
        }
    }

    public function execute(CommandScope $scope, StoredProposal $proposal, OwnerRoute $route, string $proposalId, string $digest): CommandOutcome
    {
        if ($route->proposalId !== $proposalId
            || ! hash_equals($route->fingerprint, RequestFingerprint::of($proposal->plan->command, $proposalId, $digest))) {
            throw new OfficeException(ErrorCode::IdempotencyConflict);
        }
        if ($route->dedupeUntil <= $this->clock->now()) {
            throw new OfficeException(ErrorCode::IdempotencyWindowExpired);
        }
        if ($proposal->decision !== 'approved' || ! hash_equals($proposal->plan->digest, $digest)) {
            throw OfficeException::preconditionFailed();
        }
        $owner = $this->registry->owner($route->ownerKey) ?? throw OfficeException::temporarilyUnavailable();
        $outcome = $owner->commit($scope, $proposal->plan);
        if ($outcome->commandId !== $scope->commandId) {
            throw OfficeException::temporarilyUnavailable();
        }

        return $outcome;
    }
}
