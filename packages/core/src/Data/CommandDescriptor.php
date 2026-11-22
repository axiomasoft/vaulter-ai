<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use stdClass;
use Vaulter\Ai\Core\Enums\ResourceType;

/**
 * Versioned command metadata returned by office.describe and inspected at boot for collisions.
 */
final readonly class CommandDescriptor
{
    public function __construct(
        public string $command,
        public string $name,
        public int $version,
        public ResourceType $resourceType,
        public string $effect,
        public stdClass $inputSchema,
        public string $verification,
        public string $requiredPermission = 'edit',
    ) {}
}
