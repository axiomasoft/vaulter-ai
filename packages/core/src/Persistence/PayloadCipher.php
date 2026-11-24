<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/**
 * Authenticated AES-256-GCM encryption for stored read-token dependencies and proposal payloads
 * with AAD binding to table, scope and record identity.
 */
final class PayloadCipher
{
    private const int NONCE_BYTES = 12;

    private const int TAG_BYTES = 16;

    private const string VERSION_PREFIX = "\x01";

    private ?string $cachedRawKey = null;

    /** @var array<string, string> */
    private array $derivedKeyCache = [];

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function keyId(): string
    {
        $configured = $this->config->get('vaulter-ai.encryption.key_id', 'app-key-v1');

        return is_string($configured) && $configured !== '' ? $configured : 'app-key-v1';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $aadParts
     * @return array{key_id: string, ciphertext: string}
     */
    public function encrypt(array $payload, array $aadParts): array
    {
        $keyId = $this->keyId();
        $key = $this->deriveKey($keyId);
        $nonce = random_bytes(self::NONCE_BYTES);
        $aad = json_encode($aadParts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            throw new RuntimeException('Failed to encrypt Office payload.');
        }

        return [
            'key_id' => $keyId,
            'ciphertext' => self::VERSION_PREFIX.$nonce.$tag.$ciphertext,
        ];
    }

    /**
     * @param  list<string>  $aadParts
     * @return array<string, mixed>
     */
    public function decrypt(string $keyId, string $blob, array $aadParts): array
    {
        $minLength = 1 + self::NONCE_BYTES + self::TAG_BYTES + 2;
        if (strlen($blob) < $minLength || $blob[0] !== self::VERSION_PREFIX) {
            throw new RuntimeException('Malformed encrypted Office payload.');
        }

        $nonce = substr($blob, 1, self::NONCE_BYTES);
        $tag = substr($blob, 1 + self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($blob, 1 + self::NONCE_BYTES + self::TAG_BYTES);
        $aad = json_encode($aadParts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->deriveKey($keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Authenticated decryption failed for Office payload.');
        }

        $decoded = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Decrypted Office payload must decode to an associative array.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function signMac(string $message): string
    {
        return hash_hmac('sha256', $message, $this->deriveKey('cursor-mac-v1'), true);
    }

    private function deriveKey(string $purpose): string
    {
        $raw = $this->config->get('app.key');
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('APP_KEY is required for Vaulter AI payload protection.');
        }

        if ($this->cachedRawKey !== $raw) {
            $this->cachedRawKey = $raw;
            $this->derivedKeyCache = [];
        }

        if (isset($this->derivedKeyCache[$purpose])) {
            return $this->derivedKeyCache[$purpose];
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Invalid base64 APP_KEY configured.');
            }
            $masterKey = $decoded;
        } else {
            $masterKey = $raw;
        }

        return $this->derivedKeyCache[$purpose] = hash_hkdf('sha256', $masterKey, 32, 'vaulter-ai:'.$purpose);
    }
}
