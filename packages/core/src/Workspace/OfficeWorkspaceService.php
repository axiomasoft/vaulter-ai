<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\ResumableOfficeExecutor;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeRun;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Data\StoredProposal;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\RunStateReducer;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Trusted host workspace service backing human review (P/Q), decision resolution,
 * restart-safe run inspection, and partial multi-resource continuation.
 */
final readonly class OfficeWorkspaceService
{
    /** @var list<string> */
    private const array REREAD_ERROR_CODES = [
        'precondition_failed',
        'scope_changed',
        'generation_changed',
        'schema_changed',
        'proposal_expired',
        'read_token_expired',
    ];

    public function __construct(
        private OfficeDatabase $db,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private Runs $runs,
        private ResourceDirectory $directory,
        private ProposalApprovalPolicy $approvalPolicy,
        private ResolveProposal $resolver,
        private Office $office,
        private StepCommitCoordinator $commits,
        private RunStateReducer $stateReducer,
        private OfficeRegistry $registry,
        private ProposalDiffBuilder $diffBuilder,
        private Clock $clock,
        private Container $container,
    ) {}

    /**
     * List visible proposals within the caller's tenant/project scope after strict current ACL checks.
     *
     * @return list<array<string, mixed>>
     */
    public function listProposals(CallContext $viewer, ?string $resourceId = null, ?string $decision = null, int $limit = 50): array
    {
        $rows = $this->proposals->listInScope($viewer, $resourceId, $decision, $limit);
        if ($rows === []) {
            return [];
        }

        $resourceIds = [];
        foreach ($rows as $proposal) {
            $resourceIds[] = $proposal->plan->resourceId;
        }
        $accessible = $this->directory->filterAccessible($viewer, array_values(array_unique($resourceIds)), 'view');

        $out = [];
        foreach ($rows as $proposal) {
            $entry = $accessible[$proposal->plan->resourceId] ?? null;
            if ($entry === null || ! $this->approvalPolicy->canView($viewer, $entry, $proposal)) {
                continue;
            }
            $out[] = $this->formatProposal($viewer, $entry, $proposal);
        }

        return $out;
    }

    /**
     * Inspect a single proposal only if the viewer currently holds view access on its target resource.
     *
     * @return array<string, mixed>
     */
    public function showProposal(CallContext $viewer, string $proposalId): array
    {
        if (! $this->isLowerUuid($proposalId)) {
            throw OfficeException::resourceUnavailable();
        }

        $proposal = $this->proposals->findByIdInScope($viewer, $proposalId);
        if ($proposal === null) {
            throw OfficeException::resourceUnavailable();
        }

        $resource = $this->directory->findAccessible($viewer, $proposal->plan->resourceId, 'view');
        if ($resource === null || ! $this->approvalPolicy->canView($viewer, $resource, $proposal)) {
            throw OfficeException::resourceUnavailable();
        }

        return $this->formatProposal($viewer, $resource, $proposal);
    }

    /**
     * Submit an authenticated reviewer decision bound to the exact saved digest.
     *
     * @return array{won: bool, proposal: array<string, mixed>}
     */
    public function decideProposal(
        CallContext $approver,
        string $proposalId,
        string $digest,
        bool $approved,
        ?string $preferredCommandId = null,
    ): array {
        if (! $this->isLowerUuid($proposalId) || preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            throw OfficeException::invalidInput('proposal_id must be a UUID and digest must be 64 lowercase hex characters.');
        }

        $proposal = $this->proposals->findByIdInScope($approver, $proposalId);
        if ($proposal === null) {
            throw OfficeException::resourceUnavailable();
        }

        $resource = $this->directory->findAccessible($approver, $proposal->plan->resourceId, 'view');
        if ($resource === null || ! $this->approvalPolicy->canApprove($approver, $resource, $proposal)) {
            throw OfficeException::resourceUnavailable();
        }

        $initiatorSubject = $proposal->effectiveSubject ?? '';
        $initiator = $this->resolveInitiatorForProposal($approver, $proposalId, $initiatorSubject);
        if ($initiator === null) {
            throw OfficeException::resourceUnavailable();
        }

        $existingRoute = $this->routes->forProposal($initiator, $proposalId);
        $linkedCommit = $this->runs->findLatestStepForProposalInScope($approver, $proposalId, OfficeOperation::Commit);
        $commandId = 'cmd-'.$this->db->newUuid();
        if ($existingRoute !== null) {
            $commandId = $existingRoute->commandId;
        } elseif ($linkedCommit !== null && $linkedCommit->commandId !== null) {
            $commandId = $linkedCommit->commandId;
        } elseif ($preferredCommandId !== null && $preferredCommandId !== '') {
            $commandId = $preferredCommandId;
        }

        $won = $this->resolver->resolve(
            scope: new CommandScope($initiator, $proposal->plan->resourceId, $commandId),
            proposalId: $proposalId,
            digest: $digest,
            approver: $approver,
            approved: $approved,
        );

        return [
            'won' => $won,
            'proposal' => $this->showProposal($approver, $proposalId),
        ];
    }

    /**
     * Create and initially execute a multi-resource draft run storing per-resource steps, bases, proposals, and outcomes.
     *
     * @param  list<array<string, mixed>>  $draftItems
     * @return array<string, mixed>
     */
    public function createDraftRun(
        CallContext $initiator,
        string $channel,
        string $sourceEventId,
        string $prompt,
        array $draftItems,
        bool $executeImmediately = true,
    ): array {
        if ($draftItems === []) {
            throw OfficeException::invalidInput('draft_items must not be empty.');
        }

        $run = $this->runs->createOrFind(
            context: $initiator,
            channel: $channel,
            sourceEventId: $sourceEventId,
            profileRevision: $initiator->policyRevision,
            inputPayload: [
                'prompt' => $prompt,
                'draft_items' => $draftItems,
            ],
        );

        if ($executeImmediately) {
            return $this->resumeRun($initiator, $run->runId);
        }

        return $this->showRun($initiator, $run->runId);
    }

    /**
     * Inspect a persisted run with current per-resource ACL enforcement and separate confirmation/owner/verify statuses.
     *
     * @return array<string, mixed>
     */
    public function showRun(CallContext $viewer, string $runId): array
    {
        if (! $this->isLowerUuid($runId)) {
            throw OfficeException::resourceUnavailable();
        }

        $run = $this->runs->findInScope($viewer, $runId);
        if ($run === null) {
            throw OfficeException::resourceUnavailable();
        }

        $steps = $this->runs->listStepsInScope($viewer, $runId);
        $resourceIds = $this->collectRunResourceIds($run, $steps);
        $accessible = $resourceIds !== [] ? $this->directory->filterAccessible($viewer, $resourceIds, 'view') : [];

        if ($resourceIds !== [] && $accessible === []) {
            throw OfficeException::resourceUnavailable();
        }
        if ($resourceIds === [] && $viewer->effectiveSubject !== $run->effectiveSubject) {
            throw OfficeException::resourceUnavailable();
        }

        $initiator = $this->resolveInitiatorForRun($viewer, $run);
        $resources = [];
        $confirmedSteps = [];
        $remainingSteps = [];

        foreach ($resourceIds as $resourceId) {
            $entry = $accessible[$resourceId] ?? null;
            if ($entry === null) {
                $hidden = [
                    'resource_id' => $resourceId,
                    'visible' => false,
                    'status' => 'unavailable',
                    'requires_reread' => false,
                    'can_continue' => false,
                ];
                $resources[] = $hidden;
                $remainingSteps[] = $hidden;

                continue;
            }

            $item = $this->buildRunResourceView($viewer, $initiator, $entry, $run, $steps);
            $resources[] = $item;
            if (in_array($item['status'], ['completed', 'committed'], true)) {
                $confirmedSteps[] = $item;
            } else {
                $remainingSteps[] = $item;
            }
        }

        $awaitingApprovalCount = 0;
        $conflictCount = 0;
        foreach ($resources as $res) {
            if ($res['status'] === 'awaiting_approval') {
                $awaitingApprovalCount++;
            } elseif ($res['status'] === 'conflict') {
                $conflictCount++;
            }
        }

        $computedState = $this->deriveRunState($run, $resources);
        $redactedSteps = [];
        foreach ($steps as $step) {
            $stepVisible = $step->resourceId === null || isset($accessible[$step->resourceId]);
            $redactedSteps[] = [
                'step_id' => $step->stepId,
                'operation' => $step->operation->value,
                'resource_id' => $step->resourceId,
                'proposal_id' => $stepVisible ? $step->proposalId : null,
                'command_id' => $stepVisible ? $step->commandId : null,
                'receipt_id' => $stepVisible ? $step->receiptId : null,
                'state' => $step->state,
                'safe_error' => $stepVisible ? $step->safeError : ($step->safeError !== null ? 'resource_unavailable' : null),
                'created_at' => $step->createdAt->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $step->updatedAt->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        return [
            'run_id' => $run->runId,
            'tenant_id' => $run->tenantId,
            'project_scope' => $run->projectScope,
            'initiator' => $run->effectiveSubject,
            'channel' => $run->channel,
            'profile_revision' => $run->profileRevision,
            'state' => $computedState,
            'created_at' => $run->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $run->updatedAt->format('Y-m-d\TH:i:s\Z'),
            'can_resume' => in_array($computedState, ['queued', 'running', 'partially_applied', 'reconcile'], true),
            'summary' => [
                'total_resources' => count($resources),
                'confirmed_count' => count($confirmedSteps),
                'remaining_count' => count($remainingSteps),
                'awaiting_approval_count' => $awaitingApprovalCount,
                'conflict_count' => $conflictCount,
            ],
            'confirmed_steps' => $confirmedSteps,
            'remaining_steps' => $remainingSteps,
            'resources' => $resources,
            'steps' => $redactedSteps,
        ];
    }

    /**
     * Continue a partial or interrupted run without re-applying already-committed resources,
     * re-reading conflicted targets before preparing or awaiting fresh human approval.
     *
     * @param  array<string, array<string, mixed>>  $argumentsByResource
     * @return array<string, mixed>
     */
    public function resumeRun(
        CallContext $caller,
        string $runId,
        array $argumentsByResource = [],
        bool $rereadOnly = false,
    ): array {
        if (! $this->isLowerUuid($runId)) {
            throw OfficeException::resourceUnavailable();
        }

        $run = $this->runs->findInScope($caller, $runId);
        if ($run === null) {
            throw OfficeException::resourceUnavailable();
        }

        $steps = $this->runs->listStepsInScope($caller, $runId);
        $resourceIds = $this->collectRunResourceIds($run, $steps);
        $accessible = $resourceIds !== [] ? $this->directory->filterAccessible($caller, $resourceIds, 'view') : [];
        if ($resourceIds !== [] && $accessible === []) {
            throw OfficeException::resourceUnavailable();
        }

        $initiator = $this->resolveInitiatorForRun($caller, $run);
        if ($initiator === null) {
            throw OfficeException::resourceUnavailable();
        }

        $draftItems = $this->extractDraftItems($run);
        $executor = $draftItems === [] && $run->channel !== '' ? $this->registry->executor($run->channel) : null;
        $pendingCommits = array_filter(
            $steps,
            static fn (OfficeRunStep $step): bool => $step->operation === OfficeOperation::Commit
                && in_array($step->state, ['dispatched', 'waiting'], true),
        );
        $draftItemsToContinue = $draftItems;

        if ($caller->effectiveSubject !== $run->effectiveSubject) {
            // Channel executors can select additional resources after they start.
            if ($executor instanceof ResumableOfficeExecutor) {
                throw OfficeException::resourceUnavailable();
            }

            $draftItemsToContinue = $this->unfinishedDraftItems($draftItems, $steps);
            $overrideResourceIds = array_map(strval(...), array_keys($argumentsByResource));
            $targetIds = array_merge(array_column($draftItemsToContinue, 'resource_id'), $overrideResourceIds);
            foreach ($pendingCommits as $step) {
                if ($step->resourceId === null || $step->resourceId === '') {
                    throw OfficeException::resourceUnavailable();
                }
                $targetIds[] = $step->resourceId;
            }
            foreach (array_unique($targetIds) as $resourceId) {
                if ($this->directory->findAccessible($caller, $resourceId, 'edit') === null) {
                    throw OfficeException::resourceUnavailable();
                }
            }

            if ($draftItemsToContinue === [] && $pendingCommits === []) {
                return $this->showRun($caller, $runId);
            }
        }

        if ($executor instanceof ResumableOfficeExecutor) {
            $executor->executeRun($initiator, $runId);

            return $this->showRun($caller, $runId);
        }

        $lease = $this->runs->claimLease($initiator, $runId, 90);
        if ($lease === null) {
            return $this->showRun($caller, $runId);
        }

        try {
            foreach ($pendingCommits as $step) {
                $resumed = $this->commits->resumeStep($initiator, $runId, $step->stepId);
                $resumedStep = $resumed['step'];
                if ($resumedStep->state === 'finished' && $resumedStep->resourceId !== null && $resumedStep->receiptId !== null) {
                    $this->ensureAtCommitVerified($initiator, $runId, $resumedStep->resourceId, $resumedStep->receiptId);
                }
            }

            if ($draftItemsToContinue !== []) {
                $this->continueDraftItems($initiator, $runId, $draftItemsToContinue, $argumentsByResource, $rereadOnly);
            }

            $finalSteps = $this->runs->listSteps($initiator, $runId);
            $expectedResources = [];
            foreach ($draftItems as $item) {
                $expectedResources[] = $item['resource_id'];
            }
            $nextState = $this->stateReducer->reduce($finalSteps, expectedResources: $expectedResources);
            $this->runs->finishRun($initiator, $runId, $lease, $nextState);

            return $this->showRun($caller, $runId);
        } catch (Throwable $failure) {
            $this->runs->releaseLease($initiator, $runId, $lease, 'reconcile');

            throw $failure;
        }
    }

    /**
     * @param  list<array{resource_id: string, selector: stdClass, command: string, arguments: stdClass}>  $draftItems
     * @param  list<OfficeRunStep>  $steps
     * @return list<array{resource_id: string, selector: stdClass, command: string, arguments: stdClass}>
     */
    private function unfinishedDraftItems(array $draftItems, array $steps): array
    {
        $unfinished = [];
        foreach ($draftItems as $item) {
            $commit = $this->latestStepForResource($steps, $item['resource_id'], OfficeOperation::Commit);
            if ($commit === null || ! in_array($commit->state, ['finished', 'verified'], true)
                || ($commit->receiptId !== null && ! $this->hasVerifiedReceipt($steps, $commit->receiptId))) {
                $unfinished[] = $item;
            }
        }

        return $unfinished;
    }

    /**
     * @param  list<array{resource_id: string, selector: stdClass, command: string, arguments: stdClass}>  $draftItems
     * @param  array<string, array<string, mixed>>  $argumentsByResource
     */
    private function continueDraftItems(
        CallContext $initiator,
        string $runId,
        array $draftItems,
        array $argumentsByResource,
        bool $rereadOnly,
    ): void {
        $steps = $this->runs->listSteps($initiator, $runId);

        foreach ($draftItems as $item) {
            $resourceId = $item['resource_id'];
            $latestCommit = $this->latestStepForResource($steps, $resourceId, OfficeOperation::Commit);
            $latestPrepare = $this->latestStepForResource($steps, $resourceId, OfficeOperation::Prepare);

            if ($latestCommit !== null && in_array($latestCommit->state, ['finished', 'verified'], true)) {
                if ($latestCommit->receiptId !== null) {
                    $this->ensureAtCommitVerified($initiator, $runId, $resourceId, $latestCommit->receiptId);
                }

                continue;
            }

            if ($latestPrepare !== null
                && $latestPrepare->state === 'finished'
                && $latestPrepare->proposalId !== null
                && ($latestCommit === null || $latestCommit->proposalId !== $latestPrepare->proposalId || $latestCommit->state === 'waiting')
            ) {
                $existingProposal = $this->proposals->find($initiator, $resourceId, $latestPrepare->proposalId);
                if ($existingProposal !== null && $existingProposal->decision === 'pending') {
                    if ($latestCommit === null) {
                        $prepResult = $this->stepResultMap($latestPrepare);
                        $rawDigest = $prepResult['digest'] ?? null;
                        $digest = is_string($rawDigest) ? $rawDigest : $existingProposal->plan->digest;
                        $this->commits->executeOrResume($initiator, $runId, $resourceId, $existingProposal->plan->proposalId, $digest);
                    }

                    continue;
                }
                if ($existingProposal !== null && $existingProposal->decision === 'approved') {
                    $digest = $existingProposal->plan->digest;
                    $commitRes = $this->commits->executeOrResume($initiator, $runId, $resourceId, $existingProposal->plan->proposalId, $digest);
                    $step = $commitRes['step'];
                    if ($step !== null && $step->state === 'finished' && $step->receiptId !== null) {
                        $this->ensureAtCommitVerified($initiator, $runId, $resourceId, $step->receiptId);
                    }

                    continue;
                }
            }

            // Re-read the target resource cleanly.
            $readStart = hrtime(true);
            $readResponse = $this->office->handle($initiator, new OfficeRequest(
                version: 1,
                operation: OfficeOperation::Read,
                input: (object) [
                    'resource_id' => $resourceId,
                    'selector' => $item['selector'],
                ],
            ));
            $readError = $readResponse->errorCode !== null ? $readResponse->errorCode->value : ErrorCode::TemporarilyUnavailable->value;
            $this->runs->recordStep(
                context: $initiator,
                runId: $runId,
                operation: OfficeOperation::Read,
                state: $readResponse->ok ? 'finished' : 'failed',
                resourceId: $resourceId,
                compactResult: $readResponse->toArray(),
                safeError: $readResponse->ok ? null : $readError,
                responseBytes: $readResponse->wireBytes(),
                latencyMs: $this->elapsedMs($readStart),
            );

            if (! $readResponse->ok || ! $readResponse->result instanceof stdClass || $rereadOnly) {
                continue;
            }

            $readToken = $readResponse->result->read_token ?? null;
            if (! is_string($readToken) || $readToken === '') {
                continue;
            }

            $argsObj = isset($argumentsByResource[$resourceId])
                ? $this->arrayToStdClass($argumentsByResource[$resourceId])
                : $item['arguments'];

            $prepStart = hrtime(true);
            $prepResponse = $this->office->handle($initiator, new OfficeRequest(
                version: 1,
                operation: OfficeOperation::Prepare,
                input: (object) [
                    'resource_id' => $resourceId,
                    'command' => $item['command'],
                    'read_token' => $readToken,
                    'arguments' => $argsObj,
                ],
            ));
            $proposalId = $prepResponse->ok && $prepResponse->result instanceof stdClass && is_string($prepResponse->result->proposal_id ?? null)
                ? $prepResponse->result->proposal_id
                : null;
            $prepError = $prepResponse->errorCode !== null ? $prepResponse->errorCode->value : ErrorCode::PreconditionFailed->value;
            $this->runs->recordStep(
                context: $initiator,
                runId: $runId,
                operation: OfficeOperation::Prepare,
                state: $prepResponse->ok ? 'finished' : 'failed',
                resourceId: $resourceId,
                proposalId: $proposalId,
                compactResult: $prepResponse->toArray(),
                safeError: $prepResponse->ok ? null : $prepError,
                responseBytes: $prepResponse->wireBytes(),
                latencyMs: $this->elapsedMs($prepStart),
            );

            if (! $prepResponse->ok || ! $prepResponse->result instanceof stdClass || $proposalId === null) {
                continue;
            }

            $digest = is_string($prepResponse->result->digest ?? null) ? $prepResponse->result->digest : '';
            if ($digest === '') {
                continue;
            }

            $commitResult = $this->commits->executeOrResume(
                context: $initiator,
                runId: $runId,
                resourceId: $resourceId,
                proposalId: $proposalId,
                digest: $digest,
            );
            $commitStep = $commitResult['step'];
            if ($commitStep !== null && $commitStep->state === 'finished' && $commitStep->receiptId !== null) {
                $this->ensureAtCommitVerified($initiator, $runId, $resourceId, $commitStep->receiptId);
            }
        }
    }

    private function ensureAtCommitVerified(CallContext $initiator, string $runId, string $resourceId, string $receiptId): void
    {
        if ($this->hasVerifiedReceipt($this->runs->listSteps($initiator, $runId), $receiptId)) {
            return;
        }

        $startedAt = hrtime(true);
        $response = $this->office->handle($initiator, new OfficeRequest(
            version: 1,
            operation: OfficeOperation::Verify,
            input: (object) [
                'resource_id' => $resourceId,
                'receipt_id' => $receiptId,
                'mode' => 'at_commit',
            ],
        ));
        $passed = $response->ok
            && $response->result instanceof stdClass
            && ($response->result->outcome ?? null) === 'passed';
        $safeError = $passed
            ? null
            : ($response->errorCode !== null ? $response->errorCode->value : 'verification_failed');

        $this->runs->recordStep(
            context: $initiator,
            runId: $runId,
            operation: OfficeOperation::Verify,
            state: $passed ? 'verified' : 'failed',
            resourceId: $resourceId,
            receiptId: $receiptId,
            compactResult: $response->toArray(),
            safeError: $safeError,
            responseBytes: $response->wireBytes(),
            latencyMs: $this->elapsedMs($startedAt),
        );
    }

    /** @param list<OfficeRunStep> $steps */
    private function hasVerifiedReceipt(array $steps, string $receiptId): bool
    {
        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Verify && $step->receiptId === $receiptId && $step->state === 'verified') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProposal(CallContext $viewer, ResourceEntry $resource, StoredProposal $proposal): array
    {
        $plan = $proposal->plan;
        $initiatorSubject = $proposal->effectiveSubject ?? $viewer->effectiveSubject;
        $initiator = $this->resolveInitiatorForProposal($viewer, $plan->proposalId, $initiatorSubject);
        $route = $this->routes->forProposalInScope($viewer, $initiatorSubject, $plan->proposalId);
        $linkedCommit = $this->runs->findLatestStepForProposalInScope($viewer, $plan->proposalId, OfficeOperation::Commit);
        $linkedPrep = $this->runs->findLatestStepForProposalInScope($viewer, $plan->proposalId, OfficeOperation::Prepare);
        $linkedVerify = null;

        $dispatch = $this->findDispatchRow($viewer->tenantId, $plan->proposalId);
        $dispatchState = $dispatch !== null ? QueryRecord::str($dispatch, 'state') : null;

        $commandId = null;
        if ($route !== null) {
            $commandId = $route->commandId;
        } elseif ($linkedCommit !== null) {
            $commandId = $linkedCommit->commandId;
        }

        $ownerKey = null;
        if ($route !== null) {
            $ownerKey = $route->ownerKey;
        } elseif ($plan->ownerKey !== '') {
            $ownerKey = $plan->ownerKey;
        }

        $receiptId = null;
        if ($route !== null && $route->receiptId !== null) {
            $receiptId = $route->receiptId;
        } elseif ($linkedCommit !== null) {
            $receiptId = $linkedCommit->receiptId;
        }

        $marker = null;
        $rejectionReason = null;

        if ($route !== null && $initiator !== null) {
            $owner = $this->registry->owner($route->ownerKey);
            if ($owner !== null) {
                try {
                    $outcome = $owner->status(new CommandScope($initiator, $plan->resourceId, $route->commandId, $route->fingerprint));
                    if ($receiptId === null && $outcome->state === 'committed' && $outcome->receiptId !== null) {
                        $this->routes->settle(new CommandScope($initiator, $plan->resourceId, $route->commandId), $outcome->receiptId);
                        $receiptId = $outcome->receiptId;
                        $marker = $outcome->marker?->toArray();
                    } elseif ($outcome->state === 'committed') {
                        $marker = $outcome->marker?->toArray();
                    } elseif ($receiptId === null && $outcome->state === 'rejected') {
                        $rejectionReason = $outcome->reason !== null
                            ? $outcome->reason->value
                            : ErrorCode::PreconditionFailed->value;
                    }
                } catch (OfficeException) {
                    // Fall through to dispatch/step state below without leaking details.
                }
            }
        }

        if ($marker === null && $linkedCommit !== null) {
            $commitResult = $this->stepResultMap($linkedCommit);
            $marker = $this->extractMarker($commitResult['marker'] ?? null);
        }

        if ($receiptId !== null) {
            $ownerStatus = 'committed';
        } elseif ($rejectionReason !== null) {
            $ownerStatus = 'rejected';
        } elseif ($linkedCommit !== null && $linkedCommit->state === 'failed' && $linkedCommit->safeError !== 'proposal_rejected') {
            $ownerStatus = 'rejected';
            $rejectionReason = $linkedCommit->safeError;
        } elseif ($dispatchState === 'exhausted') {
            $ownerStatus = 'rejected';
            if ($linkedCommit !== null && $linkedCommit->safeError !== null) {
                $rejectionReason = $linkedCommit->safeError;
            } elseif ($initiator === null || $this->directory->findAccessible($initiator, $plan->resourceId, 'edit') === null) {
                $rejectionReason = ErrorCode::ResourceUnavailable->value;
            } else {
                $rejectionReason = 'dispatch_exhausted';
            }
        } elseif ($route !== null) {
            $ownerStatus = $dispatchState === 'ready' ? 'pending_dispatch' : 'running';
        } else {
            $ownerStatus = 'not_started';
        }

        $runInfo = null;
        $stepRef = $linkedCommit ?? $linkedPrep;
        if ($stepRef !== null) {
            $linkedRun = $this->runs->findInScope($viewer, $stepRef->runId);
            if ($linkedRun !== null) {
                $runInfo = [
                    'run_id' => $linkedRun->runId,
                    'state' => $linkedRun->state,
                    'step_id' => $stepRef->stepId,
                ];
                $runSteps = $this->runs->listStepsInScope($viewer, $linkedRun->runId);
                if ($receiptId !== null) {
                    foreach ($runSteps as $rs) {
                        if ($rs->operation === OfficeOperation::Verify && $rs->receiptId === $receiptId) {
                            $linkedVerify = $rs;
                        }
                    }
                }
            }
        }

        $verification = $this->buildVerificationView($initiator, $plan->resourceId, $receiptId, $ownerKey, $linkedVerify);

        $now = $this->db->clockNow($this->clock);
        $expired = new DateTimeImmutable($plan->expiresAt) <= $now;
        $canDecide = $plan->approval === ApprovalMode::Human
            && $proposal->decision === 'pending'
            && $proposal->hasPayload
            && ! $expired
            && $this->approvalPolicy->canApprove($viewer, $resource, $proposal);

        return [
            'proposal_id' => $plan->proposalId,
            'digest' => $plan->digest,
            'initiator' => $initiatorSubject,
            'tenant_id' => $proposal->tenantId ?? $viewer->tenantId,
            'project_scope' => $proposal->projectScope ?? $viewer->projectScope,
            'resource' => [
                'resource_id' => $resource->resourceId,
                'resource_type' => $resource->resourceType->value,
                'title' => $resource->title,
            ],
            'command' => $plan->command,
            'base' => [
                'generation' => $plan->base->generation,
                'position' => $plan->base->position,
            ],
            'targets' => $plan->targets,
            'summary' => $plan->summary,
            'diff' => $this->diffBuilder->build($proposal),
            'approval' => [
                'mode' => $plan->approval->value,
                'decision' => $proposal->decision,
                'human_status' => $plan->approval === ApprovalMode::Automatic ? 'automatic' : $proposal->decision,
                'reviewer' => $proposal->approverSubject,
                'decided_at' => $proposal->decidedAt?->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $plan->expiresAt,
                'expired' => $expired,
                'can_approve' => $canDecide,
                'can_reject' => $canDecide,
            ],
            'execution' => [
                'owner_status' => $ownerStatus,
                'command_id' => $commandId,
                'owner_key' => $ownerKey,
                'dispatch_state' => $dispatchState,
                'receipt_id' => $receiptId,
                'marker' => $marker,
                'rejection_reason' => $rejectionReason,
            ],
            'verification' => $verification,
            'run' => $runInfo,
        ];
    }

    /**
     * @param  list<OfficeRunStep>  $steps
     * @return array<string, mixed>
     */
    private function buildRunResourceView(
        CallContext $viewer,
        ?CallContext $initiator,
        ResourceEntry $entry,
        OfficeRun $run,
        array $steps,
    ): array {
        $resourceId = $entry->resourceId;
        $latestRead = $this->latestStepForResource($steps, $resourceId, OfficeOperation::Read);
        $latestPrep = $this->latestStepForResource($steps, $resourceId, OfficeOperation::Prepare);
        $latestCommit = $this->latestStepForResource($steps, $resourceId, OfficeOperation::Commit);

        $proposalId = null;
        if ($latestPrep !== null && $latestPrep->proposalId !== null) {
            $proposalId = $latestPrep->proposalId;
        } elseif ($latestCommit !== null) {
            $proposalId = $latestCommit->proposalId;
        }

        $storedProposal = $proposalId !== null ? $this->proposals->findByIdInScope($viewer, $proposalId) : null;
        $proposalView = $storedProposal !== null ? $this->formatProposal($viewer, $entry, $storedProposal) : null;

        $base = null;
        if ($proposalView !== null && is_array($proposalView['base'] ?? null)) {
            $base = $this->extractMarker($proposalView['base']);
        } elseif ($latestRead !== null) {
            $readResult = $this->stepResultMap($latestRead);
            $base = $this->extractMarker($readResult['marker'] ?? null);
        }

        $latestReadView = null;
        if ($latestRead !== null) {
            $readResult = $this->stepResultMap($latestRead);
            if ($readResult !== []) {
                $latestReadView = [
                    'step_id' => $latestRead->stepId,
                    'marker' => $this->extractMarker($readResult['marker'] ?? null),
                    'data' => is_array($readResult['data'] ?? null) ? $readResult['data'] : null,
                ];
            }
        }

        /** @var array<string, mixed> $execution */
        $execution = $proposalView !== null && is_array($proposalView['execution'] ?? null)
            ? $proposalView['execution']
            : [
                'owner_status' => $latestCommit !== null ? match ($latestCommit->state) {
                    'finished', 'verified' => 'committed',
                    'failed' => 'rejected',
                    'waiting', 'dispatched' => 'running',
                    default => 'not_started',
                } : 'not_started',
                'command_id' => $latestCommit?->commandId,
                'owner_key' => null,
                'dispatch_state' => null,
                'receipt_id' => $latestCommit?->receiptId,
                'marker' => $latestCommit !== null ? $this->extractMarker($this->stepResultMap($latestCommit)['marker'] ?? null) : null,
                'rejection_reason' => $latestCommit?->safeError,
            ];

        $receiptId = is_string($execution['receipt_id'] ?? null) ? $execution['receipt_id'] : null;
        $latestVerify = null;
        if ($receiptId !== null) {
            foreach ($steps as $s) {
                if ($s->operation === OfficeOperation::Verify && $s->receiptId === $receiptId) {
                    $latestVerify = $s;
                }
            }
        }

        $ownerKey = is_string($execution['owner_key'] ?? null) ? $execution['owner_key'] : null;
        /** @var array<string, mixed> $verification */
        $verification = $proposalView !== null && is_array($proposalView['verification'] ?? null)
            ? $proposalView['verification']
            : $this->buildVerificationView($initiator, $resourceId, $receiptId, $ownerKey, $latestVerify);

        $errorCode = null;
        if (is_string($execution['rejection_reason'] ?? null)) {
            $errorCode = $execution['rejection_reason'];
        } elseif ($latestPrep !== null && $latestPrep->state === 'failed') {
            $errorCode = $latestPrep->safeError;
        } elseif ($latestRead !== null && $latestRead->state === 'failed'
            && ($latestPrep === null || $latestRead->stepId > $latestPrep->stepId)
            && ($latestCommit === null || $latestRead->stepId > $latestCommit->stepId)) {
            $errorCode = $latestRead->safeError;
        }

        $approvalView = $proposalView !== null && is_array($proposalView['approval'] ?? null)
            ? $proposalView['approval']
            : [];
        $humanDecision = is_string($approvalView['decision'] ?? null) ? $approvalView['decision'] : null;

        if ($latestPrep !== null && $latestCommit !== null && $latestPrep->stepId > $latestCommit->stepId) {
            if ($latestPrep->state === 'failed') {
                $errorCode = $latestPrep->safeError;
                $execution['owner_status'] = 'rejected';
                $execution['rejection_reason'] = $errorCode;
            } elseif ($humanDecision === 'pending') {
                $errorCode = null;
                $execution['owner_status'] = 'not_started';
                $execution['rejection_reason'] = null;
            }
        }

        $ownerStatus = is_string($execution['owner_status'] ?? null) ? $execution['owner_status'] : 'not_started';
        $verifyStatus = is_string($verification['status'] ?? null) ? $verification['status'] : 'unverified';
        $requiresReread = is_string($errorCode) && in_array($errorCode, self::REREAD_ERROR_CODES, true);

        if ($ownerStatus === 'committed') {
            $status = $verifyStatus === 'passed' ? 'completed' : ($verifyStatus === 'failed' ? 'failed' : 'committed');
        } elseif ($requiresReread) {
            $status = 'conflict';
        } elseif ($ownerStatus === 'rejected') {
            $status = 'failed';
        } elseif ($humanDecision === 'rejected') {
            $status = 'failed';
        } elseif ($humanDecision === 'pending') {
            $status = 'awaiting_approval';
        } elseif ($ownerStatus === 'pending_dispatch') {
            $status = 'pending_dispatch';
        } elseif ($ownerStatus === 'running') {
            $status = 'running';
        } else {
            $status = 'pending';
        }

        return [
            'resource_id' => $entry->resourceId,
            'resource_type' => $entry->resourceType->value,
            'title' => $entry->title,
            'visible' => true,
            'base' => $base,
            'latest_read' => $latestReadView,
            'proposal' => $proposalView,
            'execution' => $execution,
            'verification' => $verification,
            'status' => $status,
            'error' => $errorCode,
            'requires_reread' => $requiresReread,
            'can_continue' => in_array($status, ['conflict', 'failed', 'pending', 'pending_dispatch', 'running'], true)
                && ($humanDecision !== 'rejected'),
            'draft_configured' => $this->hasDraftItem($run, $resourceId),
        ];
    }

    /**
     * @return array{status: string, mode: ?string, outcome: ?string, marker: ?array{generation: int, position: int}, checks: mixed}
     */
    private function buildVerificationView(
        ?CallContext $initiator,
        string $resourceId,
        ?string $receiptId,
        ?string $ownerKey,
        ?OfficeRunStep $verifyStep,
    ): array {
        if ($verifyStep !== null) {
            $res = $this->stepResultMap($verifyStep);
            if ($res !== []) {
                $outcome = is_string($res['outcome'] ?? null) ? $res['outcome'] : null;

                return [
                    'status' => $outcome === 'passed' ? 'passed' : 'failed',
                    'mode' => is_string($res['mode'] ?? null) ? $res['mode'] : null,
                    'outcome' => $outcome,
                    'marker' => $this->extractMarker($res['marker'] ?? null),
                    'checks' => $res['checks'] ?? null,
                ];
            }
        }

        if ($receiptId !== null && $initiator !== null && $ownerKey !== null) {
            $owner = $this->registry->owner($ownerKey);
            if ($owner !== null) {
                try {
                    $verified = $owner->verify($initiator, $resourceId, $receiptId, 'at_commit');
                    $outcome = is_string($verified->outcome ?? null) ? $verified->outcome : null;

                    return [
                        'status' => $outcome === 'passed' ? 'passed' : 'failed',
                        'mode' => is_string($verified->mode ?? null) ? $verified->mode : 'at_commit',
                        'outcome' => $outcome,
                        'marker' => $this->extractMarker($verified->marker ?? null),
                        'checks' => $verified->checks ?? null,
                    ];
                } catch (OfficeException) {
                    // Evidence unavailable or access revoked -> remain unverified without failing view.
                }
            }
        }

        return [
            'status' => 'unverified',
            'mode' => null,
            'outcome' => null,
            'marker' => null,
            'checks' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stepResultMap(OfficeRunStep $step): array
    {
        $raw = $step->compactResult['result'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        return array_filter($raw, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array{generation: int, position: int}|null
     */
    private function extractMarker(mixed $raw): ?array
    {
        if (is_array($raw) && is_int($raw['generation'] ?? null) && is_int($raw['position'] ?? null)) {
            return [
                'generation' => $raw['generation'],
                'position' => $raw['position'],
            ];
        }
        if ($raw instanceof stdClass && is_int($raw->generation ?? null) && is_int($raw->position ?? null)) {
            return [
                'generation' => $raw->generation,
                'position' => $raw->position,
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     */
    private function deriveRunState(OfficeRun $run, array $resources): string
    {
        if ($resources === []) {
            return $run->state;
        }

        $hasCompletedOrCommitted = false;
        $hasAwaitingApproval = false;
        $hasRunning = false;
        $hasConflictOrFailed = false;
        $hasPending = false;

        foreach ($resources as $res) {
            switch ($res['status']) {
                case 'completed':
                case 'committed':
                    $hasCompletedOrCommitted = true;
                    break;
                case 'awaiting_approval':
                    $hasAwaitingApproval = true;
                    break;
                case 'pending_dispatch':
                case 'running':
                    $hasRunning = true;
                    break;
                case 'conflict':
                case 'failed':
                case 'unavailable':
                    $hasConflictOrFailed = true;
                    break;
                default:
                    $hasPending = true;
                    break;
            }
        }

        if ($hasAwaitingApproval) {
            return 'awaiting_approval';
        }
        if ($hasRunning) {
            return 'reconcile';
        }
        if (! $hasCompletedOrCommitted && $hasConflictOrFailed) {
            return 'rejected';
        }
        if ($hasCompletedOrCommitted && ($hasConflictOrFailed || $hasPending)) {
            return 'partially_applied';
        }
        if ($hasCompletedOrCommitted) {
            return 'completed';
        }

        return $run->state;
    }

    /**
     * @param  list<OfficeRunStep>  $steps
     * @return list<string>
     */
    private function collectRunResourceIds(OfficeRun $run, array $steps): array
    {
        $ids = [];
        if (is_array($run->input['draft_items'] ?? null)) {
            foreach ($run->input['draft_items'] as $item) {
                if (is_array($item) && is_string($item['resource_id'] ?? null) && $item['resource_id'] !== '') {
                    $ids[$item['resource_id']] = true;
                }
            }
        }
        if (is_array($run->input['resources'] ?? null)) {
            foreach ($run->input['resources'] as $resId) {
                if (is_string($resId) && $resId !== '') {
                    $ids[$resId] = true;
                }
            }
        }
        if (is_string($run->input['resource_id'] ?? null) && $run->input['resource_id'] !== '') {
            $ids[$run->input['resource_id']] = true;
        }
        foreach ($steps as $step) {
            if ($step->resourceId !== null && $step->resourceId !== '') {
                $ids[$step->resourceId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @return list<array{resource_id: string, selector: stdClass, command: string, arguments: stdClass}>
     */
    private function extractDraftItems(OfficeRun $run): array
    {
        $rawItems = $run->input['draft_items'] ?? null;
        if (! is_array($rawItems)) {
            return [];
        }

        $items = [];
        foreach ($rawItems as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $resourceId = $raw['resource_id'] ?? null;
            $command = $raw['command'] ?? null;
            $selector = $raw['selector'] ?? null;
            $arguments = $raw['arguments'] ?? null;
            if (! is_string($resourceId) || $resourceId === ''
                || ! is_string($command) || $command === ''
                || ! is_array($selector) || ! is_array($arguments)) {
                continue;
            }
            $items[] = [
                'resource_id' => $resourceId,
                'selector' => $this->arrayToStdClass($selector),
                'command' => $command,
                'arguments' => $this->arrayToStdClass($arguments),
            ];
        }

        return $items;
    }

    private function hasDraftItem(OfficeRun $run, string $resourceId): bool
    {
        foreach ($this->extractDraftItems($run) as $item) {
            if ($item['resource_id'] === $resourceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<OfficeRunStep>  $steps
     */
    private function latestStepForResource(array $steps, string $resourceId, OfficeOperation $operation): ?OfficeRunStep
    {
        $match = null;
        foreach ($steps as $step) {
            if ($step->resourceId === $resourceId && $step->operation === $operation) {
                $match = $step;
            }
        }

        return $match;
    }

    private function resolveInitiatorForProposal(
        CallContext $viewer,
        string $proposalId,
        string $initiatorSubject,
    ): ?CallContext {
        if ($initiatorSubject === '' || hash_equals($viewer->effectiveSubject, $initiatorSubject)) {
            return $viewer;
        }

        if ($this->container->bound(ApprovedCommandContextResolver::class)) {
            $resolved = $this->container->make(ApprovedCommandContextResolver::class)->resolve($viewer->tenantId, $proposalId);
            if ($resolved !== null
                && hash_equals($resolved->tenantId, $viewer->tenantId)
                && hash_equals($resolved->projectScope, $viewer->projectScope)
                && hash_equals($resolved->effectiveSubject, $initiatorSubject)) {
                return $resolved;
            }
        }

        $step = $this->runs->findLatestStepForProposalInScope($viewer, $proposalId);
        if ($step !== null && $this->container->bound(RunContextResolver::class)) {
            $resolved = $this->container->make(RunContextResolver::class)->resolve($viewer->tenantId, $step->runId);
            if ($resolved !== null
                && hash_equals($resolved->tenantId, $viewer->tenantId)
                && hash_equals($resolved->projectScope, $viewer->projectScope)
                && hash_equals($resolved->effectiveSubject, $initiatorSubject)) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveInitiatorForRun(CallContext $viewer, OfficeRun $run): ?CallContext
    {
        if (hash_equals($viewer->effectiveSubject, $run->effectiveSubject)) {
            return $viewer;
        }

        if ($this->container->bound(RunContextResolver::class)) {
            $resolved = $this->container->make(RunContextResolver::class)->resolve($run->tenantId, $run->runId);
            if ($resolved !== null
                && hash_equals($resolved->tenantId, $run->tenantId)
                && hash_equals($resolved->projectScope, $run->projectScope)
                && hash_equals($resolved->effectiveSubject, $run->effectiveSubject)) {
                return $resolved;
            }
        }

        return null;
    }

    private function findDispatchRow(string $tenantId, string $proposalId): ?object
    {
        if (! $this->isLowerUuid($proposalId)) {
            return null;
        }

        return $this->db->transaction(
            fn (): ?object => $this->db->query('ai_dispatches')->where('proposal_id', $proposalId)->first(),
            $tenantId,
        );
    }

    /** @param array<mixed> $data */
    private function arrayToStdClass(array $data): stdClass
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }

    private function isLowerUuid(string $value): bool
    {
        return Str::isUuid($value) && $value === strtolower($value);
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return max(0, (int) ((hrtime(true) - $startedAtNs) / 1_000_000));
    }
}
