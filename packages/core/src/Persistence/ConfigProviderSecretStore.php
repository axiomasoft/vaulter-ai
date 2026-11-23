<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Encryption\StringEncrypter;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Data\ProviderSecret;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Config and cipher-backed server secret store resolving provider credentials without
 * leaking keys across public envelopes, logs, events, or serialized job payloads.
 */
final readonly class ConfigProviderSecretStore implements ProviderSecretStore
{
    public function __construct(
        private ConfigRepository $config,
        private PayloadCipher $cipher,
        private ?Encrypter $encrypter = null,
    ) {}

    public function resolve(string $connection): ProviderSecret
    {
        $connection = trim($connection);
        if ($connection === '') {
            throw OfficeException::temporarilyUnavailable();
        }

        $record = $this->lookupRecord($connection);
        if ($record === null) {
            throw OfficeException::temporarilyUnavailable();
        }

        $apiKey = $this->extractApiKey($record, $connection);
        if ($apiKey === '') {
            throw OfficeException::temporarilyUnavailable();
        }

        $driver = is_string($record['driver'] ?? null) && $record['driver'] !== ''
            ? $record['driver']
            : 'openai';
        $model = is_string($record['model'] ?? null) && $record['model'] !== ''
            ? $record['model']
            : $this->defaultModel();
        $baseUrl = is_string($record['url'] ?? null) && $record['url'] !== ''
            ? $record['url']
            : (is_string($record['base_url'] ?? null) && $record['base_url'] !== '' ? $record['base_url'] : null);

        $headers = [];
        if (is_array($record['headers'] ?? null)) {
            foreach ($record['headers'] as $name => $val) {
                if (is_string($name) && $name !== '' && is_string($val)) {
                    $headers[$name] = $val;
                }
            }
        }

        return new ProviderSecret(
            connection: $connection,
            driver: $driver,
            apiKey: $apiKey,
            model: $model,
            baseUrl: $baseUrl,
            headers: $headers,
        );
    }

    /** @return array{configured:bool, connection:string, driver:?string, model:?string, hint:?string} */
    public function status(?string $connection): array
    {
        $conn = is_string($connection) ? trim($connection) : '';
        if ($conn === '') {
            return [
                'configured' => false,
                'connection' => '',
                'driver' => null,
                'model' => null,
                'hint' => null,
            ];
        }

        $record = $this->lookupRecord($conn);
        if ($record === null) {
            return [
                'configured' => false,
                'connection' => $conn,
                'driver' => null,
                'model' => null,
                'hint' => null,
            ];
        }

        $driver = is_string($record['driver'] ?? null) && $record['driver'] !== '' ? $record['driver'] : 'openai';
        $model = is_string($record['model'] ?? null) && $record['model'] !== '' ? $record['model'] : $this->defaultModel();

        try {
            $key = $this->extractApiKey($record, $conn);
        } catch (OfficeException) {
            $key = '';
        }

        $secret = $key !== '' ? new ProviderSecret($conn, $driver, $key, $model) : null;

        return [
            'configured' => $secret !== null,
            'connection' => $conn,
            'driver' => $driver,
            'model' => $model,
            'hint' => $secret?->redactedHint(),
        ];
    }

    public function maskText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        foreach ($this->knownSecrets() as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '[REDACTED]', $text);
            }
        }

        return $text;
    }

    /** @return array<string, mixed>|null */
    private function lookupRecord(string $connection): ?array
    {
        $stored = $this->config->get('vaulter-ai.secret_store.connections.'.$connection);
        if (is_array($stored)) {
            $typed = $this->stringKeyed($stored);
            $providerRef = $typed['provider'] ?? null;
            if (is_string($providerRef) && $providerRef !== '') {
                $providerConfig = $this->config->get('ai.providers.'.$providerRef);
                if (is_array($providerConfig)) {
                    $typed = $this->stringKeyed($providerConfig) + $typed;
                }
            }

            return $typed;
        }

        $mappedProvider = $this->config->get('vaulter-ai-laravel-ai.provider_connections.'.$connection);
        $providerKey = is_string($mappedProvider) && $mappedProvider !== ''
            ? $mappedProvider
            : $connection;

        $aiProvider = $this->config->get('ai.providers.'.$providerKey);
        if (is_array($aiProvider)) {
            return $this->stringKeyed($aiProvider);
        }

        if ($connection === 'office_model') {
            $defaultProvider = $this->config->get('ai.default');
            if (is_string($defaultProvider) && $defaultProvider !== '') {
                $defaultConfig = $this->config->get('ai.providers.'.$defaultProvider);
                if (is_array($defaultConfig)) {
                    return $this->stringKeyed($defaultConfig);
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function extractApiKey(array $record, string $connection): string
    {
        $rawKey = $record['key'] ?? ($record['api_key'] ?? null);
        if (is_string($rawKey) && trim($rawKey) !== '') {
            return trim($rawKey);
        }

        $encrypted = $record['encrypted_key'] ?? null;
        if (is_string($encrypted) && $encrypted !== '' && $this->encrypter !== null) {
            try {
                if ($this->encrypter instanceof StringEncrypter) {
                    return trim($this->encrypter->decryptString($encrypted));
                }
                $plain = $this->encrypter->decrypt($encrypted, false);

                return is_string($plain) ? trim($plain) : '';
            } catch (DecryptException) {
                throw OfficeException::temporarilyUnavailable();
            }
        }
        if (is_array($encrypted) && is_string($encrypted['key_id'] ?? null) && is_string($encrypted['ciphertext'] ?? null)) {
            try {
                $ciphertext = base64_decode($encrypted['ciphertext'], true);
                $decoded = $this->cipher->decrypt(
                    $encrypted['key_id'],
                    $ciphertext === false ? '' : $ciphertext,
                    ['secret_store', $connection],
                );
                $plain = $decoded['key'] ?? null;

                return is_string($plain) ? trim($plain) : '';
            } catch (RuntimeException) {
                throw OfficeException::temporarilyUnavailable();
            }
        }

        return '';
    }

    private function defaultModel(): ?string
    {
        foreach (['vaulter-ai.secret_store.default_model', 'vaulter-ai-laravel-ai.default_model'] as $path) {
            $val = $this->config->get($path);
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function knownSecrets(): array
    {
        $secrets = [];

        $storedConnections = $this->config->get('vaulter-ai.secret_store.connections', []);
        if (is_array($storedConnections)) {
            foreach ($storedConnections as $conn => $record) {
                if (is_string($conn) && is_array($record)) {
                    try {
                        $key = $this->extractApiKey($this->stringKeyed($record), $conn);
                        if ($key !== '') {
                            $secrets[] = $key;
                        }
                    } catch (OfficeException) {
                        // Ignore unconfigured/undecryptable record while masking.
                    }
                }
            }
        }

        $aiProviders = $this->config->get('ai.providers', []);
        if (is_array($aiProviders)) {
            foreach ($aiProviders as $record) {
                if (is_array($record) && is_string($record['key'] ?? null) && trim($record['key']) !== '') {
                    $secrets[] = trim($record['key']);
                }
            }
        }

        return array_values(array_unique($secrets));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
