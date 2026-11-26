<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\ToolPort;
use AgenTools\Error\ErrorCategory;
use AgenTools\Error\ToolError;
use AgenTools\Error\ToolExecutionResult;
use JsonException;
use stdClass;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * AgenTools ToolPort adapter invoking Office SDK operations within a trusted CallContext.
 * Bind a caller to the port for one request or run with forContext().
 */
final readonly class OfficeToolPort implements ToolPort
{
    public function __construct(
        private Office $office,
        private HostPolicy $policy,
        private OfficeToolDescriptorRegistry $descriptors,
        private ActionKeyMap $actionKeyMap,
        private Runs $runs,
        private Proposals $proposals,
        private ResourceDirectory $directory,
        private StepCommitCoordinator $commits,
        private WireErrorMapper $errorMapper,
        private ?CallContext $context = null,
        private ?string $runId = null,
        private ?OfficeRegistry $officeRegistry = null,
        private ?CommandWriteCapability $writeCapability = null,
        /** @var list<string>|null A null scope delegates to the caller’s current authorization. */
        private ?array $scopedResourceIds = null,
    ) {}

    /** @param list<ResourceEntry>|null $resources */
    public function forContext(CallContext $context, ?string $runId = null, ?array $resources = null): self
    {
        return new self(
            office: $this->office,
            policy: $this->policy,
            descriptors: $resources === null
                ? $this->descriptors->forContext($context)
                : $this->descriptors->forScope($context, $resources),
            actionKeyMap: $this->actionKeyMap,
            runs: $this->runs,
            proposals: $this->proposals,
            directory: $this->directory,
            commits: $this->commits,
            errorMapper: $this->errorMapper,
            context: $context,
            runId: $runId,
            officeRegistry: $this->officeRegistry,
            writeCapability: $this->writeCapability,
            scopedResourceIds: $resources === null ? null : array_map(static fn (ResourceEntry $entry): string => $entry->resourceId, $resources),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        $startedAt = hrtime(true);
        if ($this->context === null) {
            return ToolExecutionResult::failure(new ToolError(
                'vaulter.permission.authentication_required',
                ErrorCategory::PERMISSION,
                false,
                'An authenticated host CallContext is required.',
            ));
        }

        if ($idempotencyKey === '') {
            return ToolExecutionResult::failure(new ToolError(
                'vaulter.validation.idempotency_key_missing',
                ErrorCategory::VALIDATION,
                false,
                'An idempotency key is required for ToolPort calls.',
            ));
        }

        $operation = OfficeOperation::fromToolName($tool);
        $descriptor = $this->descriptors->descriptor($tool);
        if ($operation === null || $descriptor === null) {
            return ToolExecutionResult::failure(new ToolError(
                'vaulter.not_found.resource_unavailable',
                ErrorCategory::NOT_FOUND,
                false,
                'The requested Office tool is unavailable for this context.',
            ));
        }

        /** @var array<string, mixed> $allowedProps */
        $allowedProps = is_array($descriptor->inputSchema['properties'] ?? null)
            ? $descriptor->inputSchema['properties']
            : [];
        foreach (array_keys($arguments) as $argKey) {
            if (! array_key_exists($argKey, $allowedProps)) {
                return ToolExecutionResult::failure(new ToolError(
                    'vaulter.validation.invalid_input',
                    ErrorCategory::VALIDATION,
                    false,
                    'Unexpected argument for Office tool.',
                ));
            }
        }

        // A port scoped to resources must not reach any other resource the caller can access,
        // and search could only surface such resources, so it is refused too.
        if ($this->scopedResourceIds !== null) {
            $resourceId = $arguments['resource_id'] ?? null;
            if ($operation === OfficeOperation::Search || ! is_string($resourceId)
                || ! in_array($resourceId, $this->scopedResourceIds, true)) {
                return $this->toToolResult($this->errorMapper->toResponse($operation, ErrorCode::ResourceUnavailable));
            }
        }

        $profile = $this->policy->profile($this->context);
        if (in_array($operation, [OfficeOperation::Describe, OfficeOperation::Prepare], true)) {
            $command = $arguments['command'] ?? null;
            if (! is_string($command) || ! in_array($command, $profile->commands, true)) {
                return ToolExecutionResult::failure(new ToolError(
                    'vaulter.permission.resource_unavailable',
                    ErrorCategory::PERMISSION,
                    false,
                    'The requested command is not published for this profile.',
                ));
            }
            if ($this->officeRegistry !== null) {
                $registered = $this->officeRegistry->command($command);
                if ($registered === null) {
                    return ToolExecutionResult::failure(new ToolError(
                        'vaulter.not_found.resource_unavailable',
                        ErrorCategory::NOT_FOUND,
                        false,
                        'The requested Office command version is not installed.',
                    ));
                }
                if (is_string($arguments['resource_id'] ?? null)) {
                    $cmdDescriptor = $registered->descriptor();
                    $entry = $this->directory->findAccessible($this->context, $arguments['resource_id'], $cmdDescriptor->requiredPermission);
                    if ($entry === null || $cmdDescriptor->resourceType !== $entry->resourceType
                        || ! $this->policy->allowsCommand($this->context, $entry, $command)) {
                        return $this->toToolResult($this->errorMapper->toResponse($operation, ErrorCode::ResourceUnavailable));
                    }
                    if ($this->writeCapability !== null && ! $this->writeCapability->canWrite($this->context, $entry, $cmdDescriptor)) {
                        return $this->toToolResult($this->errorMapper->toResponse($operation, ErrorCode::UnsupportedContent));
                    }
                }
            }
        }

        if ($operation === OfficeOperation::Commit) {
            return $this->handleCommit($arguments, $idempotencyKey);
        }

        $input = $this->toStdClass($arguments);
        if ($operation === OfficeOperation::Prepare && $this->runId !== null) {
            $replayed = $this->replayPreparedStepIfPresent($input, $idempotencyKey);
            if ($replayed !== null) {
                return $replayed;
            }
        }

        $response = $this->office->handle($this->context, new OfficeRequest(1, $operation, $input));
        $this->recordStep($operation, $input, $response, $idempotencyKey, $startedAt);

        return $this->toToolResult($response);
    }

    /**
     * Replay a previously persisted successful office.prepare step within the same run/action key
     * (e.g. when resuming a ScenarioRunner run after human approval) after verifying live ACL & arguments.
     */
    private function replayPreparedStepIfPresent(stdClass $input, string $idempotencyKey): ?ToolExecutionResult
    {
        if ($this->context === null || $this->runId === null) {
            return null;
        }

        $existing = $this->runs->findStepByActionKey($this->context, $this->runId, $idempotencyKey);
        if ($existing === null) {
            return null;
        }
        if ($existing->operation !== OfficeOperation::Prepare) {
            return $this->toToolResult($this->errorMapper->toResponse(OfficeOperation::Prepare, ErrorCode::IdempotencyConflict));
        }
        if ($existing->state !== 'finished' || $existing->compactResult === null || ($existing->compactResult['ok'] ?? false) !== true) {
            return null;
        }

        $resourceId = $input->resource_id ?? null;
        $command = $input->command ?? null;
        $cmdArgs = $input->arguments ?? null;
        if (! is_string($resourceId) || $resourceId === '' || ! is_string($command) || $command === '') {
            return null;
        }

        $savedResourceId = $existing->resumeInput['resource_id'] ?? $existing->resourceId;
        $savedCommand = $existing->resumeInput['command'] ?? null;
        $savedArgs = $existing->resumeInput['arguments'] ?? null;
        if (is_array($savedArgs)) {
            $savedArgs = json_encode($savedArgs, JSON_THROW_ON_ERROR);
        }
        if (
            ($savedResourceId !== null && $savedResourceId !== $resourceId)
            || ($savedCommand !== null && $savedCommand !== $command)
            || ! $cmdArgs instanceof stdClass
            || (is_string($savedArgs) && $savedArgs !== json_encode($cmdArgs, JSON_THROW_ON_ERROR))
        ) {
            return $this->toToolResult($this->errorMapper->toResponse(OfficeOperation::Prepare, ErrorCode::IdempotencyConflict));
        }

        $entry = $this->directory->findAccessible($this->context, $resourceId, 'edit');
        if ($entry === null || ! $this->policy->allowsCommand($this->context, $entry, $command)) {
            return $this->toToolResult($this->errorMapper->toResponse(OfficeOperation::Prepare, ErrorCode::ResourceUnavailable));
        }

        $rawResult = $existing->compactResult['result'] ?? null;
        if (! is_array($rawResult)) {
            return null;
        }
        $proposalId = $rawResult['proposal_id'] ?? null;
        if (! is_string($proposalId) || $this->proposals->find($this->context, $resourceId, $proposalId) === null) {
            return $this->toToolResult($this->errorMapper->toResponse(OfficeOperation::Prepare, ErrorCode::ResourceUnavailable));
        }

        /** @var array<string, mixed> $rawResult */
        return ToolExecutionResult::success($rawResult);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function handleCommit(array $arguments, string $idempotencyKey): ToolExecutionResult
    {
        if ($this->context === null) {
            return ToolExecutionResult::failure(new ToolError(
                'vaulter.permission.authentication_required',
                ErrorCategory::PERMISSION,
                false,
                'Authentication is required.',
            ));
        }

        $resourceId = $arguments['resource_id'] ?? null;
        $proposalId = $arguments['proposal_id'] ?? null;
        $digest = $arguments['digest'] ?? null;
        if (! is_string($resourceId) || $resourceId === ''
            || ! is_string($proposalId) || $proposalId === ''
            || ! is_string($digest) || $digest === '') {
            return ToolExecutionResult::failure(new ToolError(
                'vaulter.validation.invalid_input',
                ErrorCategory::VALIDATION,
                false,
                'resource_id, proposal_id, and digest are required.',
            ));
        }

        $preferredCommandId = is_string($arguments['command_id'] ?? null) && $arguments['command_id'] !== ''
            ? $arguments['command_id']
            : null;

        $runId = $this->runId;
        if ($runId === null) {
            $profile = $this->policy->profile($this->context);
            $run = $this->runs->createOrFind(
                context: $this->context,
                channel: 'agentools',
                sourceEventId: 'proposal:'.$proposalId,
                profileRevision: $profile->revision,
                inputPayload: ['resource_id' => $resourceId, 'proposal_id' => $proposalId],
            );
            $runId = $run->runId;
        }

        try {
            $this->actionKeyMap->bindForCommit(
                context: $this->context,
                runId: $runId,
                actionKey: $idempotencyKey,
                resourceId: $resourceId,
                proposalId: $proposalId,
                digest: $digest,
                preferredCommandId: $preferredCommandId,
            );
        } catch (OfficeException $e) {
            return $this->toToolResult(
                $this->errorMapper->toResponse(OfficeOperation::Commit, $e->errorCode),
            );
        }

        $outcome = $this->commits->executeOrResume(
            context: $this->context,
            runId: $runId,
            resourceId: $resourceId,
            proposalId: $proposalId,
            digest: $digest,
            preferredCommandId: $preferredCommandId,
            executorActionKey: $idempotencyKey,
        );

        return $this->toToolResult($outcome['response']);
    }

    private function recordStep(
        OfficeOperation $operation,
        stdClass $input,
        OfficeResponse $response,
        string $idempotencyKey,
        int $startedAt,
    ): void {
        if ($this->context === null || $this->runId === null) {
            return;
        }

        if ($this->runs->findStepByActionKey($this->context, $this->runId, $idempotencyKey) !== null) {
            return;
        }

        $resourceId = is_string($input->resource_id ?? null) ? $input->resource_id : null;
        $commandId = is_string($input->command_id ?? null) ? $input->command_id : null;
        $receiptId = is_string($input->receipt_id ?? null) ? $input->receipt_id : null;
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
        } elseif ($operation === OfficeOperation::Verify) {
            if (($response->result->outcome ?? null) === 'passed') {
                $state = 'verified';
            } else {
                $state = 'failed';
                $safeError = 'verification_failed';
            }
        }

        $cmdArgs = $input->arguments ?? null;
        $resumeInput = $operation === OfficeOperation::Prepare
            ? [
                'resource_id' => $resourceId,
                'command' => is_string($input->command ?? null) ? $input->command : null,
                // Keep the JSON shape through the associative decode of encrypted resume input.
                'arguments' => $cmdArgs instanceof stdClass ? json_encode($cmdArgs, JSON_THROW_ON_ERROR) : null,
            ]
            : null;

        $this->runs->recordStep(
            context: $this->context,
            runId: $this->runId,
            operation: $operation,
            state: $state,
            resourceId: $resourceId,
            proposalId: $proposalId,
            commandId: $commandId,
            receiptId: $receiptId,
            executorActionKey: $idempotencyKey,
            resumeInput: $resumeInput,
            compactResult: $response->toArray(),
            safeError: $safeError,
            responseBytes: $response->wireBytes(),
            latencyMs: max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
        );
    }

    private function toToolResult(OfficeResponse $response): ToolExecutionResult
    {
        $decoded = $response->toArray();
        if ($response->ok) {
            $raw = $decoded['result'] ?? [];

            /** @var array<string, mixed> $result */
            $result = is_array($raw) ? $raw : [];

            return ToolExecutionResult::success($result);
        }

        $code = $response->errorCode ?? ErrorCode::TemporarilyUnavailable;
        $retryable = $response->retryable ?? false;

        return ToolExecutionResult::failure($this->mapError($code, $retryable, $response->nextAction?->value));
    }

    private function mapError(ErrorCode $code, bool $retryable, ?string $nextAction): ToolError
    {
        $category = match ($code) {
            ErrorCode::InvalidInput,
            ErrorCode::UnsupportedVersion,
            ErrorCode::UnsupportedContent => ErrorCategory::VALIDATION,
            ErrorCode::AuthenticationRequired,
            ErrorCode::ApprovalRequired => ErrorCategory::PERMISSION,
            ErrorCode::ResourceUnavailable => ErrorCategory::NOT_FOUND,
            ErrorCode::IdempotencyConflict => ErrorCategory::CONFLICT,
            ErrorCode::TemporarilyUnavailable,
            ErrorCode::OperationUnknown,
            ErrorCode::ProjectionPending,
            ErrorCode::CalculationPending => ErrorCategory::UNAVAILABLE,
            ErrorCode::LimitExceeded => ErrorCategory::BUDGET,
            ErrorCode::PreconditionFailed,
            ErrorCode::ScopeChanged,
            ErrorCode::GenerationChanged,
            ErrorCode::SchemaChanged,
            ErrorCode::ProposalExpired,
            ErrorCode::CursorExpired,
            ErrorCode::ReadTokenExpired,
            ErrorCode::EvidenceUnavailable,
            ErrorCode::IdempotencyWindowExpired => ErrorCategory::PRECONDITION,
        };

        return new ToolError(
            code: 'vaulter.'.$category.'.'.$code->value,
            category: $category,
            retryable: $retryable,
            title: 'Office operation failed with '.$code->value.'.',
            context: $nextAction !== null ? ['next_action' => $nextAction] : null,
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
