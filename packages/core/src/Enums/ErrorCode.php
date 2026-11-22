<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Enums;

enum ErrorCode: string
{
    case InvalidInput = 'invalid_input';
    case UnsupportedVersion = 'unsupported_version';
    case AuthenticationRequired = 'authentication_required';
    case ResourceUnavailable = 'resource_unavailable';
    case IdempotencyConflict = 'idempotency_conflict';
    case UnsupportedContent = 'unsupported_content';
    case OperationUnknown = 'operation_unknown';
    case PreconditionFailed = 'precondition_failed';
    case ScopeChanged = 'scope_changed';
    case GenerationChanged = 'generation_changed';
    case SchemaChanged = 'schema_changed';
    case ProposalExpired = 'proposal_expired';
    case CursorExpired = 'cursor_expired';
    case ReadTokenExpired = 'read_token_expired';
    case ApprovalRequired = 'approval_required';
    case ProjectionPending = 'projection_pending';
    case CalculationPending = 'calculation_pending';
    case EvidenceUnavailable = 'evidence_unavailable';
    case IdempotencyWindowExpired = 'idempotency_window_expired';
    case LimitExceeded = 'limit_exceeded';
    case TemporarilyUnavailable = 'temporarily_unavailable';
}
