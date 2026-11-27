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
        public ?int $maxTokens = null,
        public ?int $maxCostMicros = null,
        public int $inputCostMicrosPerMillion = 0,
        public int $outputCostMicrosPerMillion = 0,
    ) {}

    public function exceedsUsageBudget(int $input, int $output): bool
    {
        return ($this->maxTokens !== null && $input + $output > $this->maxTokens)
            || ($this->maxCostMicros !== null &&
                ($input * $this->inputCostMicrosPerMillion + $output * $this->outputCostMicrosPerMillion) / 1000000 > $this->maxCostMicros);
    }
}
