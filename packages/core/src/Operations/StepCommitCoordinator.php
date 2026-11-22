<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Illuminate\Support\Str;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;

/**
 * Coordinates durable step persistence, office.status recovery, and idempotent office.commit execution
 * across executor restarts and worker crashes (shared by Laravel AI and AgenTools adapters).
 */
final readonly class StepCommitCoordinator
{
    public function __construct(
        private Office $office,
        private Runs $runs,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private OfficeDatabase $db,
        private WireErrorMapper $errorMapper,
        private Clock $clock,
    ) {}

    /**
     * Execute or resume a commit step inside a persisted run.
     * On any retry (including a new provider toolCallId), extracts the saved command_id
     * and queries office.status first before considering a commit replay.
     *
     * @return array{step: OfficeRunStep, response: OfficeResponse}
     */
    public function executeOrResume(
        CallContext $context,
        string $runId,
        string $resourceId,
        string $proposalId,
        string $digest,
        ?string $preferredCommandId = null,
        ?string $executorActionKey = null,
    ): array {
        $startedAt = hrtime(true);

        $existing = $this->runs->findCommitStepByProposal($context, $runId, $proposalId);
        if ($existing === null && $executorActionKey !== null && $executorActionKey !== '') {
            $existing = $this->runs->findStepByActionKey($context, $runId, $executorActionKey);
        }

        if ($existing !== null) {
            return $this->resumeExistingStep($context, $existing, $resourceId, $proposalId, $digest, $startedAt);
        }

        $ownerKey = '';
        $proposal = $this->proposals->find($context, $resourceId, $proposalId);
        if ($proposal !== null) {
            $ownerKey = $proposal->plan->ownerKey;
        }

        $existingRoute = $this->routes->forProposal($context, $proposalId);
        $initialCommandId = $existingRoute !== null
            ? $existingRoute->commandId
            : ($preferredCommandId !== null && $preferredCommandId !== '' ? $preferredCommandId : 'cmd-'.Str::uuid7());

        try {
            $step = $this->runs->beginCommitStep(
                context: $context,
                runId: $runId,
                resourceId: $resourceId,
                proposalId: $proposalId,
                digest: $digest,
                initialCommandId: $initialCommandId,
                ownerKey: $ownerKey,
                executorActionKey: $executorActionKey,
            );
        } catch (OfficeException $e) {
            return [
                'step' => $this->runs->recordStep(
                    context: $context,
                    runId: $runId,
                    operation: OfficeOperation::Commit,
                    state: 'failed',
                    resourceId: $resourceId,
                    proposalId: $proposalId,
                    commandId: $initialCommandId,
                    safeError: $e->errorCode->value,
                ),
                'response' => $this->errorMapper->toResponse(OfficeOperation::Commit, $e->errorCode),
            ];
        }

        // If another process inserted the step concurrently just before beginCommitStep locked,
        // $step->commandId may differ from $initialCommandId or already be settled — resume cleanly.
        if ($step->receiptId !== null || $step->state === 'finished') {
            return $this->resumeExistingStep($context, $step, $resourceId, $proposalId, $digest, $startedAt);
        }

        return $this->dispatchCommit($context, $step, $resourceId, $proposalId, $digest, $startedAt);
    }

    /**
     * Fallback commit/resume entry point when invoked outside a persisted v_ai_runs run.
     * Reuses any existing OwnerRoute for the proposal and checks office.status before committing.
     */
    public function executeStandaloneCommit(
        CallContext $context,
        string $resourceId,
        string $proposalId,
        string $digest,
        ?string $preferredCommandId = null,
    ): OfficeResponse {
        $existingRoute = $this->routes->forProposal($context, $proposalId);
        if ($existingRoute !== null) {
            if ($existingRoute->resourceId !== $resourceId) {
                return $this->errorMapper->toResponse(OfficeOperation::Commit, ErrorCode::IdempotencyConflict);
            }

            $status = $this->office->handle($context, new OfficeRequest(
                version: 1,
                operation: OfficeOperation::Status,
                input: (object) [
                    'resource_id' => $resourceId,
                    'command_id' => $existingRoute->commandId,
                ],
            ));

            if ($status->ok && $status->result instanceof stdClass) {
                $state = $status->result->state ?? null;
                if ($state === 'committed') {
                    return OfficeResponse::success(OfficeOperation::Commit, $status->result);
                }
                if ($state === 'rejected') {
                    $reason = is_string($status->result->reason ?? null) ? $status->result->reason : ErrorCode::PreconditionFailed->value;

                    return $this->errorMapper->toResponse(
                        OfficeOperation::Commit,
                        ErrorCode::tryFrom($reason) ?? ErrorCode::PreconditionFailed,
                    );
                }
                if ($state === 'running' && $this->hasActiveDispatchLease($context, $proposalId)) {
                    return OfficeResponse::success(OfficeOperation::Commit, $status->result);
                }
            } elseif (! $status->ok && $status->errorCode !== ErrorCode::OperationUnknown) {
                return $this->errorMapper->toResponse(
                    OfficeOperation::Commit,
                    $status->errorCode ?? ErrorCode::TemporarilyUnavailable,
                );
            }

            return $this->office->handle($context, new OfficeRequest(
                version: 1,
                operation: OfficeOperation::Commit,
                input: (object) [
                    'resource_id' => $resourceId,
                    'proposal_id' => $proposalId,
                    'digest' => $digest,
                    'command_id' => $existingRoute->commandId,
                ],
            ));
        }

        $commandId = $preferredCommandId !== null && $preferredCommandId !== ''
            ? $preferredCommandId
            : 'cmd-'.Str::uuid7();

        return $this->office->handle($context, new OfficeRequest(
            version: 1,
            operation: OfficeOperation::Commit,
            input: (object) [
                'resource_id' => $resourceId,
                'proposal_id' => $proposalId,
                'digest' => $digest,
                'command_id' => $commandId,
            ],
        ));
    }

    /**
     * Resume a previously persisted commit step by querying office.status first.
     *
     * @return array{step: OfficeRunStep, response: OfficeResponse}
     */
    public function resumeStep(CallContext $context, string $runId, int $stepId): array
    {
        $startedAt = hrtime(true);
        $step = $this->runs->findStep($context, $runId, $stepId);
        if ($step === null || $step->operation !== OfficeOperation::Commit
            || $step->resourceId === null || $step->proposalId === null) {
            throw OfficeException::resourceUnavailable();
        }

        $digest = is_string($step->resumeInput['digest'] ?? null) ? $step->resumeInput['digest'] : '';

        return $this->resumeExistingStep($context, $step, $step->resourceId, $step->proposalId, $digest, $startedAt);
    }

    /**
     * @return array{step: OfficeRunStep, response: OfficeResponse}
     */
    private function resumeExistingStep(
        CallContext $context,
        OfficeRunStep $step,
        string $resourceId,
        string $proposalId,
        string $digest,
        int $startedAt,
    ): array {
        $savedDigest = is_string($step->resumeInput['digest'] ?? null) ? $step->resumeInput['digest'] : null;
        if (
            $step->operation !== OfficeOperation::Commit
            || $step->resourceId !== $resourceId
            || $step->proposalId !== $proposalId
            || ($savedDigest !== null && $digest !== '' && ! hash_equals($savedDigest, $digest))
        ) {
            return [
                'step' => $step,
                'response' => $this->errorMapper->toResponse(OfficeOperation::Commit, ErrorCode::IdempotencyConflict),
            ];
        }

        $commandId = $step->commandId ?? throw OfficeException::temporarilyUnavailable();
        $statusResponse = $this->office->handle($context, new OfficeRequest(
            version: 1,
            operation: OfficeOperation::Status,
            input: (object) [
                'resource_id' => $resourceId,
                'command_id' => $commandId,
            ],
        ));

        if ($statusResponse->ok && $statusResponse->result instanceof stdClass) {
            $state = $statusResponse->result->state ?? null;
            if ($state === 'committed') {
                $receiptId = is_string($statusResponse->result->receipt_id ?? null) ? $statusResponse->result->receipt_id : null;
                $compact = $this->decodeResult($statusResponse);
                $updated = $this->runs->updateStep(
                    context: $context,
                    runId: $step->runId,
                    stepId: $step->stepId,
                    state: 'finished',
                    receiptId: $receiptId,
                    compactResult: $compact,
                    responseBytes: $statusResponse->wireBytes(),
                    latencyMs: $this->elapsedMs($startedAt),
                );

                return [
                    'step' => $updated,
                    'response' => OfficeResponse::success(OfficeOperation::Commit, $statusResponse->result),
                ];
            }

            if ($state === 'rejected') {
                $reason = is_string($statusResponse->result->reason ?? null)
                    ? $statusResponse->result->reason
                    : ErrorCode::PreconditionFailed->value;
                $updated = $this->runs->updateStep(
                    context: $context,
                    runId: $step->runId,
                    stepId: $step->stepId,
                    state: 'failed',
                    compactResult: $this->decodeResult($statusResponse),
                    safeError: $reason,
                    responseBytes: $statusResponse->wireBytes(),
                    latencyMs: $this->elapsedMs($startedAt),
                );
                $errorCode = ErrorCode::tryFrom($reason) ?? ErrorCode::PreconditionFailed;

                return [
                    'step' => $updated,
                    'response' => $this->errorMapper->toResponse(OfficeOperation::Commit, $errorCode),
                ];
            }

            if ($state === 'running') {
                if ($this->hasActiveDispatchLease($context, $proposalId)) {
                    $updated = $this->runs->updateStep(
                        context: $context,
                        runId: $step->runId,
                        stepId: $step->stepId,
                        state: 'waiting',
                        compactResult: $this->decodeResult($statusResponse),
                        responseBytes: $statusResponse->wireBytes(),
                        latencyMs: $this->elapsedMs($startedAt),
                    );

                    return [
                        'step' => $updated,
                        'response' => OfficeResponse::success(OfficeOperation::Commit, $statusResponse->result),
                    ];
                }

                // Route exists without an active dispatch lease (e.g. crash between route bind and owner commit):
                // re-send the identical commit using the saved command_id.
                return $this->dispatchCommit($context, $step, $resourceId, $proposalId, $savedDigest ?? $digest, $startedAt);
            }
        }

        if (! $statusResponse->ok && $statusResponse->errorCode === ErrorCode::OperationUnknown) {
            return $this->dispatchCommit($context, $step, $resourceId, $proposalId, $savedDigest ?? $digest, $startedAt);
        }

        $statusError = $statusResponse->errorCode ?? ErrorCode::TemporarilyUnavailable;
        $safeError = $statusError->value;
        $nextState = $statusError === ErrorCode::ApprovalRequired ? 'waiting' : 'failed';
        $updated = $this->runs->updateStep(
            context: $context,
            runId: $step->runId,
            stepId: $step->stepId,
            state: $nextState,
            compactResult: $this->decodeResult($statusResponse),
            safeError: $safeError,
            responseBytes: $statusResponse->wireBytes(),
            latencyMs: $this->elapsedMs($startedAt),
        );

        return [
            'step' => $updated,
            'response' => $this->errorMapper->toResponse(
                OfficeOperation::Commit,
                $statusError,
            ),
        ];
    }

    /**
     * @return array{step: OfficeRunStep, response: OfficeResponse}
     */
    private function dispatchCommit(
        CallContext $context,
        OfficeRunStep $step,
        string $resourceId,
        string $proposalId,
        string $digest,
        int $startedAt,
    ): array {
        $commandId = $step->commandId ?? throw OfficeException::temporarilyUnavailable();
        $commitResponse = $this->office->handle($context, new OfficeRequest(
            version: 1,
            operation: OfficeOperation::Commit,
            input: (object) [
                'resource_id' => $resourceId,
                'proposal_id' => $proposalId,
                'digest' => $digest,
                'command_id' => $commandId,
            ],
        ));

        if ($commitResponse->ok && $commitResponse->result instanceof stdClass) {
            $state = $commitResponse->result->state ?? null;
            $receiptId = is_string($commitResponse->result->receipt_id ?? null)
                ? $commitResponse->result->receipt_id
                : null;
            $stepState = $state === 'committed' ? 'finished' : 'waiting';
            $updated = $this->runs->updateStep(
                context: $context,
                runId: $step->runId,
                stepId: $step->stepId,
                state: $stepState,
                receiptId: $receiptId,
                compactResult: $this->decodeResult($commitResponse),
                responseBytes: $commitResponse->wireBytes(),
                latencyMs: $this->elapsedMs($startedAt),
            );

            return ['step' => $updated, 'response' => $commitResponse];
        }

        $commitError = $commitResponse->errorCode ?? ErrorCode::TemporarilyUnavailable;
        $safeError = $commitError->value;
        $stepState = in_array($commitError, [ErrorCode::ApprovalRequired, ErrorCode::TemporarilyUnavailable], true)
            ? 'waiting'
            : 'failed';
        $updated = $this->runs->updateStep(
            context: $context,
            runId: $step->runId,
            stepId: $step->stepId,
            state: $stepState,
            compactResult: $this->decodeResult($commitResponse),
            safeError: $safeError,
            responseBytes: $commitResponse->wireBytes(),
            latencyMs: $this->elapsedMs($startedAt),
        );

        return ['step' => $updated, 'response' => $commitResponse];
    }

    private function hasActiveDispatchLease(CallContext $context, string $proposalId): bool
    {
        if (! Str::isUuid($proposalId) || $proposalId !== strtolower($proposalId)) {
            return false;
        }

        $now = $this->db->utcTimestamp($this->clock->now());

        return $this->db->transaction(
            fn (): bool => $this->db->query('ai_dispatches')
                ->where('proposal_id', $proposalId)
                ->where('state', 'leased')
                ->where('lease_until', '>', $now)
                ->exists(),
            $context->tenantId,
        );
    }

    /** @return array<string, mixed> */
    private function decodeResult(OfficeResponse $response): array
    {
        return $response->toArray();
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return max(0, (int) ((hrtime(true) - $startedAtNs) / 1_000_000));
    }
}
