<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use Illuminate\Support\Str;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\RequestFingerprint;

/**
 * Persistently maps an AgenTools action/idempotency key and proposal_id to a single Office command_id
 * inside v_ai_run_steps so repeat planning runs and retries never mint a second mutation key.
 */
final readonly class ActionKeyMap
{
    public function __construct(
        private Runs $runs,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private ResolveProposal $resolver,
        private Commit $commit,
    ) {}

    /**
     * Resolve or create the single durable commit step binding (run_id, proposal_id, actionKey) -> command_id.
     */
    public function bindForCommit(
        CallContext $context,
        string $runId,
        string $actionKey,
        string $resourceId,
        string $proposalId,
        string $digest,
        ?string $preferredCommandId = null,
    ): OfficeRunStep {
        if ($actionKey === '') {
            throw OfficeException::invalidInput('AgenTools action idempotency key must not be empty.');
        }

        $proposal = $this->proposals->find($context, $resourceId, $proposalId);
        $existingRoute = $this->routes->forProposal($context, $proposalId);
        if ($proposal === null || ($existingRoute === null && ! $proposal->hasPayload)) {
            throw OfficeException::resourceUnavailable();
        }

        $byProposal = $this->runs->findCommitStepByProposal($context, $runId, $proposalId);
        if ($byProposal !== null) {
            if ($byProposal->resourceId !== $resourceId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
            if ($preferredCommandId !== null && $preferredCommandId !== '' && $byProposal->commandId !== $preferredCommandId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
            $savedDigest = is_string($byProposal->resumeInput['digest'] ?? null)
                ? $byProposal->resumeInput['digest']
                : null;
            if ($savedDigest !== null && ! hash_equals($savedDigest, $digest)) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }

            $other = $this->runs->findStepByActionKey($context, $runId, $actionKey);
            if ($other !== null && $other->stepId !== $byProposal->stepId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }

            $authCommandId = $byProposal->commandId ?? 'cmd-'.Str::uuid7();
            $this->commit->authorize(new CommandScope($context, $resourceId, $authCommandId), $proposal);
            if ($existingRoute !== null
                && ($existingRoute->resourceId !== $resourceId
                    || ! hash_equals($existingRoute->fingerprint, RequestFingerprint::of($proposal->plan->command, $proposalId, $digest)))) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
            if (! hash_equals($proposal->plan->digest, $digest)) {
                throw OfficeException::preconditionFailed();
            }

            return $this->runs->beginCommitStep(
                context: $context,
                runId: $runId,
                resourceId: $resourceId,
                proposalId: $proposalId,
                digest: $digest,
                initialCommandId: $authCommandId,
                ownerKey: $proposal->plan->ownerKey,
                executorActionKey: $actionKey,
            );
        }

        $byActionKey = $this->runs->findStepByActionKey($context, $runId, $actionKey);
        if ($byActionKey !== null) {
            throw new OfficeException(ErrorCode::IdempotencyConflict);
        }

        if ($existingRoute !== null) {
            if ($existingRoute->resourceId !== $resourceId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
            if ($preferredCommandId !== null && $preferredCommandId !== '' && $existingRoute->commandId !== $preferredCommandId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
            if (! hash_equals($existingRoute->fingerprint, RequestFingerprint::of($proposal->plan->command, $proposalId, $digest))) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
        }

        $commandId = $existingRoute !== null
            ? $existingRoute->commandId
            : ($preferredCommandId !== null && $preferredCommandId !== '' ? $preferredCommandId : $this->deterministicCommandId($actionKey));

        if ($existingRoute === null) {
            $routeByCmd = $this->routes->find(new CommandScope($context, $resourceId, $commandId));
            if ($routeByCmd !== null && $routeByCmd->proposalId !== $proposalId) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }
        }

        $this->commit->authorize(new CommandScope($context, $resourceId, $commandId), $proposal);
        if (! hash_equals($proposal->plan->digest, $digest)) {
            throw OfficeException::preconditionFailed();
        }

        return $this->runs->beginCommitStep(
            context: $context,
            runId: $runId,
            resourceId: $resourceId,
            proposalId: $proposalId,
            digest: $digest,
            initialCommandId: $commandId,
            ownerKey: $proposal->plan->ownerKey,
            executorActionKey: $actionKey,
        );
    }

    public function resolveCommandId(
        CallContext $context,
        string $runId,
        string $proposalId,
        ?string $actionKey = null,
    ): ?string {
        $step = $this->runs->findCommitStepByProposal($context, $runId, $proposalId);
        if ($step !== null) {
            return $step->commandId;
        }

        if ($actionKey !== null && $actionKey !== '') {
            $byKey = $this->runs->findStepByActionKey($context, $runId, $actionKey);
            if ($byKey !== null && $byKey->proposalId === $proposalId) {
                return $byKey->commandId;
            }
        }

        return $this->routes->forProposal($context, $proposalId)?->commandId;
    }

    /**
     * Bind a host human confirmation (P/Q) decision to the mapped Office command_id and digest.
     *
     * @return array{decided: bool, command_id: string}
     */
    public function bindConfirmedApproval(
        CallContext $initiator,
        string $runId,
        string $actionKey,
        string $resourceId,
        string $proposalId,
        string $digest,
        CallContext $approver,
        bool $approved,
    ): array {
        if ($actionKey === '') {
            throw OfficeException::invalidInput('AgenTools action idempotency key must not be empty.');
        }

        $proposal = $this->proposals->find($initiator, $resourceId, $proposalId);
        if ($proposal === null) {
            throw OfficeException::resourceUnavailable();
        }
        if (! hash_equals($proposal->plan->digest, $digest)) {
            throw OfficeException::preconditionFailed();
        }

        $step = $this->bindForCommit(
            context: $initiator,
            runId: $runId,
            actionKey: $actionKey,
            resourceId: $resourceId,
            proposalId: $proposalId,
            digest: $digest,
        );
        $commandId = $step->commandId ?? throw OfficeException::temporarilyUnavailable();
        $scope = new CommandScope($initiator, $resourceId, $commandId);
        $decided = $this->resolver->resolve($scope, $proposalId, $digest, $approver, $approved);

        return ['decided' => $decided, 'command_id' => $commandId];
    }

    private function deterministicCommandId(string $actionKey): string
    {
        if (strlen($actionKey) <= 255) {
            return $actionKey;
        }

        return 'at1-'.hash('sha256', $actionKey);
    }
}
