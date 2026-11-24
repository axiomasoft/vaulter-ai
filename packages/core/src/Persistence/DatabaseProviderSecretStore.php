<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Data\ProviderSecret;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Multi-tenant AEAD-encrypted BYOK provider secret store with key_version rotation
 * and fallback to ConfigProviderSecretStore when no tenant DB record exists.
 */
final class DatabaseProviderSecretStore implements ProviderSecretStore
{
    private const int NONCE_BYTES = 12;

    private const int TAG_BYTES = 16;

    private const string VERSION_PREFIX = "\x01";

    /** @var array<string, string> */
    private array $derivedKeyCache = [];

    public function __construct(
        private readonly OfficeDatabase $db,
        private readonly ConfigRepository $config,
        private readonly Clock $clock,
        private readonly ConfigProviderSecretStore $fallback,
    ) {}

    public function resolve(string $connection, ?string $tenantId = null): ProviderSecret
    {
        $conn = trim($connection);
        if ($conn === '') {
            throw OfficeException::temporarilyUnavailable();
        }

        $tenant = $this->effectiveTenantId($tenantId);
        if ($tenant !== null) {
            $row = $this->findRow($tenant, $conn);
            if ($row !== null) {
                $keyVersion = QueryRecord::int($row, 'key_version');
                $blob = $this->db->decodeBinary(QueryRecord::raw($row, 'encrypted_secret'));

                try {
                    $decoded = $this->decryptPayload($keyVersion, $blob, $tenant, $conn);
                } catch (RuntimeException) {
                    throw OfficeException::temporarilyUnavailable();
                }

                $apiKey = is_string($decoded['api_key'] ?? null) ? trim($decoded['api_key']) : '';
                if ($apiKey === '') {
                    throw OfficeException::temporarilyUnavailable();
                }

                $headers = $this->normalizeHeaders($decoded['headers'] ?? []);
                $model = QueryRecord::raw($row, 'model');
                $baseUrl = QueryRecord::raw($row, 'base_url');

                return new ProviderSecret(
                    connection: $conn,
                    driver: QueryRecord::str($row, 'driver'),
                    apiKey: $apiKey,
                    model: is_string($model) && $model !== '' ? $model : $this->defaultModel(),
                    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
                    headers: $headers,
                );
            }
        }

        return $this->fallback->resolve($conn);
    }

    /** @return array{configured:bool, connection:string, driver:?string, model:?string, hint:?string} */
    public function status(?string $connection, ?string $tenantId = null): array
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

        $tenant = $this->effectiveTenantId($tenantId);
        if ($tenant !== null) {
            $row = $this->findRow($tenant, $conn);
            if ($row !== null) {
                $model = QueryRecord::raw($row, 'model');

                return [
                    'configured' => true,
                    'connection' => $conn,
                    'driver' => QueryRecord::str($row, 'driver'),
                    'model' => is_string($model) && $model !== '' ? $model : $this->defaultModel(),
                    'hint' => QueryRecord::str($row, 'hint'),
                ];
            }
        }

