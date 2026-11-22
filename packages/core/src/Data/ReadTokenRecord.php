<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use DateTimeImmutable;

/**
 * Verified server-side read token metadata decrypted from authenticated ciphertext.
 */
final readonly class ReadTokenRecord
{
    /**
     * @param  array<string, mixed>  $dependencies
     */
    public function __construct(
        public string $tokenHashHex,
        public string $tenantId,
        public string $projectScope,
        public string $effectiveSubject,
        public string $resourceId,
        public string $selectorKind,
        public Marker $marker,
        public string $schemaRevision,
        public bool $completeScope,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $keyId,
        public array $dependencies,
    ) {}
}
