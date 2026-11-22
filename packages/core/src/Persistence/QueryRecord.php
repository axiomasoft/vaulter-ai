<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use RuntimeException;

/**
 * Type-safe extractors for stdClass database rows returned by Query Builder.
 */
final class QueryRecord
{
    public static function raw(object $row, string $field): mixed
    {
        $vars = get_object_vars($row);

        return $vars[$field] ?? null;
    }

    public static function str(object $row, string $field, string $default = ''): string
    {
        $val = self::raw($row, $field);
        if (is_string($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return $default;
    }

    public static function int(object $row, string $field): int
    {
        $val = self::raw($row, $field);
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && is_numeric($val)) {
            return (int) $val;
        }

        throw new RuntimeException("Expected integer column [{$field}].");
    }

    public static function bool(object $row, string $field): bool
    {
        $val = self::raw($row, $field);

        return match (true) {
            is_bool($val) => $val,
            is_int($val) => $val !== 0,
            is_string($val) => in_array(strtolower($val), ['1', 't', 'true'], true),
            default => false,
        };
    }
}
