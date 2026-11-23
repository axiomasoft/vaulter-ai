<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Data;

use JsonSerializable;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Server-only provider credential record with redacted debug/JSON output to prevent accidental leakage.
 */
final readonly class ProviderSecret implements JsonSerializable
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $connection,
        public string $driver,
        private string $apiKey,
        public ?string $model = null,
        public ?string $baseUrl = null,
        public array $headers = [],
    ) {}

    public function apiKey(): string
    {
        if ($this->apiKey === '') {
            throw OfficeException::temporarilyUnavailable();
        }

        return $this->apiKey;
    }

    public function redactedHint(): string
    {
        $len = strlen($this->apiKey);
        if ($len === 0) {
            return 'unconfigured';
        }
        if ($len <= 8) {
            return '****';
        }

        return '****'.substr($this->apiKey, -4);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'connection' => $this->connection,
            'driver' => $this->driver,
            'model' => $this->model,
            'configured' => $this->apiKey !== '',
            'hint' => $this->redactedHint(),
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['apiKey' => '[REDACTED]'];
    }

    public function __toString(): string
    {
        return sprintf('ProviderSecret(connection=%s, driver=%s, configured=%s)', $this->connection, $this->driver, $this->apiKey !== '' ? 'yes' : 'no');
    }
}
