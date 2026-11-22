<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use Vaulter\Ai\Core\Enums\ApprovalMode;

/**
 * Host profile configuration governing allowed commands, executor, and approval mode.
 */
final readonly class HostProfile
{
    /**
     * @param  list<string>  $commands
     */
    public function __construct(
        public string $name,
        public string $executor,
        public ?string $providerConnection,
        public array $commands,
        public ApprovalMode $approval,
        public string $revision = '1',
    ) {}
}
