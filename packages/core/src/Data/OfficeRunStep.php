<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use DateTimeImmutable;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Immutable representation of one durable step row inside v_ai_run_steps.
 */
final readonly class OfficeRunStep
{
    /**
     * @param  array<string, mixed>|null  $resumeInput
     * @param  array<string, mixed>|null  $compactResult
     */
    public function __construct(
        public string $runId,
        public int $stepId,
        public OfficeOperation $operation,
        public ?string $resourceId,
        public ?string $proposalId,
        public ?string $commandId,
        public ?string $receiptId,
        public ?string $executorActionKey,
        public string $state,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $responseBytes,
        public ?int $latencyMs,
        public ?string $safeError,
        public ?array $resumeInput,
        public ?array $compactResult,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
