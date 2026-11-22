<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use Vaulter\Ai\Core\Enums\ErrorCode;

/**
 * Authoritative owner outcome for a committed, running, or durably rejected command.
 */
final readonly class CommandOutcome
{
    public function __construct(
        public string $commandId,
        public string $state,
        public ?string $receiptId = null,
        public ?Marker $marker = null,
        public ?ErrorCode $reason = null,
    ) {}
}
