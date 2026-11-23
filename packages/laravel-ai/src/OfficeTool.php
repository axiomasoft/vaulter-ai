<?php

declare(strict_types=1);

namespace Vaulter\Ai\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use JsonException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use stdClass;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Laravel AI tool adapter wrapping one of the seven canonical Office SDK v1 operations.
 * Rejects caller-authority injection and routes office.commit through StepCommitCoordinator.
 */
final readonly class OfficeTool implements Tool
{
    /** @var array<string, list<string>> */
    private const array ALLOWED_KEYS = [
        OfficeOperation::Search->value => ['query', 'resource_type', 'limit', 'cursor'],
        OfficeOperation::Read->value => ['resource_id', 'selector', 'limit', 'cursor', 'after_receipt'],
        OfficeOperation::Describe->value => ['resource_id', 'command'],
        OfficeOperation::Prepare->value => ['resource_id', 'command', 'read_token', 'arguments'],
        OfficeOperation::Commit->value => ['resource_id', 'proposal_id', 'digest', 'command_id'],
        OfficeOperation::Status->value => ['resource_id', 'command_id'],
        OfficeOperation::Verify->value => ['resource_id', 'receipt_id', 'mode'],
    ];

    public function __construct(
        private OfficeOperation $operation,
        private CallContext $context,
        private HostProfile $profile,
        private Office $office,
        private Runs $runs,
        private StepCommitCoordinator $commits,
        private WireErrorMapper $errorMapper,
        private ?string $runId = null,
        private ?OfficeRegistry $registry = null,
        private ?ResourceDirectory $directory = null,
        private ?CommandWriteCapability $writeCapability = null,
    ) {}

    public function name(): string
    {
        return $this->operation->toolName();
    }

    public function operation(): OfficeOperation
    {
        return $this->operation;
    }

    public function description(): string
    {
        $commands = $this->profile->commands === [] ? 'none' : implode(', ', $this->profile->commands);

        return OfficeToolSchema::descriptionFor($this->operation, $commands);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return OfficeToolSchema::forOperation($this->operation, $schema);
    }

    public function handle(Request $request): string
    {
        $startedAt = hrtime(true);
        $rawArgs = $request->all();
        $allowedKeys = self::ALLOWED_KEYS[$this->operation->value];

        foreach (array_keys($rawArgs) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                return $this->errorMapper->toResponse($this->operation, ErrorCode::InvalidInput)->toJson();
            }
        }

        if (in_array($this->operation, [OfficeOperation::Describe, OfficeOperation::Prepare], true)) {
            $command = $rawArgs['command'] ?? null;
            if (! is_string($command) || ! in_array($command, $this->profile->commands, true)) {
                return $this->errorMapper->toResponse($this->operation, ErrorCode::ResourceUnavailable)->toJson();
            }
            if ($this->registry !== null) {
                $registered = $this->registry->command($command);
                if ($registered === null) {
                    return $this->errorMapper->toResponse($this->operation, ErrorCode::ResourceUnavailable)->toJson();
                }
                if ($this->directory !== null && $this->writeCapability !== null && is_string($rawArgs['resource_id'] ?? null)) {
                    $descriptor = $registered->descriptor();
                    $entry = $this->directory->findAccessible($this->context, $rawArgs['resource_id'], $descriptor->requiredPermission);
                    if ($entry === null || $descriptor->resourceType !== $entry->resourceType) {
                        return $this->errorMapper->toResponse($this->operation, ErrorCode::ResourceUnavailable)->toJson();
                    }
                    if (! $this->writeCapability->canWrite($this->context, $entry, $descriptor)) {
                        return $this->errorMapper->toResponse($this->operation, ErrorCode::UnsupportedContent)->toJson();
                    }
                }
            }
        }

        if ($this->operation === OfficeOperation::Commit) {
            return $this->handleCommit($rawArgs, $request->toolCallId());
        }

        $input = $this->toStdClass($rawArgs);
        $response = $this->office->handle($this->context, new OfficeRequest(1, $this->operation, $input));

        if ($this->runId !== null) {
            $this->recordNonCommitStep($rawArgs, $response, $startedAt);
        }

        return $response->toJson();
    }

    /**
     * @param  array<string, mixed>  $rawArgs
     */
    private function handleCommit(array $rawArgs, ?string $toolCallId): string
    {
        $resourceId = $rawArgs['resource_id'] ?? null;
        $proposalId = $rawArgs['proposal_id'] ?? null;
        $digest = $rawArgs['digest'] ?? null;
        if (! is_string($resourceId) || $resourceId === ''
            || ! is_string($proposalId) || $proposalId === ''
            || ! is_string($digest) || $digest === '') {
            return $this->errorMapper->toResponse(OfficeOperation::Commit, ErrorCode::InvalidInput)->toJson();
        }

        $preferredCommandId = is_string($rawArgs['command_id'] ?? null) && $rawArgs['command_id'] !== ''
            ? $rawArgs['command_id']
            : null;

        if ($this->runId !== null) {
            $outcome = $this->commits->executeOrResume(
                context: $this->context,
                runId: $this->runId,
                resourceId: $resourceId,
                proposalId: $proposalId,
                digest: $digest,
                preferredCommandId: $preferredCommandId,
            );

            return $outcome['response']->toJson();
        }

        $fallbackCommandId = $preferredCommandId ?? ($toolCallId !== null && $toolCallId !== '' ? $toolCallId : null);

        return $this->commits->executeStandaloneCommit(
            context: $this->context,
            resourceId: $resourceId,
            proposalId: $proposalId,
            digest: $digest,
            preferredCommandId: $fallbackCommandId,
        )->toJson();
    }

    /**
     * @param  array<string, mixed>  $rawArgs
     */
    private function recordNonCommitStep(array $rawArgs, OfficeResponse $response, int $startedAt): void
    {
        if ($this->runId === null) {
            return;
        }

        $resourceId = is_string($rawArgs['resource_id'] ?? null) ? $rawArgs['resource_id'] : null;
        $commandId = is_string($rawArgs['command_id'] ?? null) ? $rawArgs['command_id'] : null;
        $receiptId = is_string($rawArgs['receipt_id'] ?? null) ? $rawArgs['receipt_id'] : null;
        $proposalId = null;

        if ($response->ok && $response->result instanceof stdClass) {
            if (is_string($response->result->proposal_id ?? null)) {
                $proposalId = $response->result->proposal_id;
            }
            if (is_string($response->result->receipt_id ?? null)) {
                $receiptId = $response->result->receipt_id;
            }
        }

        $state = 'finished';
        $safeError = null;
        if (! $response->ok) {
            $state = 'failed';
            $safeError = $response->errorCode?->value;
        } elseif ($this->operation === OfficeOperation::Verify) {
            if (($response->result->outcome ?? null) === 'passed') {
                $state = 'verified';
            } else {
                $state = 'failed';
                $safeError = 'verification_failed';
            }
        }

        $this->runs->recordStep(
            context: $this->context,
            runId: $this->runId,
            operation: $this->operation,
            state: $state,
            resourceId: $resourceId,
            proposalId: $proposalId,
            commandId: $commandId,
            receiptId: $receiptId,
            compactResult: $response->toArray(),
            safeError: $safeError,
            responseBytes: $response->wireBytes(),
            latencyMs: max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toStdClass(array $data): stdClass
    {
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new stdClass;
        }

        return $decoded instanceof stdClass ? $decoded : new stdClass;
    }
}
