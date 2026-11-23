<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Host;

use Closure;
use stdClass;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentStatePendingException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\InvalidDocumentContentException;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\DriveArchivedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Sheets\Data\WorkbookProblem;

/**
 * Translates Vaulter Sheets domain/runtime exceptions into normalized Office SDK errors
 * and extracts strictly typed structures from untyped WorkbookOperations responses.
 */
final class WorkbookProblemMapper
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function call(Closure $callback, string $unknownTargetCode = 'resource_unavailable'): mixed
    {
        try {
            return $callback();
        } catch (WorkbookProblem $problem) {
            throw self::toOfficeException($problem, $unknownTargetCode);
        } catch (NodeNotFoundException|PermissionDeniedException|ProfileRestrictionException|DriveArchivedException) {
            throw OfficeException::resourceUnavailable();
        } catch (DocumentStatePendingException) {
            throw OfficeException::projectionPending();
        } catch (DocumentContentDegradedException|DocumentTypeUnavailableException|InvalidDocumentContentException) {
            throw OfficeException::unsupportedContent();
        }
    }

    public static function toOfficeException(WorkbookProblem $problem, string $unknownTargetCode = 'resource_unavailable'): OfficeException
    {
        $code = self::mapErrorCode($problem->problem, $unknownTargetCode);

        return new OfficeException($code, $problem->problem, $problem);
    }

    public static function mapErrorCode(string $problemCode, string $unknownTargetCode = 'precondition_failed'): ErrorCode
    {
        return match ($problemCode) {
            'resource_unavailable' => ErrorCode::ResourceUnavailable,
            'unsupported_content' => ErrorCode::UnsupportedContent,
            'invalid_command' => ErrorCode::InvalidInput,
            'scope_changed' => ErrorCode::ScopeChanged,
            'generation_changed' => ErrorCode::GenerationChanged,
            'version_conflict' => ErrorCode::PreconditionFailed,
            'idempotency_conflict' => ErrorCode::IdempotencyConflict,
            'idempotency_window_expired' => ErrorCode::IdempotencyWindowExpired,
            'evidence_unavailable' => ErrorCode::EvidenceUnavailable,
            'limit_exceeded' => ErrorCode::LimitExceeded,
            'calculation_pending' => ErrorCode::CalculationPending,
            'unknown_target' => ErrorCode::tryFrom($unknownTargetCode) ?? ErrorCode::PreconditionFailed,
            default => ErrorCode::TemporarilyUnavailable,
        };
    }

    public static function marker(mixed $raw): Marker
    {
        $gen = is_object($raw) ? ($raw->generation ?? null) : (is_array($raw) ? ($raw['generation'] ?? null) : null);
        $pos = is_object($raw) ? ($raw->position ?? null) : (is_array($raw) ? ($raw['position'] ?? null) : null);
        if (! is_int($gen) || $gen < 0 || ! is_int($pos) || $pos < 0) {
            throw OfficeException::temporarilyUnavailable('Invalid workbook marker.');
        }

        return new Marker($gen, $pos);
    }

    public static function columnLetter(int $column): string
    {
        if ($column < 1) {
            throw OfficeException::invalidInput('Workbook column coordinate must be positive.');
        }
        $letters = '';
        while ($column > 0) {
            $column--;
            $letters = chr(65 + ($column % 26)).$letters;
            $column = intdiv($column, 26);
        }

        return $letters;
    }

    /**
     * Normalize cell scalar values into Office SDK JSON scalars, rejecting unsupported/non-finite types.
     */
    public static function normalizeScalarCell(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value) || is_string($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw OfficeException::unsupportedContent('Non-finite numeric cell value.');
            }
            if (floor($value) === $value && abs($value) <= 9_007_199_254_740_991.0) {
                return (int) $value;
            }

            return $value;
        }

        throw OfficeException::unsupportedContent('Unsupported complex cell value.');
    }

    public static function normalizeNumericValue(mixed $value): int|float|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value)) {
            if (floor($value) === $value && abs($value) <= 9_007_199_254_740_991.0) {
                return (int) $value;
            }

            return $value;
        }

        return null;
    }

    /**
     * Convert an encrypted/decoded JSON associative structure back into tagged stdClass|null cell expectation.
     */
    public static function cellExpectationObject(mixed $cell): ?stdClass
    {
        if ($cell === null) {
            return null;
        }
        if ($cell instanceof stdClass) {
            return $cell;
        }
        if (is_array($cell)) {
            $obj = new stdClass;
            foreach ($cell as $key => $val) {
                if (! is_string($key)) {
                    throw OfficeException::preconditionFailed('Malformed expected cell key.');
                }
                $obj->{$key} = $val;
            }

            return $obj;
        }

        throw OfficeException::preconditionFailed('Malformed expected cell value.');
    }
}
