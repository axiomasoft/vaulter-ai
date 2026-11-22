<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use JsonException;
use stdClass;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Validated outbound Office SDK v1 envelope (success or typed error).
 */
final readonly class OfficeResponse
{
    public const int WIRE_JSON_OPTIONS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private function __construct(
        public int $version,
        public OfficeOperation $operation,
        public bool $ok,
        public ?stdClass $result,
        public ?ErrorCode $errorCode,
        public ?bool $retryable,
        public ?NextAction $nextAction,
    ) {}

    public static function success(OfficeOperation $operation, stdClass $result, int $version = 1): self
    {
        return new self(
            version: $version,
            operation: $operation,
            ok: true,
            result: $result,
            errorCode: null,
            retryable: null,
            nextAction: null,
        );
    }

    public static function failure(
        OfficeOperation $operation,
        ErrorCode $errorCode,
        bool $retryable,
        NextAction $nextAction,
        int $version = 1,
    ): self {
        return new self(
            version: $version,
            operation: $operation,
            ok: false,
            result: null,
            errorCode: $errorCode,
            retryable: $retryable,
            nextAction: $nextAction,
        );
    }

    public function toStdClass(): stdClass
    {
        if ($this->ok) {
            return (object) [
                'version' => $this->version,
                'operation' => $this->operation->value,
                'ok' => true,
                'result' => $this->result ?? new stdClass,
            ];
        }

        return (object) [
            'version' => $this->version,
            'operation' => $this->operation->value,
            'ok' => false,
            'error' => (object) [
                'code' => ($this->errorCode ?? ErrorCode::TemporarilyUnavailable)->value,
                'retryable' => $this->retryable ?? false,
                'next_action' => ($this->nextAction ?? NextAction::Stop)->value,
            ],
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->toStdClass(), self::WIRE_JSON_OPTIONS);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to serialize OfficeResponse.', 0, $e);
        }
    }

    public function wireBytes(): int
    {
        return strlen($this->toJson());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($this->toJson(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to serialize OfficeResponse.', 0, $e);
        }

        return $decoded;
    }
}
