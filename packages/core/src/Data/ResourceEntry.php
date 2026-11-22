<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use Vaulter\Ai\Core\Enums\ResourceType;

/**
 * Authorized resource descriptor resolved within the caller's trust boundary.
 */
final readonly class ResourceEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $resourceId,
        public ResourceType $resourceType,
        public string $title,
        public ?string $writeMode = null,
        public string $schemaRevision = '1',
        public ?string $driveId = null,
        public ?string $sortKey = null,
        public array $metadata = [],
    ) {}
}
