<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\ApprovedDispatches;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;

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
            $dispatches->finish($scope, $this->proposalId, $lease, 'delivered', $outcome->receiptId);
        } catch (OfficeException $failure) {
            if (in_array($failure->errorCode, [ErrorCode::TemporarilyUnavailable, ErrorCode::ProjectionPending], true)) {
                if ($dispatches->finish($scope, $this->proposalId, $lease, 'ready')) {
                    $this->release(5);
                }

                return;
            }
            $dispatches->finish($scope, $this->proposalId, $lease, 'exhausted');
        } catch (Throwable $failure) {
            $dispatches->finish($scope, $this->proposalId, $lease, 'ready');

            throw $failure;
        }
    }
}
