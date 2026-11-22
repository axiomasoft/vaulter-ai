<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use Vaulter\Ai\Core\Enums\ApprovalMode;

/**
 * Immutable prepared command plan combining wire proposal fields and private owner preconditions.
 */
final readonly class PreparedPlan
{
    /**
     * @param  list<string>  $targets
     * @param  array<string, mixed>  $preconditions
     */
    public function __construct(
        public string $proposalId,
        public string $command,
        public string $resourceId,
        public Marker $base,
        public ApprovalMode $approval,
        public string $summary,
        public array $targets,
        public string $expiresAt,
        public string $digest,
        public string $ownerKey,
        public array $preconditions = [],
    ) {}
}
