<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use DateTimeImmutable;

/**
 * Immutable representation of a persisted AI run and its optional decrypted input payload.
 */
final readonly class OfficeRun
{
    /**
     * @param  array<string, mixed>|null  $input
     */
    public function __construct(
        public string $runId,
        public string $tenantId,
        public string $projectScope,
        public string $effectiveSubject,
        public string $channel,
        public string $sourceEventId,
        public string $profileRevision,
        public string $state,
        public ?array $input,
        public ?string $leaseToken,
        public ?DateTimeImmutable $leaseUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
