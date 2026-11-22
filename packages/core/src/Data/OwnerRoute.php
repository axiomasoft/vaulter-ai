<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use DateTimeImmutable;

final readonly class OwnerRoute
{
    public function __construct(
        public string $commandId,
        public string $resourceId,
        public string $proposalId,
        public string $fingerprint,
        public string $ownerKey,
        public ?string $receiptId,
        public DateTimeImmutable $dedupeUntil,
    ) {}
}
