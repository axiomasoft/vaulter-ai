<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class Status
{
    public function __construct(
        private ResourceDirectory $directory,
        private OwnerRoutes $routes,
        private OfficeRegistry $registry,
        private Clock $clock,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $resourceId = $request->input->resource_id ?? null;
        $commandId = $request->input->command_id ?? null;
        if (! is_string($resourceId) || ! is_string($commandId)) {
            throw OfficeException::invalidInput();
        }
        if ($this->directory->findAccessible($context, $resourceId) === null) {
            throw OfficeException::resourceUnavailable();
        }
        $scope = new CommandScope($context, $resourceId, $commandId);
        $route = $this->routes->find($scope) ?? throw OfficeException::operationUnknown();
        if ($route->dedupeUntil <= $this->clock->now()) {
            throw new OfficeException(ErrorCode::IdempotencyWindowExpired);
        }
        $owner = $this->registry->owner($route->ownerKey) ?? throw OfficeException::temporarilyUnavailable();
        $outcome = $owner->status(new CommandScope($context, $resourceId, $commandId, $route->fingerprint));
        if ($outcome->commandId !== $commandId) {
            throw OfficeException::temporarilyUnavailable();
        }
        if ($outcome->state === 'committed' && $outcome->receiptId !== null && $outcome->receiptId !== $route->receiptId) {
            $this->routes->settle($scope, $outcome->receiptId);
        }

        return CommandResult::response(OfficeOperation::Status, $outcome);
    }
}
