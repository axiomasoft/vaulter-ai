<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Closure;
use Illuminate\Database\Connection;

/**
 * Executes database transactions obeying the VaulterDatabase contract:
 * transaction-local PostgreSQL tenant context, pre-commit deferred constraint checks,
 * and failed-COMMIT PDO/transaction-manager cleanup (Laravel #61964).
 */
interface TransactionRunner
{
    /**
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public function run(Connection $connection, Closure $callback, ?string $tenantId = null, int $attempts = 3): mixed;

    /**
     * Register a callback to run only once the outermost transaction commits cleanly.
     *
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Connection $connection, Closure $callback): void;
}