        return $this->fallback->status($conn);
    }

    /**
     * Store or update an encrypted BYOK provider secret for a tenant.
     *
     * @param  array<string, string>  $headers
     * @return array{secret_id:string, tenant_id:string, connection:string, driver:string, model:?string, base_url:?string, key_version:int, key_id:string, hint:string}
     */
    public function put(
        string $tenantId,
        string $connection,
        string $driver,
        string $apiKey,
        ?string $model = null,
        ?string $baseUrl = null,
        array $headers = [],
        ?int $keyVersion = null,
    ): array {
        $tenant = trim($tenantId);
        $conn = trim($connection);
        $drv = trim($driver);
        $key = trim($apiKey);

        if ($tenant === '' || strlen($tenant) > 255 || $conn === '' || strlen($conn) > 128 || $drv === '' || $key === '') {
            throw new InvalidArgumentException('Tenant, connection, driver, and API key must be non-empty.');
        }

        $version = $keyVersion ?? $this->activeKeyVersion();
        if ($version < 1) {
            throw new InvalidArgumentException('Key version must be >= 1.');
        }

        $normalizedModel = is_string($model) && trim($model) !== '' ? trim($model) : null;
        $normalizedBaseUrl = is_string($baseUrl) && trim($baseUrl) !== '' ? trim($baseUrl) : null;
        $normalizedHeaders = $this->normalizeHeaders($headers);
        $hint = (new ProviderSecret($conn, $drv, $key, $normalizedModel, $normalizedBaseUrl, $normalizedHeaders))->redactedHint();
        $keyId = 'kms-v'.$version;
        $ciphertext = $this->encryptPayload($version, ['api_key' => $key, 'headers' => $normalizedHeaders], $tenant, $conn);

        return $this->db->transaction(function () use (
            $tenant,
            $conn,
            $drv,
            $normalizedModel,
            $normalizedBaseUrl,
            $hint,
            $version,
            $keyId,
            $ciphertext,
        ): array {
            if ($this->db->driver() === 'pgsql') {
                $this->db->connection()->selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    ['vaulter-ai:provider-secret:'.$tenant.':'.$conn],
                );
            }

            $now = $this->db->utcTimestamp($this->db->clockNow($this->clock));
            $query = $this->db->query('ai_provider_secrets')
                ->where('tenant_id', $tenant)
                ->where('connection', $conn);
            if ($this->db->driver() === 'pgsql') {
                $query->lockForUpdate();
            }
            $existing = $query->first(['secret_id']);

            if ($existing !== null) {
                $secretId = QueryRecord::str($existing, 'secret_id');
                $this->db->query('ai_provider_secrets')
                    ->where('secret_id', $secretId)
                    ->update([
                        'driver' => $drv,
                        'model' => $normalizedModel,
                        'base_url' => $normalizedBaseUrl,
                        'hint' => $hint,
                        'key_version' => $version,
                        'key_id' => $keyId,
                        'encrypted_secret' => $this->db->binaryLiteral($ciphertext),
                        'updated_at' => $now,
                    ]);
            } else {
                $secretId = $this->db->newUuid();
                $this->db->query('ai_provider_secrets')->insert([
                    'secret_id' => $secretId,
                    'tenant_id' => $tenant,
                    'connection' => $conn,
                    'driver' => $drv,
                    'model' => $normalizedModel,
                    'base_url' => $normalizedBaseUrl,
                    'hint' => $hint,
                    'key_version' => $version,
                    'key_id' => $keyId,
                    'encrypted_secret' => $this->db->binaryLiteral($ciphertext),
                    'rotated_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'secret_id' => $secretId,
                'tenant_id' => $tenant,
                'connection' => $conn,
                'driver' => $drv,
                'model' => $normalizedModel,
                'base_url' => $normalizedBaseUrl,
                'key_version' => $version,
                'key_id' => $keyId,
                'hint' => $hint,
            ];
        }, $tenant);
    }

    public function delete(string $tenantId, string $connection): bool
    {
        $tenant = trim($tenantId);
        $conn = trim($connection);
        if ($tenant === '' || $conn === '') {
            return false;
        }

        return $this->db->transaction(
            fn (): bool => $this->db->query('ai_provider_secrets')
                ->where('tenant_id', $tenant)
                ->where('connection', $conn)
                ->delete() > 0,
            $tenant,
        );
    }

    /**
     * Inspect redacted metadata for a tenant's stored secret without exposing plaintext credentials.
     *
     * @return array{secret_id:string, tenant_id:string, connection:string, driver:string, model:?string, base_url:?string, key_version:int, key_id:string, hint:string, rotated_at:?string, created_at:string, updated_at:string}|null
     */
    public function inspect(string $tenantId, string $connection): ?array
    {
        $row = $this->findRow(trim($tenantId), trim($connection));
        if ($row === null) {
            return null;
        }

        $model = QueryRecord::raw($row, 'model');
        $baseUrl = QueryRecord::raw($row, 'base_url');
        $rotatedAt = QueryRecord::raw($row, 'rotated_at');

        return [
            'secret_id' => QueryRecord::str($row, 'secret_id'),
            'tenant_id' => QueryRecord::str($row, 'tenant_id'),
            'connection' => QueryRecord::str($row, 'connection'),
            'driver' => QueryRecord::str($row, 'driver'),
            'model' => is_string($model) && $model !== '' ? $model : null,
            'base_url' => is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
            'key_version' => QueryRecord::int($row, 'key_version'),
            'key_id' => QueryRecord::str($row, 'key_id'),
            'hint' => QueryRecord::str($row, 'hint'),
            'rotated_at' => is_string($rotatedAt) && $rotatedAt !== '' ? $rotatedAt : null,
            'created_at' => QueryRecord::str($row, 'created_at'),
            'updated_at' => QueryRecord::str($row, 'updated_at'),
        ];
    }

    /**
     * Re-encrypt stored secrets under $targetKeyVersion (defaulting to active configured key_version).
     */
    public function rotate(
        ?int $targetKeyVersion = null,
        ?string $tenantId = null,
        ?string $connection = null,
        bool $force = false,
    ): int {
        $targetVersion = $targetKeyVersion ?? $this->activeKeyVersion();
        if ($targetVersion < 1) {
            throw new InvalidArgumentException('Target key version must be >= 1.');
        }

        $tenant = is_string($tenantId) && trim($tenantId) !== '' ? trim($tenantId) : null;
        $conn = is_string($connection) && trim($connection) !== '' ? trim($connection) : null;

        return $this->db->transaction(function () use ($targetVersion, $tenant, $conn, $force): int {
            $query = $this->db->query('ai_provider_secrets');
            if ($tenant !== null) {
                $query->where('tenant_id', $tenant);
            }
            if ($conn !== null) {
                $query->where('connection', $conn);
            }
            if (! $force) {
                $query->where('key_version', '!=', $targetVersion);
            }
            if ($this->db->driver() === 'pgsql') {
                $query->lockForUpdate();
            }

            $rows = $query->get(['secret_id', 'tenant_id', 'connection', 'key_version', 'encrypted_secret']);
            $now = $this->db->utcTimestamp($this->db->clockNow($this->clock));
            $rotated = 0;

            foreach ($rows as $row) {
                $secretId = QueryRecord::str($row, 'secret_id');
                $rowTenant = QueryRecord::str($row, 'tenant_id');
                $rowConn = QueryRecord::str($row, 'connection');
                $oldVersion = QueryRecord::int($row, 'key_version');
                $blob = $this->db->decodeBinary(QueryRecord::raw($row, 'encrypted_secret'));

                $payload = $this->decryptPayload($oldVersion, $blob, $rowTenant, $rowConn);
                $reEncrypted = $this->encryptPayload($targetVersion, $payload, $rowTenant, $rowConn);

                $this->db->query('ai_provider_secrets')
                    ->where('secret_id', $secretId)
                    ->update([
                        'key_version' => $targetVersion,
                        'key_id' => 'kms-v'.$targetVersion,
                        'encrypted_secret' => $this->db->binaryLiteral($reEncrypted),
                        'rotated_at' => $now,
                        'updated_at' => $now,
                    ]);
                $rotated++;
            }

            return $rotated;
        }, $tenant);
    }

    public function maskText(string $text, ?string $tenantId = null): string
    {
        if ($text === '') {
            return '';
        }

        $masked = $this->fallback->maskText($text);
        $tenant = $this->effectiveTenantId($tenantId);
        if ($tenant === null) {
            return $masked;
        }

        $rows = $this->db->query('ai_provider_secrets')
            ->where('tenant_id', $tenant)
            ->get(['connection', 'key_version', 'encrypted_secret']);

        foreach ($rows as $row) {
            $conn = QueryRecord::str($row, 'connection');
            $version = QueryRecord::int($row, 'key_version');
            $blob = $this->db->decodeBinary(QueryRecord::raw($row, 'encrypted_secret'));

            try {
                $decoded = $this->decryptPayload($version, $blob, $tenant, $conn);
            } catch (RuntimeException) {
                continue;
            }

            $apiKey = is_string($decoded['api_key'] ?? null) ? trim($decoded['api_key']) : '';
            if (strlen($apiKey) >= 6) {
                $masked = str_replace($apiKey, '[REDACTED]', $masked);
            }
        }

        return $masked;
    }

    private function findRow(string $tenantId, string $connection): ?object
    {
        if ($tenantId === '' || $connection === '') {
            return null;
        }

        return $this->db->query('ai_provider_secrets')
            ->where('tenant_id', $tenantId)
            ->where('connection', $connection)
            ->first();
    }

    private function effectiveTenantId(?string $explicit): ?string
    {
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $fromContext = Context::get('vaulter.ai.tenant_id');
        if (is_string($fromContext) && trim($fromContext) !== '') {
            return trim($fromContext);
        }

        return null;
    }

    private function activeKeyVersion(): int
    {
        $configured = $this->config->get('vaulter-ai.secret_store.key_version', 1);

        return is_int($configured) && $configured >= 1 ? $configured : 1;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encryptPayload(int $keyVersion, array $payload, string $tenantId, string $connection): string
    {
        $key = $this->deriveVersionKey($keyVersion);
        $nonce = random_bytes(self::NONCE_BYTES);
        $aad = $this->aad($tenantId, $connection, $keyVersion);
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Failed to encrypt provider secret.');
        }

        return self::VERSION_PREFIX.$nonce.$tag.$ciphertext;
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptPayload(int $keyVersion, string $blob, string $tenantId, string $connection): array
    {
        $minLength = 1 + self::NONCE_BYTES + self::TAG_BYTES + 2;
        if (strlen($blob) < $minLength || $blob[0] !== self::VERSION_PREFIX) {
            throw new RuntimeException('Malformed encrypted provider secret.');
        }

        $nonce = substr($blob, 1, self::NONCE_BYTES);
        $tag = substr($blob, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($blob, 1 + self::NONCE_BYTES + self::TAG_BYTES);
        $aad = $this->aad($tenantId, $connection, $keyVersion);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->deriveVersionKey($keyVersion),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Authenticated decryption failed for provider secret.');
        }

        try {
            $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Malformed decrypted provider secret.', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Decrypted provider secret must decode to an associative array.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function aad(string $tenantId, string $connection, int $keyVersion): string
    {
        return json_encode(
            [$this->db->table('ai_provider_secrets'), $tenantId, $connection, (string) $keyVersion],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function deriveVersionKey(int $keyVersion): string
    {
        $keys = $this->config->get('vaulter-ai.secret_store.keys', []);
        $configured = null;
        if (is_array($keys)) {
            $candidate = $keys[$keyVersion] ?? ($keys[(string) $keyVersion] ?? null);
            if (is_string($candidate) && $candidate !== '') {
                $configured = $candidate;
            }
        }

        $raw = $configured ?? $this->config->get('app.key');
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Master key is required for provider secret encryption.');
        }

        $cacheKey = $keyVersion."\0".hash('sha256', $raw);
        if (isset($this->derivedKeyCache[$cacheKey])) {
            return $this->derivedKeyCache[$cacheKey];
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Invalid base64 master key configured.');
            }
            $masterKey = $decoded;
        } else {
            $masterKey = $raw;
        }

        return $this->derivedKeyCache[$cacheKey] = hash_hkdf(
            'sha256',
            $masterKey,
            32,
            'vaulter-ai:provider-secret:v'.$keyVersion,
        );
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $headers = [];
        foreach ($raw as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
