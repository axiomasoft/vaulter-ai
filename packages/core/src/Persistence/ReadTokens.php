<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Throwable;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Issues and verifies scope-bound encrypted read_token records in v_ai_read_tokens.
 */
final readonly class ReadTokens
{
    public function __construct(
        private OfficeDatabase $db,
        private PayloadCipher $cipher,
        private Clock $clock,
    ) {}

    public function issue(
        CallContext $context,
        string $resourceId,
        ReadPage $page,
        int $ttlSeconds,
    ): string {
        $rawToken = 'rt-'.bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken, true);
        $tokenHashHex = bin2hex($tokenHash);

        $now = $this->clock->now();
        $expiresAt = $now->modify('+'.$ttlSeconds.' seconds');

        $encrypted = $this->cipher->encrypt(
            payload: [
                'selector_kind' => $page->selectorKind,
                'dependencies' => $page->selectorDependencies,
            ],
            aadParts: $this->aad($context, $resourceId, $tokenHashHex),
        );

        $this->db->transaction(function () use (
            $tokenHash,
            $context,
            $resourceId,
            $page,
            $now,
            $expiresAt,
            $encrypted,
        ): void {
            $this->db->query('ai_read_tokens')->insert([
                'token_hash' => $this->db->binaryLiteral($tokenHash),
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'effective_subject' => $context->effectiveSubject,
                'resource_id' => $resourceId,
                'selector_kind' => $page->selectorKind,
                'source_generation' => $page->marker->generation,
                'source_position' => $page->marker->position,
                'schema_revision' => $page->schemaRevision,
                'complete_scope' => $page->completeScope,
                'issued_at' => $this->db->utcTimestamp($now),
                'expires_at' => $this->db->utcTimestamp($expiresAt),
                'key_id' => $encrypted['key_id'],
                'encrypted_selector_dependencies' => $this->db->binaryLiteral($encrypted['ciphertext']),
            ]);
        }, tenantId: $context->tenantId);

        return $rawToken;
    }

    public function verify(CallContext $context, string $rawToken, string $resourceId): ReadTokenRecord
    {
        if ($rawToken === '') {
            throw OfficeException::invalidInput('read_token must be non-empty.');
        }

        $tokenHash = hash('sha256', $rawToken, true);
        $tokenHashHex = bin2hex($tokenHash);

        $row = $this->db->transaction(
            fn (): ?object => $this->db->query('ai_read_tokens')
                ->where('token_hash', $this->db->binaryLiteral($tokenHash))
                ->first([
                    'tenant_id',
                    'project_scope',
                    'effective_subject',
                    'resource_id',
                    'selector_kind',
                    'source_generation',
                    'source_position',
                    'schema_revision',
                    'complete_scope',
                    'issued_at',
                    'expires_at',
                    'key_id',
                    'encrypted_selector_dependencies',
                ]),
            tenantId: $context->tenantId,
        );

        if ($row === null) {
            throw OfficeException::readTokenExpired();
        }

        $storedTenant = QueryRecord::str($row, 'tenant_id');
        $storedProject = QueryRecord::str($row, 'project_scope');
        $storedSubject = QueryRecord::str($row, 'effective_subject');
        $storedResource = QueryRecord::str($row, 'resource_id');

        if (
            ! hash_equals($storedTenant, $context->tenantId)
            || ! hash_equals($storedProject, $context->projectScope)
            || ! hash_equals($storedSubject, $context->effectiveSubject)
            || ! hash_equals($storedResource, $resourceId)
        ) {
            throw OfficeException::resourceUnavailable();
        }

        $issuedAt = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'issued_at'));
        $expiresAt = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'expires_at'));
        if ($issuedAt === null || $expiresAt === null || $expiresAt <= $this->clock->now()) {
            throw OfficeException::readTokenExpired();
        }

        $keyId = QueryRecord::str($row, 'key_id');
        $ciphertext = $this->db->decodeBinary(QueryRecord::raw($row, 'encrypted_selector_dependencies'));
        try {
            $decrypted = $this->cipher->decrypt(
                keyId: $keyId,
                blob: $ciphertext,
                aadParts: $this->aad($context, $resourceId, $tokenHashHex),
            );
        } catch (Throwable) {
            throw OfficeException::readTokenExpired();
        }

        $deps = $decrypted['dependencies'] ?? [];
        if (! is_array($deps)) {
            $deps = [];
        }

        /** @var array<string, mixed> $deps */
        return new ReadTokenRecord(
            tokenHashHex: $tokenHashHex,
            tenantId: $storedTenant,
            projectScope: $storedProject,
            effectiveSubject: $storedSubject,
            resourceId: $storedResource,
            selectorKind: QueryRecord::str($row, 'selector_kind'),
            marker: new Marker(QueryRecord::int($row, 'source_generation'), QueryRecord::int($row, 'source_position')),
            schemaRevision: QueryRecord::str($row, 'schema_revision'),
            completeScope: QueryRecord::bool($row, 'complete_scope'),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            keyId: $keyId,
            dependencies: $deps,
        );
    }

    /** @return list<string> */
    private function aad(CallContext $context, string $resourceId, string $tokenHashHex): array
    {
        return [
            $this->db->table('ai_read_tokens'),
            $context->tenantId,
            $context->projectScope,
            $context->effectiveSubject,
            $resourceId,
            $tokenHashHex,
        ];
    }
}
