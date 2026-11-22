<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use stdClass;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\TextVerificationEvidence;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class Verify
{
    public function __construct(
        private ResourceDirectory $directory,
        private OwnerRoutes $routes,
        private OfficeRegistry $registry,
        private WireValidator $validator,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $resourceId = $input->resource_id ?? null;
        $receiptId = $input->receipt_id ?? null;
        $mode = $input->mode ?? null;
        if (! is_string($resourceId) || ! is_string($receiptId) || ! is_string($mode)) {
            throw OfficeException::invalidInput();
        }
        $resource = $this->directory->findAccessible($context, $resourceId) ?? throw OfficeException::resourceUnavailable();

        $route = $this->routes->byReceipt($context, $resourceId, $receiptId) ?? throw OfficeException::resourceUnavailable();
        $owner = $this->registry->owner($route->ownerKey) ?? throw OfficeException::temporarilyUnavailable();
        $outcome = $owner->status(new CommandScope($context, $resourceId, $route->commandId, $route->fingerprint));
        if ($outcome->state !== 'committed' || $outcome->commandId !== $route->commandId
            || $outcome->receiptId !== $receiptId || $outcome->marker === null) {
            throw OfficeException::resourceUnavailable();
        }

        $result = $owner->verify($context, $resourceId, $receiptId, $mode);
        $marker = $result->marker ?? null;
        $checks = $result->checks ?? null;
        $receiptMarker = $outcome->marker;
        $response = OfficeResponse::success(OfficeOperation::Verify, $result);
        if (($result->receipt_id ?? null) !== $receiptId || ($result->mode ?? null) !== $mode
            || ! $marker instanceof stdClass || ($marker->generation ?? null) !== $receiptMarker->generation
            || ! is_int($marker->position ?? null) || $marker->position < $receiptMarker->position
            || ($mode === 'at_commit' && $marker->position !== $receiptMarker->position)
            || ! $this->validator->isValidMessage($response->toStdClass())) {
            throw OfficeException::temporarilyUnavailable();
        }
        if ($resource->resourceType === ResourceType::Text && ($result->outcome ?? null) === 'passed') {
            if (! $owner instanceof TextVerificationEvidence || ! is_array($checks)) {
                throw OfficeException::temporarilyUnavailable();
            }
            $actuals = array_column($checks, 'actual', 'name');
            $expected = $owner->expectedTextChecks($context, $resourceId, $receiptId, $receiptMarker);
            $marks = $actuals['marks'] ?? null;
            if (($actuals['text_exact'] ?? null) !== $expected['text_exact'] || ! is_array($marks)) {
                throw OfficeException::temporarilyUnavailable();
            }
            $markNames = array_filter($marks, is_string(...));
            $expectedMarks = $expected['marks'];
            sort($markNames, SORT_STRING);
            sort($expectedMarks, SORT_STRING);
            if (count($markNames) !== count($marks) || $markNames !== $expectedMarks) {
                throw OfficeException::temporarilyUnavailable();
            }
        }

        return $response;
    }
}
