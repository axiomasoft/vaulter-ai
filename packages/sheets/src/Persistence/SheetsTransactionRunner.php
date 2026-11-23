<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Persistence;

use Closure;
use Illuminate\Database\Connection;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Persistence\DefaultTransactionRunner;
use Vaulter\Database\VaulterDatabase;

/**
 * Routes Office AI database transactions through VaulterDatabase::transaction() when targeting
 * the Vaulter connection on standalone vaulter-ai-sheets installations.
 */
final readonly class SheetsTransactionRunner implements TransactionRunner
{
    public function __construct(
        private VaulterDatabase $vaulterDb,
        private DefaultTransactionRunner $fallback,
    ) {}

    /**
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public function run(Connection $connection, Closure $callback, ?string $tenantId = null, int $attempts = 3): mixed
    {
        if ($connection !== $this->vaulterDb->connection()) {
            return $this->fallback->run($connection, $callback, $tenantId, $attempts);
        }

        return $this->vaulterDb->transaction(function (Connection $txConnection) use ($callback, $tenantId): mixed {
            if ($tenantId !== null && $tenantId !== '' && $txConnection->getDriverName() === 'pgsql') {
                $txConnection->select("SELECT set_config('vaulter.tenant_id', ?, true)", [$tenantId]);
            }

            return $callback($txConnection);
        }, $attempts);
    }

    /**
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Connection $connection, Closure $callback): void
    {
        if ($connection !== $this->vaulterDb->connection()) {
            $this->fallback->afterCommit($connection, $callback);

            return;
        }

        $this->vaulterDb->afterCommit($callback);
    }
}
