<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\BinaryExpression;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;

/**
 * Queries committed domain command receipts in v_ai_text_commands on the OWNER Vaulter connection.
 */
final readonly class TextCommands
{
    public function __construct(
        private VaulterDatabase $database,
        private DatabaseOptions $vaulterDbOptions,
        private DocumentScopeResolver $scopes,
        private OfficeDatabase $officeDb,
        private Clock $clock,
    ) {}

    public function findOutcome(CommandScope $scope): ?CommandOutcome
    {
        return $this->outcome($this->database->connection(), $scope, fingerprint: $scope->requestFingerprint);
    }

    public function outcome(Connection $db, CommandScope $scope, ?string $proposalId = null, ?string $fingerprint = null): ?CommandOutcome
    {
        $query = $this->scopeQuery($db, $scope->context, $scope->resourceId);
        $row = (clone $query)->where('command_id', $scope->commandId)->first();
        if ($row === null && $proposalId !== null) {
            $row = $query->where('proposal_id', $proposalId)->first();
        }
        if ($row === null) {
            return null;
        }
        if (QueryRecord::str($row, 'command_id') !== $scope->commandId
            || ($fingerprint !== null && ! hash_equals($fingerprint, $this->officeDb->decodeBinary(QueryRecord::raw($row, 'request_fingerprint'))))) {
            throw new OfficeException(ErrorCode::IdempotencyConflict);
        }
        if (QueryRecord::str($row, 'state') === 'rejected') {
            return new CommandOutcome($scope->commandId, 'rejected', reason: ErrorCode::from(QueryRecord::str($row, 'reason')));
        }

        return new CommandOutcome(
            $scope->commandId, 'committed', QueryRecord::str($row, 'receipt_id'),
            new Marker(QueryRecord::int($row, 'source_generation'), QueryRecord::int($row, 'source_position')),
        );
    }

    public function record(Connection $db, CommandScope $scope, string $proposalId, string $fingerprint, string $documentId, CommandOutcome $outcome): void
    {
        $now = $this->clock->now();
        $rowKey = $db->getDriverName() === 'pgsql' ? [] : ['row_id' => (string) Str::uuid7()];
        $db->table($this->vaulterDbOptions->table('ai_text_commands'))->insert($this->scope($scope->context, $scope->resourceId) + [
            'command_id' => $scope->commandId,
            'proposal_id' => $proposalId,
            'request_fingerprint' => BinaryExpression::forDriver($db->getDriverName(), $fingerprint),
            'document_id' => $documentId,
            'state' => $outcome->state,
            'reason' => $outcome->reason?->value,
            'receipt_id' => $outcome->receiptId,
            'source_generation' => $outcome->marker?->generation,
            'source_position' => $outcome->marker?->position,
            'decided_at' => $this->officeDb->utcTimestamp($now, $db->getDriverName()),
            'dedupe_until' => $this->officeDb->utcTimestamp($now->modify('+7 days'), $db->getDriverName()),
        ] + $rowKey);
    }

    private function scopeQuery(Connection $db, CallContext $context, string $resourceId): Builder
    {
        return $db->table($this->vaulterDbOptions->table('ai_text_commands'))->where($this->scope($context, $resourceId));
    }

    /** @return array{tenant_id: int|string, project_scope: string, effective_subject: string, resource_id: string} */
    private function scope(CallContext $context, string $resourceId): array
    {
        return $this->scopes->forContext($context) + [
            'effective_subject' => $context->effectiveSubject,
            'resource_id' => $resourceId,
        ];
    }

    /**
     * Find a committed receipt marker visible to the caller's exact scope and resource.
     *
     * @return array{receipt_id: string, document_id: string|int|null, marker: Marker}|null
     */
    public function findCommittedReceipt(
        CallContext $context,
        string $resourceId,
        string $receiptId,
    ): ?array {
        if (! Str::isUuid($receiptId)) {
            return null;
        }
        $row = $this->scopeQuery($this->database->connection(), $context, $resourceId)
            ->where('receipt_id', $receiptId)
            ->where('state', 'committed')
            ->first(['receipt_id', 'document_id', 'source_generation', 'source_position']);

        if ($row === null || QueryRecord::raw($row, 'source_generation') === null || QueryRecord::raw($row, 'source_position') === null) {
            return null;
        }

        $docId = QueryRecord::raw($row, 'document_id');

        return [
            'receipt_id' => QueryRecord::str($row, 'receipt_id'),
            'document_id' => is_int($docId) || is_string($docId) ? $docId : null,
            'marker' => new Marker(QueryRecord::int($row, 'source_generation'), QueryRecord::int($row, 'source_position')),
        ];
    }

    /**
     * Seed a committed owner receipt fixture for integration testing before 3.3 owner writes land.
     */
    public function recordCommittedReceiptFixture(
        CallContext $context,
        string $resourceId,
        string|int $documentId,
        string $commandId,
        string $proposalId,
        string $receiptId,
        Marker $marker,
        DateTimeImmutable $dedupeUntil,
    ): void {
        $scope = $this->scopes->forContext($context);
        $fingerprint = hash('sha256', $commandId.'|'.$proposalId.'|'.$receiptId, true);
        $now = $this->officeDb->utcTimestamp(new DateTimeImmutable('now', new DateTimeZone('UTC')), $this->database->driver());

        $this->database->transaction(function () use (
            $scope,
            $context,
            $resourceId,
            $documentId,
            $commandId,
            $proposalId,
            $receiptId,
            $marker,
            $dedupeUntil,
            $fingerprint,
            $now,
        ): void {
            $table = $this->vaulterDbOptions->table('ai_text_commands');
            $row = [
                'tenant_id' => $scope['tenant_id'],
                'project_scope' => $scope['project_scope'],
                'effective_subject' => $context->effectiveSubject,
                'resource_id' => $resourceId,
                'document_id' => $documentId,
                'command_id' => $commandId,
                'proposal_id' => $proposalId,
                'request_fingerprint' => $this->officeDb->binaryLiteral($fingerprint),
                'state' => 'committed',
                'reason' => null,
                'receipt_id' => $receiptId,
                'source_generation' => $marker->generation,
                'source_position' => $marker->position,
                'decided_at' => $now,
                'dedupe_until' => $this->officeDb->utcTimestamp($dedupeUntil, $this->database->driver()),
            ];

            if ($this->database->driver() !== 'pgsql') {
                $row['row_id'] = $this->officeDb->newUuid();
            }

            $this->database->connection()->table($table)->insert($row);
        });
    }
}
