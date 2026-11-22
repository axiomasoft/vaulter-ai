<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Closure;
use Illuminate\Database\Connection;
use PDO;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use WeakMap;

/**
 * Standalone TransactionRunner matching VaulterDatabase::transaction() guarantees
 * (transaction-local tenant context, SET CONSTRAINTS ALL IMMEDIATE, and #61964 failed-COMMIT cleanup).
 */
final class DefaultTransactionRunner implements TransactionRunner
{
    /** @var WeakMap<Connection, stdClass> */
    private WeakMap $attempts;

    public function __construct()
    {
        /** @var WeakMap<Connection, stdClass> $map */
        $map = new WeakMap;
        $this->attempts = $map;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public function run(Connection $connection, Closure $callback, ?string $tenantId = null, int $attempts = 3): mixed
    {
        $attempt = null;

        try {
            return $connection->transaction(function (Connection $txConnection) use ($callback, $tenantId, &$attempt): mixed {
                if ($attempt !== null) {
                    $attempt->aborted = true;
                }
                if ($txConnection->transactionLevel() === 1) {
                    $attempt = (object) ['aborted' => false];
                    $this->attempts[$txConnection] = $attempt;
                }

                if ($tenantId !== null && $tenantId !== '' && $txConnection->getDriverName() === 'pgsql') {
                    $txConnection->select("SELECT set_config('vaulter.tenant_id', ?, true)", [$tenantId]);
                }

                $result = $callback($txConnection);

                if ($txConnection->transactionLevel() === 1 && $txConnection->getDriverName() === 'pgsql') {
                    $txConnection->statement('SET CONSTRAINTS ALL IMMEDIATE');
                }

                return $result;
            }, $attempts);
        } catch (Throwable $exception) {
            if ($attempt !== null) {
                $attempt->aborted = true;
                if ($connection->transactionLevel() === 0) {
                    $this->cleanFailedCommit($connection);
                }
            }

            throw $exception;
        } finally {
            if ($connection->transactionLevel() === 0) {
                unset($this->attempts[$connection]);
            }
        }
    }

    /**
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Connection $connection, Closure $callback): void
    {
        $attempt = $connection->transactionLevel() > 0 ? ($this->attempts[$connection] ?? null) : null;

        $connection->afterCommit(static function () use ($callback, $attempt): void {
            if ($attempt === null || $attempt->aborted !== true) {
                $callback();
            }
        });
    }

    private function cleanFailedCommit(Connection $connection): void
    {
        try {
            $pdo = $connection->getRawPdo();
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } finally {
            $rollback = Closure::bind(function (): void {
                /** @var Connection $this */
                $this->transactionsManager?->rollback($this->getName() ?? '', 0);
            }, $connection, Connection::class);

            if ($rollback instanceof Closure) {
                $rollback();
            }
        }
    }
}
