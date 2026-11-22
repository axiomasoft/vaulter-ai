<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use InvalidArgumentException;

/**
 * Parameter-safe binary SQL literal expression implementing Laravel's Expression contract.
 */
final readonly class BinaryExpression implements Expression
{
    public function __construct(
        private string $sql,
    ) {}

    public static function forDriver(string $driver, string $rawBytes): self
    {
        $hex = bin2hex($rawBytes);
        if ($hex === '' || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException('Binary SQL literal cannot be empty.');
        }

        return new self(match ($driver) {
            'pgsql' => "decode('{$hex}', 'hex')",
            'sqlite' => "X'{$hex}'",
            'mysql', 'mariadb' => "UNHEX('{$hex}')",
            default => throw new InvalidArgumentException('Unsupported binary literal driver: '.$driver),
        });
    }

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
