<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use DateTimeImmutable;

final readonly class StoredProposal
{
    public function __construct(
        public PreparedPlan $plan,
        public string $decision,
        public string $policyRevision,
        public bool $hasPayload,
        public ?string $approverSubject = null,
        public ?DateTimeImmutable $decidedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $effectiveSubject = null,
        public ?string $tenantId = null,
        public ?string $projectScope = null,
    ) {}
}
