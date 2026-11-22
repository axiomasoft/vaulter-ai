<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

final readonly class StoredProposal
{
    public function __construct(
        public PreparedPlan $plan,
        public string $decision,
        public string $policyRevision,
        public bool $hasPayload,
    ) {}
}
