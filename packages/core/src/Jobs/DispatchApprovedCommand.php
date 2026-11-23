<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Jobs;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\CommandResult;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\ApprovedDispatches;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;

final class DispatchApprovedCommand implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'office-agent';

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public readonly string $tenantId, public readonly string $proposalId) {}

    public function handle(
        ApprovedDispatches $dispatches,
        OwnerRoutes $routes,
        Proposals $proposals,
        Commit $commit,
        ?ApprovedCommandContextResolver $resolver = null,
        ?Runs $runs = null,
        ?Office $office = null,
        ?OfficeDatabase $db = null,
        ?Dispatcher $bus = null,
        ?ChannelChatBridge $channelBridge = null,
    ): void {
        $context = $resolver?->resolve($this->tenantId, $this->proposalId);
        if ($context === null || $context->tenantId !== $this->tenantId) {
            return;
        }
        $route = $routes->forProposal($context, $this->proposalId);
        if ($route === null) {
            return;
        }
        $scope = new CommandScope($context, $route->resourceId, $route->commandId);
        $proposal = $proposals->find($context, $scope->resourceId, $route->proposalId);
        if ($proposal === null) {
            return;
        }
        $lease = $dispatches->claim($scope, $this->proposalId);
        if ($lease === null) {
            return;
        }

        try {
            $commit->authorize($scope, $proposal);
            $outcome = $commit->execute($scope, $proposal, $route, $route->proposalId, $proposal->plan->digest);
            if ($outcome->state === 'running') {
                if ($dispatches->finish($scope, $this->proposalId, $lease, 'ready')) {
                    $this->release(5);
                }

                return;
            }
            if ($outcome->state === 'committed' && $outcome->receiptId !== null && $route->receiptId === null) {
                $routes->settle($scope, $outcome->receiptId);
            }
            $settled = $dispatches->finish($scope, $this->proposalId, $lease, 'delivered', $outcome->receiptId);
            if ($settled && $runs !== null) {
                $response = CommandResult::response(OfficeOperation::Commit, $outcome);
                if ($outcome->state === 'committed' && $outcome->receiptId !== null) {
                    $runs->syncProposalOutcome(
                        context: $context,
                        proposalId: $this->proposalId,
                        stepState: 'finished',
                        receiptId: $outcome->receiptId,
                        compactResult: $response->toArray(),
                    );
                    $commitStep = $runs->findLatestStepForProposalInScope($context, $this->proposalId, OfficeOperation::Commit);
                    $linkedRun = $commitStep !== null ? $runs->findInScope($context, $commitStep->runId) : null;
                    if ($office !== null && $linkedRun !== null && ! in_array($linkedRun->channel, ['agentools', 'chat'], true)) {
                        $startedAt = hrtime(true);
                        $verifyResponse = $office->handle($context, new OfficeRequest(1, OfficeOperation::Verify, (object) [
                            'resource_id' => $scope->resourceId,
                            'receipt_id' => $outcome->receiptId,
                            'mode' => 'at_commit',
                        ]));
                        $passed = $verifyResponse->ok
                            && $verifyResponse->result instanceof stdClass
                            && ($verifyResponse->result->outcome ?? null) === 'passed';
                        $runs->recordVerifyForProposalRuns(
                            context: $context,
                            proposalId: $this->proposalId,
                            resourceId: $scope->resourceId,
                            receiptId: $outcome->receiptId,
                            passed: $passed,
                            compactResult: $verifyResponse->toArray(),
                            safeError: $passed ? null : ($verifyResponse->errorCode !== null ? $verifyResponse->errorCode->value : 'verification_failed'),
                            responseBytes: $verifyResponse->wireBytes(),
                            latencyMs: max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
                        );
                    }
                } elseif ($outcome->state === 'rejected') {
                    $runs->syncProposalOutcome(
                        context: $context,
                        proposalId: $this->proposalId,
                        stepState: 'failed',
                        safeError: $outcome->reason !== null ? $outcome->reason->value : ErrorCode::PreconditionFailed->value,
                        compactResult: $response->toArray(),
                    );
                }
            }
            if ($settled) {
                $this->dispatchLinkedChatRuns($context, $db, $bus);
                $this->syncExternalLinkedChats($context, $runs, $db, $channelBridge);
            }
        } catch (OfficeException $failure) {
            if (in_array($failure->errorCode, [ErrorCode::TemporarilyUnavailable, ErrorCode::ProjectionPending], true)) {
                if ($dispatches->finish($scope, $this->proposalId, $lease, 'ready')) {
                    $this->release(5);
                }

                return;
            }
            $finished = $dispatches->finish($scope, $this->proposalId, $lease, 'exhausted');
            if ($finished && $runs !== null) {
                $runs->syncProposalOutcome(
                    context: $context,
                    proposalId: $this->proposalId,
                    stepState: 'failed',
                    safeError: $failure->errorCode->value,
                );
            }
            if ($finished) {
                $this->dispatchLinkedChatRuns($context, $db, $bus);
                $this->syncExternalLinkedChats($context, $runs, $db, $channelBridge);
            }
        } catch (Throwable $failure) {
            $dispatches->finish($scope, $this->proposalId, $lease, 'ready');

            throw $failure;
        }
    }

    private function dispatchLinkedChatRuns(CallContext $context, ?OfficeDatabase $db, ?Dispatcher $bus): void
    {
        if ($db === null || $bus === null) {
            return;
        }

        $chatRunIds = $db->transaction(function () use ($db, $context): array {
            $callsTable = $db->table('ai_tool_calls');
            $runsTable = $db->table('ai_runs');
            $rows = $db->connection()->table($callsTable)
                ->join($runsTable, $runsTable.'.run_id', '=', $callsTable.'.run_id')
                ->where($callsTable.'.proposal_id', $this->proposalId)
                ->where($runsTable.'.tenant_id', $context->tenantId)
                ->where($runsTable.'.project_scope', $context->projectScope)
                ->where($runsTable.'.channel', 'chat')
                ->whereNotIn($runsTable.'.state', ['completed', 'rejected'])
                ->distinct()
                ->get([$callsTable.'.run_id']);

            $ids = [];
            foreach ($rows as $row) {
                $rid = QueryRecord::str($row, 'run_id');
                if ($rid !== '') {
                    $ids[] = $rid;
                }
            }

            return $ids;
        }, $context->tenantId);

        foreach ($chatRunIds as $chatRunId) {
            $bus->dispatch(new ExecuteChatRun($context->tenantId, $chatRunId));
        }
    }

    private function syncExternalLinkedChats(
        CallContext $context,
        ?Runs $runs,
        ?OfficeDatabase $db,
        ?ChannelChatBridge $channelBridge,
    ): void {
        if ($runs === null || $db === null || $channelBridge === null) {
            return;
        }
        $step = $runs->findLatestStepForProposalInScope($context, $this->proposalId, OfficeOperation::Commit)
            ?? $runs->findLatestStepForProposalInScope($context, $this->proposalId, OfficeOperation::Prepare);
        if ($step === null) {
            return;
        }
        $run = $runs->findInScope($context, $step->runId);
        if ($run === null || $run->channel === 'chat') {
            return;
        }

        $msgRow = $db->query('ai_chat_messages')
            ->where('run_id', $run->runId)
            ->where('role', 'user')
            ->first();
        if ($msgRow === null) {
            return;
        }

        $sessionId = QueryRecord::str($msgRow, 'session_id');
        $rawScope = json_decode(QueryRecord::str($msgRow, 'resource_scope_json'), true);
        if (! is_array($rawScope)) {
            return;
        }
        /** @var list<string> $scope */
        $scope = array_values(array_filter($rawScope, is_string(...)));
        $ownerRunContext = new CallContext(
            tenantId: $run->tenantId,
            projectScope: $run->projectScope,
            effectiveSubject: $run->effectiveSubject,
            profile: $context->profile,
            policyRevision: $run->profileRevision,
            driveId: $context->driveId,
            actor: $context->actor,
        );

        $channelBridge->syncExternalRunEvents($ownerRunContext, $sessionId, $run->runId, $scope);
    }
}
