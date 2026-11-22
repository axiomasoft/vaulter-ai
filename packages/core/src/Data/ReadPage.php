<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use stdClass;

/**
 * Single page returned by a ResourceReader before token issuance and byte-limit checks.
 */
final readonly class ReadPage
{
    /**
     * @param  array<string, mixed>  $selectorDependencies
     */
    public function __construct(
        public Marker $marker,
        public stdClass $data,
        public bool $truncated,
        public ?string $nextCursor,
        public string $selectorKind,
        public string $schemaRevision,
        public bool $completeScope,
        public array $selectorDependencies = [],
    ) {}
}
