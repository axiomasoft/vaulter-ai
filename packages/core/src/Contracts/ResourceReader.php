<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use stdClass;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ResourceType;

/**
 * Reads bounded, addressable resource slices for a registered ResourceType.
 */
interface ResourceReader
{
    public function resourceType(): ResourceType;

    public function read(
        CallContext $context,
        ResourceEntry $resource,
        stdClass $selector,
        ?int $limit,
        ?string $cursor,
        ?string $afterReceipt,
    ): ReadPage;
}
