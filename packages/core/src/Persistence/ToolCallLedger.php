<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use LogicException;
use stdClass;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\WireValidator;

final readonly class ToolCallLedger
{
    public const int LEASE_SECONDS = 90;

    public function __construct(
        private OfficeDatabase $db,
        private Runs $runs,
        private Clock $clock,
        private WireValidator $validator,
    ) {}

    public function parse(OfficeOperation $operation, string $json): OfficeRequest
    {
        if (strlen($json) > 65536) {
            throw new OfficeException(ErrorCode::LimitExceeded);
        }
        try {
            $input = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw OfficeException::invalidInput();
        }
        if (! $input instanceof stdClass) {
            throw OfficeException::invalidInput();
        }
        $wireInput = $input;
        if ($operation === OfficeOperation::Commit && ! property_exists($input, 'command_id')) {
            $wireInput = (object) (get_object_vars($input) + ['command_id' => 'assigned-by-chat']);
        }
        if (! $this->validator->isValidRequest((object) [
            'version' => 1, 'operation' => $operation->value, 'input' => $wireInput,
        ])) {
            throw OfficeException::invalidInput();
        }

        return new OfficeRequest(1, $operation, $input);
    }

    /**
     * Called in the transaction that publishes tool.requested. The ordinal identifies the
     * server-side call within this turn, including fresh calls with identical arguments.
     *
     * @return array{id:string, created:bool}
     */
    public function request(CallContext $context, string $runId, string $runLease, int $ordinal, OfficeOperation $operation, string $json): array
    {
        $request = $this->parse($operation, $json);
        $this->lockRun($context, $runId, $runLease);
        $id = hash('sha256', $runId.':'.$ordinal);
        try {
            $digest = hash('sha256', ProposalDigest::canonicalize((object) [
                'tenant' => $context->tenantId,
                'project' => $context->projectScope,
                'subject' => $context->effectiveSubject,
                'call' => $id,
                'tool' => $operation->value,
                'version' => '1',
                'input' => $request->input,
            ]), true);
        } catch (InvalidArgumentException) {
            throw OfficeException::invalidInput();
        }

        $query = $this->call($runId, $id);
        $old = $query->first();
        if ($old !== null) {
            if (! hash_equals($this->db->decodeBinary(QueryRecord::raw($old, 'input_digest')), $digest)) {
                throw new OfficeException(ErrorCode::IdempotencyConflict);
            }

            return ['id' => $id, 'created' => false];
        }

        $resource = $request->input->resource_id ?? null;
        $proposal = $request->input->proposal_id ?? null;
        $step = $this->runs->recordStep(
            $context,
            $runId,
            $operation,
            'pending',
            resourceId: is_string($resource) ? $resource : null,
            proposalId: is_string($proposal) ? $proposal : null,
            executorActionKey: $id,
            resumeInput: ['tool_input' => $json],
        );
        $stamp = $this->db->utcTimestamp($this->db->clockNow($this->clock));
        $this->db->query('ai_tool_calls')->insert([
            'run_id' => $runId,
            'call_id' => $id,
            'step_id' => $step->stepId,
            'tool_name' => $operation->value,
            'tool_version' => '1',
            'input_digest' => $this->db->binaryLiteral($digest),
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return ['id' => $id, 'created' => true];
    }

    /** @return list<array{id:string, step_id:int, state:string}> */
    public function calls(CallContext $context, string $runId, string $runLease): array
    {
        return $this->db->transaction(function () use ($context, $runId, $runLease): array {
            $this->lockRun($context, $runId, $runLease);
            $calls = [];
            foreach ($this->db->query('ai_tool_calls')->where('run_id', $runId)->orderBy('step_id')->get() as $row) {
                $calls[] = [
                    'id' => QueryRecord::str($row, 'call_id'),
                    'step_id' => QueryRecord::int($row, 'step_id'),
                    'state' => QueryRecord::str($row, 'state'),
                ];
            }

            return $calls;
        }, $context->tenantId);
    }

    public function claim(CallContext $context, string $runId, string $runLease, string $callId): ?string
    {
        return $this->db->transaction(function () use ($context, $runId, $runLease, $callId): ?string {
            $this->lockRun($context, $runId, $runLease);
            $query = $this->call($runId, $callId)->lockForUpdate();
            $row = $query->first() ?? throw OfficeException::resourceUnavailable();
            $now = $this->db->clockNow($this->clock);
            $until = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'lease_until'));
            if (! in_array(QueryRecord::str($row, 'state'), ['requested', 'dispatched', 'awaiting_approval'], true)
                || ($until !== null && $until > $now)) {
                return null;
            }

            $lease = (string) Str::uuid();
            $query->update([
                'state' => 'dispatched',
                'lease_token' => $lease,
                'lease_until' => $this->db->utcTimestamp($now->modify('+'.self::LEASE_SECONDS.' seconds')),
                'updated_at' => $this->db->utcTimestamp($now),
            ]);

            return $lease;
        }, $context->tenantId);
    }

    /** Must run in the same transaction as the state transition or event that uses this fence. */
    public function lockRun(CallContext $context, string $runId, string $runLease): void
    {
        if ($this->db->connection()->transactionLevel() === 0) {
            throw new LogicException('The chat run fence requires a transaction.');
        }
        $query = $this->db->query('ai_runs')->where([
            'run_id' => $runId,
            'tenant_id' => $context->tenantId,
            'project_scope' => $context->projectScope,
            'effective_subject' => $context->effectiveSubject,
            'channel' => 'chat',
            'lease_token' => $runLease,
        ])->useWritePdo();
        if ($this->db->driver() !== 'pgsql') {
            (clone $query)->update(['lease_token' => $runLease]);
        }
        $row = $query->lockForUpdate()->first(['lease_until']);
        $until = $row !== null ? $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'lease_until')) : null;
        if ($until === null || $until <= $this->db->clockNow($this->clock)) {
            throw OfficeException::temporarilyUnavailable();
        }
    }

    /**
     * Record the durable command identity before calling its owner, or settle the call and
     * release it. The caller commits settlement together with the step result and SSE events.
     *
     * @param  'dispatched'|'awaiting_approval'|'committed'|'verified'|'rejected'|'failed'  $state
     */
    public function transition(
        CallContext $context,
        string $runId,
        string $runLease,
        string $callId,
        string $callLease,
        string $state,
        ?string $proposalId,
        ?string $commandId,
        ?string $receiptId = null,
        bool $release = true,
    ): void {
        $this->lockRun($context, $runId, $runLease);
        $query = $this->call($runId, $callId)->where('lease_token', $callLease)->lockForUpdate();
        $row = $query->first();
        $now = $this->db->clockNow($this->clock);
        $until = $row !== null ? $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'lease_until')) : null;
        if ($until === null || $until <= $now) {
            throw OfficeException::temporarilyUnavailable();
        }
        $changes = [
            'state' => $state,
            'proposal_id' => $proposalId,
            'command_id' => $commandId,
            'receipt_id' => $receiptId,
            'updated_at' => $this->db->utcTimestamp($now),
        ];
        if ($release) {
            $changes['lease_token'] = null;
            $changes['lease_until'] = null;
        }
        $query->update($changes);
    }

    private function call(string $runId, string $callId): Builder
    {
        return $this->db->query('ai_tool_calls')->where(['run_id' => $runId, 'call_id' => $callId]);
    }
}
