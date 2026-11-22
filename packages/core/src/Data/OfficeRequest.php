<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use stdClass;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Validated inbound Office SDK v1 request envelope.
 */
final readonly class OfficeRequest
{
    public function __construct(
        public int $version,
        public OfficeOperation $operation,
        public stdClass $input,
    ) {}
}
