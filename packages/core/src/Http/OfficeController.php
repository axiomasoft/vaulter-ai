<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Protocol\WireValidator;

/**
 * Single HTTP endpoint for Office SDK v1 operations, delegating identically to the PHP Office port.
 * Resolves CallContextResolver and Office per invocation so Octane/cached routes never hold stale bindings.
 */
final readonly class OfficeController
{
    public function __construct(
        private WireErrorMapper $errorMapper,
    ) {}

    public function __invoke(Request $request, Container $container, WireValidator $validator): JsonResponse
    {
        $body = stream_get_contents($request->getContent(asResource: true), WireValidator::MAX_HTTP_BODY_BYTES + 1);
        if ($body === false) {
            throw new RuntimeException('Unable to read the Office request body.');
        }
        $parsed = $validator->parseHttpPayload($body);

        if (! $parsed['ok']) {
            if ($parsed['kind'] === 'too_large') {
                return new JsonResponse(['error' => 'request_too_large'], 413, options: OfficeResponse::WIRE_JSON_OPTIONS);
            }
            if ($parsed['kind'] === 'invalid_request') {
                return new JsonResponse(['error' => 'invalid_request'], 400, options: OfficeResponse::WIRE_JSON_OPTIONS);
            }

            $response = $this->errorMapper->toResponse(
                operation: $parsed['operation'],
                code: $parsed['error_code'],
                version: 1,
            );

            return JsonResponse::fromJsonString(
                data: $response->toJson(),
                status: $this->errorMapper->httpStatus($response),
            );
        }

        $officeRequest = $parsed['request'];
        $resolver = $container->make(CallContextResolver::class);
        $context = $resolver->resolve($request);
        if ($context === null) {
            $response = $this->errorMapper->toResponse(
                operation: $officeRequest->operation,
                code: ErrorCode::AuthenticationRequired,
                version: 1,
            );

            return JsonResponse::fromJsonString(
                data: $response->toJson(),
                status: $this->errorMapper->httpStatus($response),
            );
        }

        $office = $container->make(Office::class);
        $response = $office->handle($context, $officeRequest);
        $status = $this->errorMapper->httpStatus($response);
        if ($officeRequest->operation === OfficeOperation::Commit && $response->errorCode === ErrorCode::ApprovalRequired
            && ($context->attributes['office_subagent'] ?? null) === true) {
            $status = 403;
        }

        return JsonResponse::fromJsonString(
            data: $response->toJson(),
            status: $status,
        );
    }
}
