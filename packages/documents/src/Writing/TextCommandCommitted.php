<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\DomainEvent;
use Vaulter\ValueObjects\Events\EventScope;
use Vaulter\ValueObjects\Events\EventSubject;

final readonly class TextCommandCommitted extends DomainEvent
{
    public function __construct(
        private EventScope $eventScope,
        private string $resourceId,
        private string $commandId,
        private string $receiptId,
        private Marker $marker,
        DriveContext $context,
    ) {
        parent::__construct($context->actor->ref(), $context->correlationId);
    }

    public function type(): TextCommandEventType
    {
        return TextCommandEventType::Committed;
    }

    public function scope(): EventScope
    {
        return $this->eventScope;
    }

    public function subject(): EventSubject
    {
        return new EventSubject('node', $this->resourceId);
    }

    public function sequence(): int
    {
        return $this->marker->position;
    }

    /** @return array{resource_id: string, command_id: string, receipt_id: string, generation: int, position: int} */
    public function data(): array
    {
        return [
            'resource_id' => $this->resourceId,
            'command_id' => $this->commandId,
            'receipt_id' => $this->receiptId,
            'generation' => $this->marker->generation,
            'position' => $this->marker->position,
        ];
    }
}
