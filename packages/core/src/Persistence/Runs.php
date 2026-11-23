<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRun;
use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Durable run and step ledger over v_ai_runs, v_ai_run_inputs, and v_ai_run_steps.
 */
final readonly class Runs
{
    public function __construct(
        private OfficeDatabase $db,
        private PayloadCipher $cipher,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $inputPayload
     */
    public function createOrFind(
        CallContext $context,
        string $channel,
        string $sourceEventId,
        string $profileRevision,
        array $inputPayload,
        int $inputTtlSeconds = 86400,
    ): OfficeRun {
        if ($channel === '' || $sourceEventId === '' || $profileRevision === '') {
            throw OfficeException::invalidInput('Run channel, source_event_id, and profile_revision are required.');
        }

        return $this->db->transaction(function () use (
            $context,
            $channel,
            $sourceEventId,
            $profileRevision,
            $inputPayload,
            $inputTtlSeconds,
        ): OfficeRun {
            $existing = $this->scopedRunQuery($context)
                ->where('channel', $channel)
                ->where('source_event_id', $sourceEventId)
                ->first();
            if ($existing !== null) {
                return $this->hydrateRun($context, $existing);
            }

            $runId = $this->db->newUuid();
            $now = $this->clock->now();
            $stamp = $this->db->utcTimestamp($now);
            $expiresAt = $this->db->utcTimestamp($now->modify('+'.$inputTtlSeconds.' seconds'));

            $inserted = $this->db->query('ai_runs')->insertOrIgnore([
                'run_id' => $runId,
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'effective_subject' => $context->effectiveSubject,
                'channel' => $channel,
                'source_event_id' => $sourceEventId,
                'profile_revision' => $profileRevision,
                'state' => 'queued',
                'created_at' => $stamp,
                'updated_at' => $stamp,
                'next_attempt_at' => $stamp,
            ]);

            if ($inserted === 1) {
                $encrypted = $this->cipher->encrypt(
                    $inputPayload,
                    $this->inputAad($context, $runId),
                );
                $this->db->query('ai_run_inputs')->insert([
                    'run_id' => $runId,
                    'key_id' => $encrypted['key_id'],
                    'encrypted_request' => $this->db->binaryLiteral($encrypted['ciphertext']),
                    'expires_at' => $expiresAt,
                ]);
            }

            $row = $this->scopedRunQuery($context)
                ->where('channel', $channel)
                ->where('source_event_id', $sourceEventId)
                ->first();
            if ($row === null) {
                throw new RuntimeException('Failed to persist Office run.');
            }

            return $this->hydrateRun($context, $row);
        }, $context->tenantId);
    }

    /** @phpstan-impure */
    public function find(CallContext $context, string $runId): ?OfficeRun
    {
        if (! $this->isLowerUuid($runId)) {
            return null;
        }

        return $this->db->transaction(function () use ($context, $runId): ?OfficeRun {
            $row = $this->scopedRunQuery($context)->where('run_id', $runId)->first();

            return $row === null ? null : $this->hydrateRun($context, $row);
        }, $context->tenantId);
    }

    public function claimLease(CallContext $context, string $runId, int $leaseSeconds = 90): ?string
    {
        if (! $this->isLowerUuid($runId)) {
            return null;
        }

        return $this->db->transaction(function () use ($context, $runId, $leaseSeconds): ?string {
            $query = $this->scopedRunQuery($context)->where('run_id', $runId);
            if ($this->db->driver() === 'pgsql' && (clone $query)->lockForUpdate()->first(['run_id']) === null) {
                return null;
            }

            $now = $this->clock->now();
            $stamp = $this->db->utcTimestamp($now);
            $leaseToken = $this->db->newUuid();
            $leaseUntil = $this->db->utcTimestamp($now->modify('+'.$leaseSeconds.' seconds'));

            $updated = $query
                ->whereIn('state', ['queued', 'running', 'awaiting_approval', 'partially_applied', 'reconcile'])
                ->where(static function (Builder $candidate) use ($stamp): void {
                    $candidate->whereNull('lease_until')->orWhere('lease_until', '<=', $stamp);
                })
                ->update([
                    'state' => 'running',
                    'lease_token' => $leaseToken,
                    'lease_until' => $leaseUntil,
                    'updated_at' => $stamp,
                ]);

            return $updated === 1 ? $leaseToken : null;
        }, $context->tenantId);
    }

    /**
     * @param  'queued'|'running'|'awaiting_approval'|'partially_applied'|'completed'|'rejected'|'reconcile'  $state
     */
    public function finishRun(CallContext $context, string $runId, string $leaseToken, string $state): bool
    {
        if (! $this->isLowerUuid($runId) || ! $this->isLowerUuid($leaseToken)) {
            return false;
        }

        return $this->db->transaction(function () use ($context, $runId, $leaseToken, $state): bool {
            $query = $this->scopedRunQuery($context)
                ->where('run_id', $runId)
                ->where('lease_token', $leaseToken);
            if ($this->db->driver() === 'pgsql' && (clone $query)->lockForUpdate()->first(['run_id']) === null) {
                return false;
            }

            $now = $this->clock->now();
            $stamp = $this->db->utcTimestamp($now);

            $updated = $query
                ->where('lease_until', '>', $stamp)
                ->update([
                    'state' => $state,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $stamp,
                ]);

            return $updated === 1;
        }, $context->tenantId);
    }

    public function releaseLease(CallContext $context, string $runId, string $leaseToken, string $nextState = 'reconcile'): bool
    {
        if (! $this->isLowerUuid($runId) || ! $this->isLowerUuid($leaseToken)) {
            return false;
        }

        return $this->db->transaction(function () use ($context, $runId, $leaseToken, $nextState): bool {
            $stamp = $this->db->utcTimestamp($this->clock->now());

            return $this->scopedRunQuery($context)
                ->where('run_id', $runId)
                ->where('lease_token', $leaseToken)
                ->update([
                    'state' => $nextState,
                    'lease_token' => null,
                    'lease_until' => null,
                    'updated_at' => $stamp,
                ]) === 1;
        }, $context->tenantId);
    }

    /**
     * Atomically look up or create a single durable commit step for (run_id, proposal_id) before calling the owner.
     * Repeated calls—including retries with a new provider toolCallId—return the exact existing step and command_id.
     */
    public function beginCommitStep(
        CallContext $context,
        string $runId,
        string $resourceId,
        string $proposalId,
        string $digest,
        string $initialCommandId,
        string $ownerKey = '',
        ?string $executorActionKey = null,
    ): OfficeRunStep {
        if (! $this->isLowerUuid($runId) || ! $this->isLowerUuid($proposalId)) {
            throw OfficeException::invalidInput('run_id and proposal_id must be valid UUIDs.');
        }

        return $this->db->transaction(function () use (
            $context,
            $runId,
            $resourceId,
            $proposalId,
            $digest,
            $initialCommandId,
            $ownerKey,
            $executorActionKey,
        ): OfficeRunStep {
            $runQuery = $this->scopedRunQuery($context)->where('run_id', $runId);
            if ($this->db->driver() === 'pgsql') {
                $runQuery->lockForUpdate();
            }
            if ($runQuery->first(['run_id']) === null) {
                throw OfficeException::resourceUnavailable();
            }

            $existing = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('operation', OfficeOperation::Commit->value)
                ->where('proposal_id', $proposalId)
                ->first();

            if ($existing === null && $executorActionKey !== null && $executorActionKey !== '') {
                $existing = $this->findStepRowByActionKeyInTransaction($runId, $executorActionKey);
            }

            if ($existing !== null) {
                $hydrated = $this->hydrateStep($existing);
                $savedDigest = is_string($hydrated->resumeInput['digest'] ?? null)
                    ? $hydrated->resumeInput['digest']
                    : null;
                if (
                    $hydrated->operation !== OfficeOperation::Commit
                    || $hydrated->proposalId !== $proposalId
                    || $hydrated->resourceId !== $resourceId
                    || ($savedDigest !== null && ! hash_equals($savedDigest, $digest))
                ) {
                    throw new OfficeException(ErrorCode::IdempotencyConflict);
                }

                if ($executorActionKey !== null && $executorActionKey !== '') {
                    $aliases = $this->extractActionKeys($hydrated);
                    if (! in_array($executorActionKey, $aliases, true)) {
                        $other = $this->findStepByActionKeyInTransaction($runId, $executorActionKey);
                        if ($other !== null && $other->stepId !== $hydrated->stepId) {
                            throw new OfficeException(ErrorCode::IdempotencyConflict);
                        }

                        $aliases[] = $executorActionKey;
                        $resumePayload = $hydrated->resumeInput ?? [
                            'resource_id' => $resourceId,
                            'proposal_id' => $proposalId,
                            'digest' => $digest,
                            'command_id' => $hydrated->commandId ?? $initialCommandId,
                            'owner_key' => $ownerKey,
                        ];
                        $resumePayload['action_keys'] = $aliases;

                        $aad = $this->stepAad($runId, $hydrated->stepId);
                        $encResume = $this->cipher->encrypt($resumePayload, $aad);
                        $updates = [
                            'key_id' => $encResume['key_id'],
                            'encrypted_resume_input' => $this->db->binaryLiteral($encResume['ciphertext']),
                            'updated_at' => $this->db->utcTimestamp($this->clock->now()),
                        ];
                        if ($hydrated->executorActionKey === null) {
                            $updates['executor_action_key'] = $executorActionKey;
                        }
                        $rawCompact = QueryRecord::raw($existing, 'encrypted_compact_result');
                        if ($rawCompact !== null && $hydrated->compactResult !== null) {
                            $encCompact = $this->cipher->encrypt($hydrated->compactResult, $aad);
                            $updates['encrypted_compact_result'] = $this->db->binaryLiteral($encCompact['ciphertext']);
                        }

                        $this->db->query('ai_run_steps')
                            ->where('run_id', $runId)
                            ->where('step_id', $hydrated->stepId)
                            ->update($updates);

                        $reloaded = $this->db->query('ai_run_steps')
                            ->where('run_id', $runId)
                            ->where('step_id', $hydrated->stepId)
                            ->first();
                        if ($reloaded !== null) {
                            return $this->hydrateStep($reloaded);
                        }
                    }
                }

                return $hydrated;
            }

            $commandConflictInRun = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('operation', OfficeOperation::Commit->value)
                ->where('command_id', $initialCommandId)
                ->where('proposal_id', '!=', $proposalId)
                ->exists();
            if ($commandConflictInRun) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }

            $routeConflict = $this->db->query('ai_owner_routes')
                ->where('tenant_id', $context->tenantId)
                ->where('project_scope', $context->projectScope)
                ->where('effective_subject', $context->effectiveSubject)
                ->where('resource_id', $resourceId)
                ->where('command_id', $initialCommandId)
                ->where('proposal_id', '!=', $proposalId)
                ->exists();
            if ($routeConflict) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }

            $stepId = $this->nextStepIdInTransaction($runId);
            $now = $this->db->utcTimestamp($this->clock->now());
            $resumePayload = [
                'resource_id' => $resourceId,
                'proposal_id' => $proposalId,
                'digest' => $digest,
                'command_id' => $initialCommandId,
                'owner_key' => $ownerKey,
                'action_keys' => $executorActionKey !== null && $executorActionKey !== '' ? [$executorActionKey] : [],
            ];
            $encrypted = $this->cipher->encrypt($resumePayload, $this->stepAad($runId, $stepId));

            $this->db->query('ai_run_steps')->insert([
                'run_id' => $runId,
                'step_id' => $stepId,
                'operation' => OfficeOperation::Commit->value,
                'resource_id' => $resourceId,
                'proposal_id' => $proposalId,
                'command_id' => $initialCommandId,
                'receipt_id' => null,
                'executor_action_key' => $executorActionKey,
                'state' => 'dispatched',
                'created_at' => $now,
                'updated_at' => $now,
                'key_id' => $encrypted['key_id'],
                'encrypted_resume_input' => $this->db->binaryLiteral($encrypted['ciphertext']),
                'encrypted_compact_result' => null,
            ]);

            $inserted = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId)
                ->first();
            if ($inserted === null) {
                throw new RuntimeException('Failed to read persisted Office commit step.');
            }

            return $this->hydrateStep($inserted);
        }, $context->tenantId);
    }

    /**
     * @param  'pending'|'dispatched'|'waiting'|'verified'|'failed'|'finished'  $state
     * @param  array<string, mixed>|null  $resumeInput
     * @param  array<string, mixed>|null  $compactResult
     */
    public function recordStep(
        CallContext $context,
        string $runId,
        OfficeOperation $operation,
        string $state,
        ?string $resourceId = null,
        ?string $proposalId = null,
        ?string $commandId = null,
        ?string $receiptId = null,
        ?string $executorActionKey = null,
        ?array $resumeInput = null,
        ?array $compactResult = null,
        ?string $safeError = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $responseBytes = null,
        ?int $latencyMs = null,
    ): OfficeRunStep {
        if (! $this->isLowerUuid($runId)) {
            throw OfficeException::invalidInput('run_id must be a valid UUID.');
        }

        return $this->db->transaction(function () use (
            $context,
            $runId,
            $operation,
            $state,
            $resourceId,
            $proposalId,
            $commandId,
            $receiptId,
            $executorActionKey,
            $resumeInput,
            $compactResult,
            $safeError,
            $inputTokens,
            $outputTokens,
            $responseBytes,
            $latencyMs,
        ): OfficeRunStep {
            $runQuery = $this->scopedRunQuery($context)->where('run_id', $runId);
            if ($this->db->driver() === 'pgsql') {
                $runQuery->lockForUpdate();
            }
            if ($runQuery->first(['run_id']) === null) {
                throw OfficeException::resourceUnavailable();
            }

            $stepId = $this->nextStepIdInTransaction($runId);
            $now = $this->db->utcTimestamp($this->clock->now());

            $keyId = null;
            $encryptedResume = null;
            $encryptedCompact = null;
            if ($resumeInput !== null || $compactResult !== null) {
                $aad = $this->stepAad($runId, $stepId);
                if ($resumeInput !== null) {
                    $enc = $this->cipher->encrypt($resumeInput, $aad);
                    $keyId = $enc['key_id'];
                    $encryptedResume = $this->db->binaryLiteral($enc['ciphertext']);
                }
                if ($compactResult !== null) {
                    $enc = $this->cipher->encrypt($compactResult, $aad);
                    $keyId ??= $enc['key_id'];
                    $encryptedCompact = $this->db->binaryLiteral($enc['ciphertext']);
                }
            }

            $this->db->query('ai_run_steps')->insert([
                'run_id' => $runId,
                'step_id' => $stepId,
                'operation' => $operation->value,
                'resource_id' => $resourceId,
                'proposal_id' => $proposalId !== null && $this->isLowerUuid($proposalId) ? $proposalId : null,
                'command_id' => $commandId,
                'receipt_id' => $receiptId,
                'executor_action_key' => $executorActionKey,
                'state' => $state,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'response_bytes' => $responseBytes,
                'latency_ms' => $latencyMs,
                'safe_error' => $safeError,
                'created_at' => $now,
                'updated_at' => $now,
                'key_id' => $keyId,
                'encrypted_resume_input' => $encryptedResume,
                'encrypted_compact_result' => $encryptedCompact,
            ]);

            $row = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId)
                ->first();
            if ($row === null) {
                throw new RuntimeException('Failed to read recorded Office run step.');
            }

            return $this->hydrateStep($row);
        }, $context->tenantId);
    }

    /**
     * @param  'pending'|'dispatched'|'waiting'|'verified'|'failed'|'finished'  $state
     * @param  array<string, mixed>|null  $compactResult
     */
    public function updateStep(
        CallContext $context,
        string $runId,
        int $stepId,
        string $state,
        ?string $receiptId = null,
        ?array $compactResult = null,
        ?string $safeError = null,
        ?int $responseBytes = null,
        ?int $latencyMs = null,
        bool $clearSafeError = false,
    ): OfficeRunStep {
        if (! $this->isLowerUuid($runId)) {
            throw OfficeException::invalidInput('run_id must be a valid UUID.');
        }

        return $this->db->transaction(function () use (
            $context,
            $runId,
            $stepId,
            $state,
            $receiptId,
            $compactResult,
            $safeError,
            $responseBytes,
            $latencyMs,
            $clearSafeError,
        ): OfficeRunStep {
            if ($this->scopedRunQuery($context)->where('run_id', $runId)->first(['run_id']) === null) {
                throw OfficeException::resourceUnavailable();
            }

            $query = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId);
            if ($this->db->driver() === 'pgsql') {
                $query->lockForUpdate();
            }
            $row = $query->first();
            if ($row === null) {
                throw OfficeException::resourceUnavailable();
            }

            $updates = [
                'state' => $state,
                'updated_at' => $this->db->utcTimestamp($this->clock->now()),
            ];
            if ($receiptId !== null) {
                $updates['receipt_id'] = $receiptId;
            }
            if ($safeError !== null || $clearSafeError) {
                $updates['safe_error'] = $safeError;
            }
            if ($responseBytes !== null) {
                $updates['response_bytes'] = $responseBytes;
            }
            if ($latencyMs !== null) {
                $updates['latency_ms'] = $latencyMs;
            }

            if ($compactResult !== null) {
                $existingKeyId = QueryRecord::raw($row, 'key_id');
                $enc = $this->cipher->encrypt($compactResult, $this->stepAad($runId, $stepId));
                if (! is_string($existingKeyId) || $existingKeyId === '') {
                    $updates['key_id'] = $enc['key_id'];
                } else {
                    // Re-encrypt compactResult under existing key_id so both blobs share the row's key_id.
                    $updates['key_id'] = $enc['key_id'];
                    $existingResume = QueryRecord::raw($row, 'encrypted_resume_input');
                    if ($existingResume !== null) {
                        $decodedResume = $this->cipher->decrypt(
                            $existingKeyId,
                            $this->db->decodeBinary($existingResume),
                            $this->stepAad($runId, $stepId),
                        );
                        $reEnc = $this->cipher->encrypt($decodedResume, $this->stepAad($runId, $stepId));
                        $updates['encrypted_resume_input'] = $this->db->binaryLiteral($reEnc['ciphertext']);
                    }
                }
                $updates['encrypted_compact_result'] = $this->db->binaryLiteral($enc['ciphertext']);
            }

            $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId)
                ->update($updates);

            $updated = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId)
                ->first();
            if ($updated === null) {
                throw new RuntimeException('Failed to reload Office run step.');
            }

            return $this->hydrateStep($updated);
        }, $context->tenantId);
    }

    public function findStep(CallContext $context, string $runId, int $stepId): ?OfficeRunStep
    {
        if (! $this->isLowerUuid($runId)) {
            return null;
        }

        return $this->db->transaction(function () use ($context, $runId, $stepId): ?OfficeRunStep {
            if ($this->scopedRunQuery($context)->where('run_id', $runId)->first(['run_id']) === null) {
                return null;
            }
            $row = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('step_id', $stepId)
                ->first();

            return $row === null ? null : $this->hydrateStep($row);
        }, $context->tenantId);
    }

    public function findCommitStepByProposal(CallContext $context, string $runId, string $proposalId): ?OfficeRunStep
    {
        if (! $this->isLowerUuid($runId) || ! $this->isLowerUuid($proposalId)) {
            return null;
        }

        return $this->db->transaction(function () use ($context, $runId, $proposalId): ?OfficeRunStep {
            if ($this->scopedRunQuery($context)->where('run_id', $runId)->first(['run_id']) === null) {
                return null;
            }
            $row = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->where('operation', OfficeOperation::Commit->value)
                ->where('proposal_id', $proposalId)
                ->first();

            return $row === null ? null : $this->hydrateStep($row);
        }, $context->tenantId);
    }

    public function findStepByActionKey(CallContext $context, string $runId, string $executorActionKey): ?OfficeRunStep
    {
        if (! $this->isLowerUuid($runId) || $executorActionKey === '') {
            return null;
        }

        return $this->db->transaction(function () use ($context, $runId, $executorActionKey): ?OfficeRunStep {
            if ($this->scopedRunQuery($context)->where('run_id', $runId)->first(['run_id']) === null) {
                return null;
            }

            return $this->findStepByActionKeyInTransaction($runId, $executorActionKey);
        }, $context->tenantId);
    }

    private function findStepByActionKeyInTransaction(string $runId, string $executorActionKey): ?OfficeRunStep
    {
        $row = $this->findStepRowByActionKeyInTransaction($runId, $executorActionKey);

        return $row === null ? null : $this->hydrateStep($row);
    }

    private function findStepRowByActionKeyInTransaction(string $runId, string $executorActionKey): ?object
    {
        $direct = $this->db->query('ai_run_steps')
            ->where('run_id', $runId)
            ->where('executor_action_key', $executorActionKey)
            ->first();
        if ($direct !== null) {
            return $direct;
        }

        $candidates = $this->db->query('ai_run_steps')
            ->where('run_id', $runId)
            ->whereNotNull('encrypted_resume_input')
            ->orderBy('step_id')
            ->get();
        foreach ($candidates as $candidate) {
            $hydrated = $this->hydrateStep($candidate);
            if (in_array($executorActionKey, $this->extractActionKeys($hydrated), true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function extractActionKeys(OfficeRunStep $step): array
    {
        $keys = [];
        if ($step->executorActionKey !== null && $step->executorActionKey !== '') {
            $keys[] = $step->executorActionKey;
        }

        $rawAliases = $step->resumeInput['action_keys'] ?? null;
        if (is_array($rawAliases)) {
            foreach ($rawAliases as $alias) {
                if (is_string($alias) && $alias !== '' && ! in_array($alias, $keys, true)) {
                    $keys[] = $alias;
                }
            }
        }

        return $keys;
    }

    /**
     * @phpstan-impure
     *
     * @return list<OfficeRunStep>
     */
    public function listSteps(CallContext $context, string $runId): array
    {
        if (! $this->isLowerUuid($runId)) {
            return [];
        }

        return $this->db->transaction(function () use ($context, $runId): array {
            if ($this->scopedRunQuery($context)->where('run_id', $runId)->first(['run_id']) === null) {
                return [];
            }

            $rows = $this->db->query('ai_run_steps')
                ->where('run_id', $runId)
                ->orderBy('step_id')
                ->get();

            $steps = [];
            foreach ($rows as $row) {
                $steps[] = $this->hydrateStep($row);
            }

            return $steps;
        }, $context->tenantId);
    }

    private function scopedRunQuery(CallContext $context): Builder
    {
        return $this->db->query('ai_runs')->where([
            'tenant_id' => $context->tenantId,
            'project_scope' => $context->projectScope,
            'effective_subject' => $context->effectiveSubject,
        ]);
    }

    private function nextStepIdInTransaction(string $runId): int
    {
        $max = $this->db->query('ai_run_steps')
            ->where('run_id', $runId)
            ->max('step_id');

        if (is_int($max)) {
            return $max + 1;
        }
        if (is_string($max) && is_numeric($max)) {
            return ((int) $max) + 1;
        }

        return 1;
    }

    private function hydrateRun(CallContext $context, object $row): OfficeRun
    {
        $runId = QueryRecord::str($row, 'run_id');
        $inputRow = $this->db->query('ai_run_inputs')->where('run_id', $runId)->first();
        $decodedInput = null;
        if ($inputRow !== null) {
            try {
                $decodedInput = $this->cipher->decrypt(
                    QueryRecord::str($inputRow, 'key_id'),
                    $this->db->decodeBinary(QueryRecord::raw($inputRow, 'encrypted_request')),
                    $this->inputAad($context, $runId),
                );
            } catch (RuntimeException) {
                $decodedInput = null;
            }
        }

        $leaseToken = QueryRecord::raw($row, 'lease_token');
        $leaseUntil = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'lease_until'));
        $createdAt = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'created_at')) ?? $this->clock->now();
        $updatedAt = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'updated_at')) ?? $createdAt;

        return new OfficeRun(
            runId: $runId,
            tenantId: QueryRecord::str($row, 'tenant_id'),
            projectScope: QueryRecord::str($row, 'project_scope'),
            effectiveSubject: QueryRecord::str($row, 'effective_subject'),
            channel: QueryRecord::str($row, 'channel'),
            sourceEventId: QueryRecord::str($row, 'source_event_id'),
            profileRevision: QueryRecord::str($row, 'profile_revision'),
            state: QueryRecord::str($row, 'state'),
            input: $decodedInput,
            leaseToken: is_string($leaseToken) ? $leaseToken : null,
            leaseUntil: $leaseUntil,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    private function hydrateStep(object $row): OfficeRunStep
    {
        $runId = QueryRecord::str($row, 'run_id');
        $stepId = QueryRecord::int($row, 'step_id');
        $keyId = QueryRecord::raw($row, 'key_id');
        $aad = $this->stepAad($runId, $stepId);

        $resumeInput = null;
        $rawResume = QueryRecord::raw($row, 'encrypted_resume_input');
        if (is_string($keyId) && $keyId !== '' && $rawResume !== null) {
            try {
                $resumeInput = $this->cipher->decrypt($keyId, $this->db->decodeBinary($rawResume), $aad);
            } catch (RuntimeException) {
                $resumeInput = null;
            }
        }

        $compactResult = null;
        $rawCompact = QueryRecord::raw($row, 'encrypted_compact_result');
        if (is_string($keyId) && $keyId !== '' && $rawCompact !== null) {
            try {
                $compactResult = $this->cipher->decrypt($keyId, $this->db->decodeBinary($rawCompact), $aad);
            } catch (RuntimeException) {
                $compactResult = null;
            }
        }

        $resourceId = QueryRecord::raw($row, 'resource_id');
        $proposalId = QueryRecord::raw($row, 'proposal_id');
        $commandId = QueryRecord::raw($row, 'command_id');
        $receiptId = QueryRecord::raw($row, 'receipt_id');
        $actionKey = QueryRecord::raw($row, 'executor_action_key');
        $safeError = QueryRecord::raw($row, 'safe_error');

        return new OfficeRunStep(
            runId: $runId,
            stepId: $stepId,
            operation: OfficeOperation::from(QueryRecord::str($row, 'operation')),
            resourceId: is_string($resourceId) ? $resourceId : null,
            proposalId: is_string($proposalId) ? $proposalId : null,
            commandId: is_string($commandId) ? $commandId : null,
            receiptId: is_string($receiptId) ? $receiptId : null,
            executorActionKey: is_string($actionKey) ? $actionKey : null,
            state: QueryRecord::str($row, 'state'),
            inputTokens: $this->nullableInt(QueryRecord::raw($row, 'input_tokens')),
            outputTokens: $this->nullableInt(QueryRecord::raw($row, 'output_tokens')),
            responseBytes: $this->nullableInt(QueryRecord::raw($row, 'response_bytes')),
            latencyMs: $this->nullableInt(QueryRecord::raw($row, 'latency_ms')),
            safeError: is_string($safeError) ? $safeError : null,
            resumeInput: $resumeInput,
            compactResult: $compactResult,
            createdAt: $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'created_at')) ?? $this->clock->now(),
            updatedAt: $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'updated_at')) ?? $this->clock->now(),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function isLowerUuid(string $value): bool
    {
        return Str::isUuid($value) && $value === strtolower($value);
    }

    /** @return list<string> */
    private function inputAad(CallContext $context, string $runId): array
    {
        return [
            $this->db->table('ai_run_inputs'),
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $runId,
        ];
    }

    /** @return list<string> */
    private function stepAad(string $runId, int $stepId): array
    {
        return [
            $this->db->table('ai_run_steps'),
            $runId,
            (string) $stepId,
        ];
    }
}
