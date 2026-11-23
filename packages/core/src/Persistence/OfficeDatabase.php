<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\TransactionRunner;

/**
 * Resolves the configured AI Core database connection, table names, binary literals, and transactions.
 */
final readonly class OfficeDatabase
{
    private const string PREFIX_PATTERN = '/\A[a-z][a-z0-9_]{0,20}_\z/';

    public function __construct(
        private DatabaseManager $db,
        private ConfigRepository $config,
        private ?TransactionRunner $transactions = null,
    ) {}

    public function connection(): Connection
    {
        $configured = $this->config->get('vaulter-ai.database.connection');
        $name = is_string($configured) && $configured !== '' ? $configured : null;

        return $this->db->connection($name);
    }

    public function driver(): string
    {
        return $this->connection()->getDriverName();
    }

    public function table(string $logical): string
    {
        $raw = $this->config->get('vaulter-ai.database.table_prefix', 'v_');
        $prefix = is_string($raw) && preg_match(self::PREFIX_PATTERN, $raw) === 1 ? $raw : 'v_';

        return $prefix.$logical;
    }

    public function query(string $logical): Builder
    {
        return $this->connection()->table($this->table($logical));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  int<1, max>  $attempts
     * @return TReturn
     */
    public function transaction(Closure $callback, ?string $tenantId = null, int $attempts = 3): mixed
    {
        return $this->runner()->run(
            connection: $this->connection(),
            callback: static fn (Connection $_connection): mixed => $callback(),
            tenantId: $tenantId,
            attempts: $attempts,
        );
    }

    /**
     * @param  Closure(): void  $callback
     */
    public function afterCommit(Closure $callback): void
    {
        $this->runner()->afterCommit($this->connection(), $callback);
    }

    private function runner(): TransactionRunner
    {
        return $this->transactions ?? new DefaultTransactionRunner;
    }

    public function newUuid(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Lossless binary literal expression compatible with PostgreSQL bytea, SQLite BLOB, and MySQL.
     */
    public function binaryLiteral(string $rawBytes): Expression
    {
        return BinaryExpression::forDriver($this->driver(), $rawBytes);
    }

    public function decodeBinary(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return is_string($contents) ? $contents : '';
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Format a UTC instant unambiguously for PostgreSQL timestamptz (or driver-local text for SQLite/MySQL).
     */
    public function utcTimestamp(DateTimeImmutable $instant, ?string $driver = null): string
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));
        $targetDriver = $driver ?? $this->driver();

        return $targetDriver === 'pgsql'
            ? $utc->format('Y-m-d H:i:sP')
            : $utc->format('Y-m-d H:i:s');
    }

    public function parseUtcTimestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Authoritative UTC instant for expiry checks after acquiring row locks.
     * Uses PostgreSQL clock_timestamp() when running on SystemClock + pgsql so frozen
     * transaction timestamps cannot mask expiry during lock contention.
     */
    public function clockNow(Clock $clock): DateTimeImmutable
    {
        if ($clock instanceof SystemClock && $this->driver() === 'pgsql') {
            $row = $this->connection()->selectOne(
                'SELECT to_char(clock_timestamp() AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"\') AS clock_now',
                useReadPdo: false,
            );
            $raw = is_object($row) ? ($row->clock_now ?? null) : null;
            if (is_string($raw) && $raw !== '') {
                $parsed = $this->parseUtcTimestamp($raw);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return $clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
