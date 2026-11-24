<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Console;

use Illuminate\Console\Command;
use Vaulter\Ai\Core\Persistence\DatabaseProviderSecretStore;

/**
 * Re-encrypts stored per-tenant provider secrets under a target key_version without exposing secrets.
 */
final class RotateProviderSecrets extends Command
{
    protected $signature = 'vaulter-ai:secrets:rotate
        {--tenant= : Optional tenant ID filter}
        {--connection= : Optional connection name filter}
        {--key-version= : Target key version to rotate to}
        {--force : Re-encrypt even if already on target key version}';

    protected $description = 'Rotate encrypted per-tenant AI provider secrets to the active or specified key_version.';

    public function handle(DatabaseProviderSecretStore $store): int
    {
        $rawVersion = $this->option('key-version');
        $targetVersion = null;
        if (is_string($rawVersion) && $rawVersion !== '') {
            if (preg_match('/\A[1-9][0-9]*\z/', $rawVersion) !== 1) {
                $this->error('Option --key-version must be a positive integer.');

                return self::FAILURE;
            }
            $targetVersion = (int) $rawVersion;
        }

        $rawTenant = $this->option('tenant');
        $tenantId = is_string($rawTenant) && trim($rawTenant) !== '' ? trim($rawTenant) : null;

        $rawConnection = $this->option('connection');
        $connection = is_string($rawConnection) && trim($rawConnection) !== '' ? trim($rawConnection) : null;

        $count = $store->rotate(
            targetKeyVersion: $targetVersion,
            tenantId: $tenantId,
            connection: $connection,
            force: $this->option('force') === true,
        );

        $this->info("Rotated {$count} provider secret(s).");

        return self::SUCCESS;
    }
}
