<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Exceptions;

use RuntimeException;
use Throwable;
use Vaulter\Ai\Core\Enums\ErrorCode;

final class OfficeException extends RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value, 0, $previous);
    }

    public static function invalidInput(string $message = 'Invalid operation input.'): self
    {
        return new self(ErrorCode::InvalidInput, $message);
    }

    public static function unsupportedVersion(string $message = 'Unsupported protocol version.'): self
    {
        return new self(ErrorCode::UnsupportedVersion, $message);
    }

    public static function authenticationRequired(string $message = 'Authenticated host context is required.'): self
    {
        return new self(ErrorCode::AuthenticationRequired, $message);
    }

    public static function resourceUnavailable(): self
    {
        return new self(ErrorCode::ResourceUnavailable, 'Resource is unavailable.');
    }

    public static function operationUnknown(): self
    {
        return new self(ErrorCode::OperationUnknown, 'Operation is unavailable.');
    }

    public static function preconditionFailed(string $message = 'Precondition failed.'): self
    {
        return new self(ErrorCode::PreconditionFailed, $message);
    }

    public static function scopeChanged(string $message = 'Scope changed.'): self
    {
        return new self(ErrorCode::ScopeChanged, $message);
    }

    public static function generationChanged(string $message = 'Document generation changed.'): self
    {
        return new self(ErrorCode::GenerationChanged, $message);
    }

    public static function schemaChanged(string $message = 'Schema revision changed.'): self
    {
        return new self(ErrorCode::SchemaChanged, $message);
    }

    public static function cursorExpired(string $message = 'Cursor expired or invalid.'): self
    {
        return new self(ErrorCode::CursorExpired, $message);
    }

    public static function readTokenExpired(string $message = 'Read token expired.'): self
    {
        return new self(ErrorCode::ReadTokenExpired, $message);
    }

    public static function projectionPending(string $message = 'Projection is catching up.'): self
    {
        return new self(ErrorCode::ProjectionPending, $message);
    }

    public static function limitExceeded(string $message = 'Response exceeds configured limit; narrow the selector.'): self
    {
        return new self(ErrorCode::LimitExceeded, $message);
    }

    public static function unsupportedContent(string $message = 'Unsupported content structure.'): self
    {
        return new self(ErrorCode::UnsupportedContent, $message);
    }

    public static function temporarilyUnavailable(string $message = 'Service temporarily unavailable.'): self
    {
        return new self(ErrorCode::TemporarilyUnavailable, $message);
    }
}
