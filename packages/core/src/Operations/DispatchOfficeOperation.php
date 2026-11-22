<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Protocol\WireValidator;

/**
 * Single authoritative dispatcher shared identically by PHP callers, queue jobs, and HTTP controller.
 */
final readonly class DispatchOfficeOperation implements Office
{
    public function __construct(
        private WireValidator $validator,
        private Search $search,
        private Read $read,
        private Describe $describe,
        private WireErrorMapper $errorMapper,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        if ($request->version !== 1) {
            return $this->errorMapper->toResponse($request->operation, ErrorCode::UnsupportedVersion, 1);
        }

        $envelope = (object) [
            'version' => $request->version,
            'operation' => $request->operation->value,
            'input' => $request->input,
        ];
        if (! $this->validator->isValidRequest($envelope)) {
            return $this->errorMapper->toResponse($request->operation, ErrorCode::InvalidInput, 1);
        }

        try {
            return match ($request->operation) {
                OfficeOperation::Search => $this->search->handle($context, $request),
                OfficeOperation::Read => $this->read->handle($context, $request),
                OfficeOperation::Describe => $this->describe->handle($context, $request),
                OfficeOperation::Prepare,
                OfficeOperation::Commit,
                OfficeOperation::Status,
                OfficeOperation::Verify => throw OfficeException::operationUnknown(),
            };
        } catch (OfficeException $e) {
            return $this->errorMapper->toResponse($request->operation, $e->errorCode, 1);
        }
    }
}
