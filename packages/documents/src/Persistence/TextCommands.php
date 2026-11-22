<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
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
    ) {}

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
        $scope = $this->scopes->forContext($context);
        $row = $this->database->connection()
            ->table($this->vaulterDbOptions->table('ai_text_commands'))
            ->where('tenant_id', $scope['tenant_id'])
            ->where('project_scope', $scope['project_scope'])
            ->where('effective_subject', $context->effectiveSubject)
            ->where('resource_id', $resourceId)
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
