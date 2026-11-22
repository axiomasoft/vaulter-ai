<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Protocol;

use InvalidArgumentException;
use stdClass;

/**
 * Office v1 RFC 8785 JCS canonicalizer and proposal digest calculator.
 *
 * Input objects MUST be decoded via json_decode(..., false) so JSON objects remain stdClass
 * (preserving {} and {"0":...}) and JSON arrays remain PHP lists.
 */
final class ProposalDigest
{
    public const int MAX_SAFE_INTEGER = 9007199254740991;

    public static function record(stdClass $prepareInput, stdClass $prepareResult): stdClass
    {
        $plan = clone $prepareResult;
        unset($plan->digest);

        return (object) [
            'version' => 1,
            'prepare_input' => $prepareInput,
            'plan' => $plan,
        ];
    }

    public static function digest(stdClass $prepareInput, stdClass $prepareResult): string
    {
        return hash('sha256', self::canonicalize(self::record($prepareInput, $prepareResult)));
    }

    public static function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => self::integer($value),
            is_float($value) => self::integralFloat($value),
            is_string($value) => self::string($value),
            is_array($value) => self::listValue($value),
            $value instanceof stdClass => self::objectValue($value),
            default => throw new InvalidArgumentException('JCS: unsupported value '.get_debug_type($value)),
        };
    }

    private static function integer(int $value): string
    {
        if ($value > self::MAX_SAFE_INTEGER || $value < -self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("JCS profile: integer outside safe range: {$value}");
        }

        return (string) $value;
    }

    private static function integralFloat(float $value): string
    {
        if (! is_finite($value) || floor($value) !== $value || abs($value) > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException('JCS profile: non-integer or unsafe number');
        }

        return self::integer((int) $value);
    }

    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('JCS: string is not valid UTF-8');
        }

        $short = [
            "\x08" => '\b',
            "\t" => '\t',
            "\n" => '\n',
            "\x0c" => '\f',
            "\r" => '\r',
            '"' => '\"',
            '\\' => '\\\\',
        ];

        $escaped = preg_replace_callback(
            '/[\x00-\x1f"\\\\]/',
            static fn (array $m): string => $short[$m[0]] ?? sprintf('\u%04x', ord($m[0])),
            $value,
        );

        if ($escaped === null) {
            throw new InvalidArgumentException('JCS: string escaping failed');
        }

        return '"'.$escaped.'"';
    }

    /** @param array<mixed> $value */
    private static function listValue(array $value): string
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException('JCS: PHP associative array passed; decode JSON objects as stdClass');
        }

        return '['.implode(',', array_map(self::canonicalize(...), $value)).']';
    }

    private static function objectValue(stdClass $value): string
    {
        $members = [];
        foreach (get_object_vars($value) as $key => $item) {
            $members[(string) $key] = $item;
        }

        $keys = array_map('strval', array_keys($members));
        usort($keys, static fn (string $a, string $b): int => strcmp(
            mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'),
        ));

        return '{'.implode(',', array_map(
            static fn (string $k): string => self::string($k).':'.self::canonicalize($members[$k]),
            $keys,
        )).'}';
    }
}
