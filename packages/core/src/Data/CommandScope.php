<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

/**
 * Trusted command execution tuple binding context, resource and caller command_id.
 */
final readonly class CommandScope
{
    public function __construct(
        public CallContext $context,
        public string $resourceId,
        public string $commandId,
    ) {}
}
