<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Documents\Contracts\DocumentScopeResolver;
use Vaulter\Configuration\IdsOptions;

/**
 * Default scope mapper aligning Vaulter host_keys strategy with AI Documents partition columns.
 *
 * Maintains optional drive -> project_scope bindings for tests or single-tenant hosts.
 */
final class DefaultDocumentScopeResolver implements DocumentScopeResolver
{
    /** @var array<string, array{tenant_id: int|string, project_scope: string}> */
    private array $driveScopes = [];

    public function __construct(
        private readonly IdsOptions $ids,
        private readonly ConfigRepository $config,
    ) {}

    public function bindDriveScope(string $driveId, int|string $tenantId, string $projectScope): void
    {
        $this->driveScopes[$driveId] = [
            'tenant_id' => $this->normalizeTenantKey((string) $tenantId),
            'project_scope' => $projectScope,
        ];
    }

    /** @return array{tenant_id: int|string, project_scope: string} */
    public function forContext(CallContext $context, ?string $driveId = null): array
    {
        return [
            'tenant_id' => $this->normalizeTenantKey($context->tenantId),
            'project_scope' => $context->projectScope,
        ];
    }

    /** @return array{tenant_id: int|string, project_scope: string} */
    public function forDriveEvent(int|string|null $eventTenantId, string $driveId): array
    {
        if (isset($this->driveScopes[$driveId])) {
            return $this->driveScopes[$driveId];
        }

        $tenantRaw = $eventTenantId !== null
            ? (string) $eventTenantId
            : $this->defaultTenantString();

        return [
            'tenant_id' => $this->normalizeTenantKey($tenantRaw),
            'project_scope' => $this->defaultProjectScope(),
        ];
    }

    private function normalizeTenantKey(string $raw): int|string
    {
        if ($this->ids->hostKeys === 'bigint') {
            if (preg_match('/^[0-9]+$/', $raw) !== 1) {
                throw OfficeException::resourceUnavailable();
            }

            return (int) $raw;
        }

        return $raw;
    }

    private function defaultTenantString(): string
    {
        $configured = $this->config->get('vaulter-ai-documents.default_tenant_id');
        if (is_int($configured) || (is_string($configured) && $configured !== '')) {
            return (string) $configured;
        }

        return $this->ids->hostKeys === 'bigint' ? '0' : 'default';
    }

    private function defaultProjectScope(): string
    {
        $configured = $this->config->get('vaulter-ai-documents.default_project_scope', 'default');

        return is_string($configured) && $configured !== '' ? $configured : 'default';
    }
}
