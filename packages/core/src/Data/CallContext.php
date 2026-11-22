<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use InvalidArgumentException;

/**
 * Trusted server-constructed caller context. Never populated from LLM or HTTP request JSON fields.
 */
final readonly class CallContext
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $tenantId,
        public string $projectScope,
        public string $effectiveSubject,
        public string $profile = 'documents',
        public string $policyRevision = '1',
        public ?string $driveId = null,
        public ?object $actor = null,
        public array $attributes = [],
    ) {
        self::assertScopeString('tenantId', $tenantId);
        self::assertScopeString('projectScope', $projectScope);
        self::assertScopeString('effectiveSubject', $effectiveSubject);
        if ($profile === '') {
            throw new InvalidArgumentException('CallContext profile cannot be empty.');
        }
        if ($policyRevision === '') {
            throw new InvalidArgumentException('CallContext policyRevision cannot be empty.');
        }
    }

    private static function assertScopeString(string $field, string $value): void
    {
        $len = strlen($value);
        if ($len < 1 || $len > 255) {
            throw new InvalidArgumentException("CallContext {$field} must be between 1 and 255 bytes.");
        }
    }
}
