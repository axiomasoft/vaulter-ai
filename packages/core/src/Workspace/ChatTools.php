<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use stdClass;
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
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class ChatTools
{
    public function __construct(
        private OfficeDatabase $db,
        private Runs $runs,
        private ChatWorkspace $chat,
        private ToolCallLedger $ledger,
        private ResourceDirectory $resources,
        private HostPolicy $policy,
        private OfficeRegistry $registry,
        private WireValidator $validator,
        private Office $office,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private StepCommitCoordinator $commits,
    ) {}

    /** @param list<string> $scope
     * @return array<string,array<string,mixed>>
     */
    public function catalog(CallContext $context, array $scope): array
    {
        if ($scope === []) {
            return [];
        }
        $readable = $this->resources->filterAccessible($context, $scope);
        $targets = array_values(array_filter($scope, static fn (string $id): bool => isset($readable[$id])));
        if ($targets === []) {
            return [];
        }
        $commands = [];
        foreach ($readable as $entry) {
            $allowed = $this->commands($context, $entry);
            if ($allowed !== []) {
                $commands[$entry->resourceId] = $allowed;
            }
        }

        $tools = [];
        foreach ([OfficeOperation::Read, OfficeOperation::Describe, OfficeOperation::Prepare,
            OfficeOperation::Commit, OfficeOperation::Status, OfficeOperation::Verify] as $operation) {
            $needsCommand = in_array($operation, [OfficeOperation::Describe, OfficeOperation::Prepare, OfficeOperation::Commit], true);
            if ($needsCommand && $commands === []) {
                continue;
            }
            $tools[$operation->toolName()] = [
                'input_schema' => $this->validator->toolInputSchema($operation),
                'resource_ids' => $needsCommand ? array_values(array_intersect($targets, array_keys($commands))) : $targets,
                'commands' => $needsCommand ? $commands : [],
            ];
        }

        return $tools;
    }

    /** @param list<string> $scope */
    public function request(
        CallContext $context, string $sessionId, string $runId, string $runLease,
        int $ordinal, string $tool, string $json, array $scope,
    ): void {
        $operation = OfficeOperation::fromToolName($tool) ?? throw OfficeException::invalidInput();
        $request = $this->ledger->parse($operation, $json);
        $this->authorize($context, $request, $scope);

        $this->db->transaction(function () use ($context, $sessionId, $runId, $runLease, $ordinal, $operation, $json, $scope): void {
            $call = $this->ledger->request($context, $runId, $runLease, $ordinal, $operation, $json);
            if ($call['created']) {
                $this->chat->append($context, $sessionId, $runId, 'tool.requested', [
                    'call_id' => $call['id'], 'tool' => $operation->toolName(),
                ], $scope);
            }
        }, $context->tenantId);
    }

    /**
     * @param  array{id:string, step_id:int, state:string}  $call
     * @param  list<string>  $scope
     * @return array{state:string, message:array<string,mixed>}
     */
    public function deliver(CallContext $context, string $sessionId, string $runId, string $runLease, array $call, array $scope): array
    {
        $step = $this->runs->findStep($context, $runId, $call['step_id']) ?? throw OfficeException::resourceUnavailable();
        $json = $step->resumeInput['tool_input'] ?? null;
        $request = $this->ledger->parse($step->operation, is_string($json) ? $json : '');
        $this->authorize($context, $request, $scope);
        $message = [
            'role' => 'tool',
            'name' => $step->operation->toolName(),
            'call_id' => $call['id'],
            'arguments' => $request->input,
        ];
        if (! in_array($call['state'], ['requested', 'awaiting_approval', 'dispatched'], true)) {
            return ['state' => $call['state'], 'message' => $message + ['response' => $step->compactResult]];
        }

        $lease = $this->ledger->claim($context, $runId, $runLease, $call['id']);
        if ($lease === null) {
            return ['state' => 'dispatched', 'message' => $message];
        }
        if ($step->operation !== OfficeOperation::Commit) {
            $response = $this->db->transaction(function () use ($context, $sessionId, $runId, $runLease, $call, $scope, $step, $request, $lease): OfficeResponse {
                // In particular, prepare's proposal and its ledger link must commit together.
                $response = $this->office->handle($context, $request);
                $proposal = $this->stringField($response, 'proposal_id');
                $receipt = $this->stringField($response, 'receipt_id');
                $state = $response->ok ? 'verified' : 'failed';
                $this->ledger->transition($context, $runId, $runLease, $call['id'], $lease, $state, $proposal,
                    $this->stringField($response, 'command_id'), $receipt);
                $this->runs->updateStep($context, $runId, $step->stepId, $response->ok ? 'finished' : 'failed',
                    receiptId: $receipt, compactResult: $response->toArray(), safeError: $response->errorCode?->value);
                if ($proposal !== null && ($response->result->approval ?? null) === 'human') {
                    $this->chat->append($context, $sessionId, $runId, 'approval.required', ['call_id' => $call['id'], 'proposal_id' => $proposal], $scope);
                }

                return $response;
            }, $context->tenantId);

            return ['state' => $response->ok ? 'verified' : 'failed', 'message' => $message + ['response' => $response->toArray()]];
        }

        $resource = $request->input->resource_id;
        $proposalId = $request->input->proposal_id;
        $digest = $request->input->digest;
        if (! is_string($resource) || ! is_string($proposalId) || ! is_string($digest)) {
            throw OfficeException::invalidInput();
        }
        $proposal = $this->proposals->find($context, $resource, $proposalId) ?? throw OfficeException::resourceUnavailable();
        $command = $this->routes->forProposal($context, $proposalId)->commandId
            ?? 'cmd-chat-'.hash('sha256', 'command:'.$call['id']);
        $this->db->transaction(
            fn () => $this->ledger->transition($context, $runId, $runLease, $call['id'], $lease, 'dispatched', $proposalId, $command, release: false),
            $context->tenantId,
        );
        $response = $this->commits->executeStandaloneCommit($context, $resource, $proposalId, $digest, $command);
        $receipt = $this->stringField($response, 'receipt_id');
        $verification = $receipt === null ? null : $this->office->handle($context, new OfficeRequest(1, OfficeOperation::Verify, (object) [
            'resource_id' => $resource, 'receipt_id' => $receipt, 'mode' => 'at_commit',
        ]));
        $result = $response->toArray();
        if ($verification !== null) {
            $result['verification'] = $verification->toArray();
        }
        $state = match (true) {
            ($verification?->result->outcome ?? null) === 'passed' => 'verified',
            $verification?->retryable === true,
            $response->retryable === true,
            ($response->result->state ?? null) === 'running' => 'dispatched',
            $receipt !== null => 'committed',
            $response->errorCode === ErrorCode::ApprovalRequired => 'awaiting_approval',
            default => 'rejected',
        };
        if ($state === 'awaiting_approval') {
            $result['status'] = 403;
        }
        $this->db->transaction(function () use ($context, $sessionId, $runId, $runLease, $call, $lease, $scope, $step, $state, $proposal, $command, $receipt, $result, $response): void {
            $proposalId = $proposal->plan->proposalId;
            $this->ledger->transition($context, $runId, $runLease, $call['id'], $lease, $state, $proposalId,
                $state === 'awaiting_approval' ? null : $command, $receipt);
            $this->runs->updateStep($context, $runId, $step->stepId, match ($state) {
                'verified' => 'verified',
                'committed' => 'failed',
                'awaiting_approval' => 'waiting',
                'dispatched' => 'dispatched',
                default => 'failed',
            }, receiptId: $receipt, compactResult: $result,
                safeError: $response->errorCode->value ?? ($state === 'committed' ? 'verification_failed' : null),
                clearSafeError: true);
            $details = ['call_id' => $call['id'], 'proposal_id' => $proposalId];
            if ($state === 'awaiting_approval' && $step->safeError !== ErrorCode::ApprovalRequired->value) {
                $this->chat->append($context, $sessionId, $runId, 'approval.required', $details + ['status' => 403], $scope);
            }
            if ($receipt !== null && $state !== 'dispatched') {
                $details += ['command_id' => $command, 'receipt_id' => $receipt];
                $this->chat->append($context, $sessionId, $runId, 'tool.committed', $details, $scope);
                if ($state === 'verified') {
                    $this->chat->append($context, $sessionId, $runId, 'tool.verified', $details + ['verification' => $result['verification']], $scope);
                }
            }
        }, $context->tenantId);

        return ['state' => $state, 'message' => $message + ['response' => $result]];
    }

    /** @param list<string> $scope */
    private function authorize(CallContext $context, OfficeRequest $request, array $scope): void
    {
        $resource = $request->input->resource_id ?? null;
        if ($request->operation === OfficeOperation::Search || ! is_string($resource) || ! in_array($resource, $scope, true)) {
            throw OfficeException::resourceUnavailable();
        }
        $entry = $this->resources->findAccessible($context, $resource) ?? throw OfficeException::resourceUnavailable();
        if (! in_array($request->operation, [OfficeOperation::Describe, OfficeOperation::Prepare, OfficeOperation::Commit], true)) {
            return;
        }
        $commands = $this->commands($context, $entry);
        $command = $request->input->command ?? null;
        if ($commands === [] || (is_string($command) && ! isset($commands[$command]))) {
            throw OfficeException::resourceUnavailable();
        }
        $arguments = $request->input->arguments ?? null;
        if (is_string($command) && $arguments instanceof stdClass && ! $this->validator->isValidToolArguments($arguments, $commands[$command])) {
            throw OfficeException::invalidInput();
        }
    }

    /** @return array<string,stdClass> */
    private function commands(CallContext $context, ResourceEntry $entry): array
    {
        $commands = [];
        foreach ($this->registry->allowedDescriptors($context, $this->policy, $entry) as $descriptor) {
            if ($this->resources->findAccessible($context, $entry->resourceId, $descriptor->requiredPermission) !== null) {
                $commands[$descriptor->command] = $descriptor->inputSchema;
            }
        }

        return $commands;
    }

    private function stringField(OfficeResponse $response, string $field): ?string
    {
        $value = $response->result->{$field} ?? null;

        return is_string($value) ? $value : null;
    }
}
