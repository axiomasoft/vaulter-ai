<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Documents\Enums\DocumentEventType;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxEnvelope;

/**
 * Durable Vaulter outbox consumer ensuring text projections catch up via vaulter:relay
 * even if the originating owner process exited after SQL commit before in-process listeners ran.
 */
final readonly class TextProjectionOutboxConsumer implements OutboxConsumer
{
    public const string ID = 'ai.documents.text_projection';

    public function __construct(
        private IndexText $indexer,
        private DocumentScopeResolver $scopes,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Durable;
    }

    public function subscribes(EventTypeContract $type): bool
    {
        return in_array($type, [
            DocumentEventType::DocumentCreated,
            DocumentEventType::DocumentUpdated,
            DocumentEventType::DocumentRestored,
            DriveEventType::NodeRestored,
        ], true);
    }

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult
    {
        $driveId = $this->resolveDriveId($envelope);
        $nodeId = $this->resolveNodeId($envelope);
        if ($driveId === null || $nodeId === null) {
            return ConsumeResult::done();
        }

        $data = is_array($envelope->payload['data'] ?? null) ? $envelope->payload['data'] : [];
        $minGeneration = is_int($data['epoch'] ?? null) ? $data['epoch'] : null;
        $scope = $this->scopes->forDriveEvent($envelope->tenantId, $driveId);

        try {
            if ($envelope->type === DriveEventType::NodeRestored->value) {
                $this->indexer->projectRestoredNode($driveId, $nodeId, $scope['tenant_id'], $scope['project_scope']);
            } else {
                $this->indexer->projectNode($driveId, $nodeId, $scope['tenant_id'], $scope['project_scope'], $minGeneration);
            }
        } catch (DocumentStatePendingException $pending) {
            return ConsumeResult::retry($pending->getMessage());
        }

        return ConsumeResult::done();
    }

    private function resolveDriveId(OutboxEnvelope $envelope): ?string
    {
        if ($envelope->scopeKind === OutboxEnvelope::SCOPE_DRIVE && is_string($envelope->scopeId) && $envelope->scopeId !== '') {
            return $envelope->scopeId;
        }

        $payloadDrive = $envelope->payload['drive_id'] ?? null;

        return is_string($payloadDrive) && $payloadDrive !== '' ? $payloadDrive : null;
    }

    private function resolveNodeId(OutboxEnvelope $envelope): ?string
    {
        if ($envelope->aggregateType === 'node' && is_string($envelope->aggregateId) && $envelope->aggregateId !== '') {
            return $envelope->aggregateId;
        }

        $subject = $envelope->payload['subject'] ?? null;
        $subjectId = is_array($subject) ? ($subject['id'] ?? null) : null;

        return is_string($subjectId) && $subjectId !== '' ? $subjectId : null;
    }
}
