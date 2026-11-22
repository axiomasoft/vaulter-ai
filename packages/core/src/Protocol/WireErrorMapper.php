<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Protocol;

use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Maps internal ErrorCode values to wire-compliant {retryable, next_action} pairs and HTTP status codes.
 */
final readonly class WireErrorMapper
{
    /**
     * @return array{status: int, retryable: bool, next_action: NextAction}
     */
    public function describe(ErrorCode $code, ?OfficeOperation $operation = null): array
    {
        return match ($code) {
            ErrorCode::InvalidInput,
            ErrorCode::UnsupportedVersion => [
                'status' => 400,
                'retryable' => false,
                'next_action' => NextAction::Stop,
            ],
            ErrorCode::AuthenticationRequired => [
                'status' => 401,
                'retryable' => false,
                'next_action' => NextAction::Stop,
            ],
            ErrorCode::ResourceUnavailable => [
                'status' => 404,
                'retryable' => false,
                'next_action' => NextAction::Stop,
            ],
            ErrorCode::OperationUnknown => [
                'status' => 404,
                'retryable' => true,
                'next_action' => NextAction::RetrySame,
            ],
            ErrorCode::PreconditionFailed,
            ErrorCode::ScopeChanged,
            ErrorCode::GenerationChanged,
            ErrorCode::SchemaChanged => [
                'status' => 409,
                'retryable' => false,
                'next_action' => NextAction::Reread,
            ],
            ErrorCode::IdempotencyConflict => [
                'status' => 409,
                'retryable' => false,
                'next_action' => NextAction::Stop,
            ],
            ErrorCode::ApprovalRequired => [
                'status' => 409,
                'retryable' => false,
                'next_action' => NextAction::AwaitApproval,
            ],
            ErrorCode::ProjectionPending,
            ErrorCode::CalculationPending => [
                'status' => 409,
                'retryable' => true,
                'next_action' => NextAction::RetryRead,
            ],
            ErrorCode::ProposalExpired,
            ErrorCode::CursorExpired,
            ErrorCode::ReadTokenExpired => [
                'status' => 410,
                'retryable' => false,
                'next_action' => NextAction::Reread,
            ],
            ErrorCode::EvidenceUnavailable,
            ErrorCode::IdempotencyWindowExpired => [
                'status' => 410,
                'retryable' => false,
                'next_action' => NextAction::InspectHistory,
            ],
            ErrorCode::LimitExceeded => [
                'status' => 413,
                'retryable' => false,
                'next_action' => NextAction::Narrow,
            ],
            ErrorCode::UnsupportedContent => [
                'status' => 422,
                'retryable' => false,
                'next_action' => NextAction::Stop,
            ],
            ErrorCode::TemporarilyUnavailable => [
                'status' => 503,
                'retryable' => true,
                'next_action' => $operation === OfficeOperation::Commit ? NextAction::Status : NextAction::RetryRead,
            ],
        };
    }

    public function toResponse(OfficeOperation $operation, ErrorCode $code, int $version = 1): OfficeResponse
    {
        $spec = $this->describe($code, $operation);

        return OfficeResponse::failure(
            operation: $operation,
            errorCode: $code,
            retryable: $spec['retryable'],
            nextAction: $spec['next_action'],
            version: $version,
        );
    }

    public function httpStatus(OfficeResponse $response): int
    {
        if ($response->ok) {
            return $response->operation === OfficeOperation::Commit
                && $response->result !== null
                && ($response->result->state ?? null) === 'running'
                ? 202
                : 200;
        }

        $code = $response->errorCode ?? ErrorCode::TemporarilyUnavailable;

        return $this->describe($code, $response->operation)['status'];
    }
}
