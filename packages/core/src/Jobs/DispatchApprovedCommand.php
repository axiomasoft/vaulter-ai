<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\CommandResult;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\ApprovedDispatches;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\Runs;

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
                    if ($office !== null && $linkedRun !== null && $linkedRun->channel !== 'agentools') {
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
        } catch (Throwable $failure) {
            $dispatches->finish($scope, $this->proposalId, $lease, 'ready');

            throw $failure;
        }
    }
}
