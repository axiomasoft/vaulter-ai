<?php

declare(strict_types=1);

namespace Vaulter\Ai\Sheets\Host;

use stdClass;

/**
 * Extracts strictly typed sheet maps, cell grids, and ordered table bindings
 * from Vaulter Sheets workbook snapshots without dynamic property access.
 */
final class WorkbookSnapshot
{
    public static function sheetObject(mixed $workbook, string $sheetId): ?stdClass
    {
        if (! $workbook instanceof stdClass) {
            return null;
        }
        $native = $workbook->native ?? null;
        if (! $native instanceof stdClass) {
            return null;
        }
        $sheets = $native->sheets ?? null;
        if (! $sheets instanceof stdClass) {
            return null;
        }
        $sheet = get_object_vars($sheets)[$sheetId] ?? null;

        return $sheet instanceof stdClass ? $sheet : null;
    }

    public static function sheetCellData(mixed $workbook, string $sheetId): stdClass
    {
        $sheet = self::sheetObject($workbook, $sheetId);
        $cellData = $sheet !== null ? ($sheet->cellData ?? null) : null;

        return $cellData instanceof stdClass ? $cellData : new stdClass;
    }

    public static function rawCell(stdClass $cellData, int $row, int $column): ?stdClass
    {
        $rowObj = get_object_vars($cellData)[(string) ($row - 1)] ?? null;
        if (! $rowObj instanceof stdClass) {
            return null;
        }
        $cell = get_object_vars($rowObj)[(string) ($column - 1)] ?? null;

        return $cell instanceof stdClass ? $cell : null;
    }

    /** @return array<string, int> */
    public static function columnMap(stdClass $table): array
    {
        $map = [];
        foreach (is_array($table->columns ?? null) ? $table->columns : [] as $col) {
            if ($col instanceof stdClass && is_string($col->column_id ?? null) && $col->column_id !== ''
                && is_int($col->column ?? null) && $col->column >= 1) {
                $map[$col->column_id] = $col->column;
            }
        }

        return $map;
    }

    /** @return list<stdClass> */
    public static function orderedRowBindings(stdClass $table): array
    {
        $rows = [];
        foreach (is_array($table->rows ?? null) ? $table->rows : [] as $row) {
            if ($row instanceof stdClass && is_string($row->row_id ?? null) && $row->row_id !== ''
                && is_int($row->row ?? null) && $row->row >= 1) {
                $rows[] = $row;
            }
        }

        usort($rows, static function (stdClass $a, stdClass $b): int {
            $posA = is_int($a->row ?? null) ? $a->row : 0;
            $posB = is_int($b->row ?? null) ? $b->row : 0;

            return $posA <=> $posB;
        });

        return $rows;
    }

    /** @return list<string> */
    public static function orderedRowIds(stdClass $table): array
    {
        $ids = [];
        foreach (self::orderedRowBindings($table) as $row) {
            if (is_string($row->row_id ?? null) && $row->row_id !== '') {
                $ids[] = $row->row_id;
            }
        }

        return $ids;
    }

    /** @return array<string, int> */
    public static function rowMap(stdClass $table): array
    {
        $map = [];
        foreach (self::orderedRowBindings($table) as $row) {
            if (is_string($row->row_id ?? null) && is_int($row->row ?? null)) {
                $map[$row->row_id] = $row->row;
            }
        }

        return $map;
    }

    /** @return array<int, array<int, stdClass>> */
    public static function indexCellGrid(mixed $ranges): array
    {
        $grid = [];
        if (! is_array($ranges)) {
            return $grid;
        }
        foreach ($ranges as $range) {
            if (! $range instanceof stdClass || ! is_array($range->cells ?? null)) {
                continue;
            }
            foreach ($range->cells as $cell) {
                if ($cell instanceof stdClass && is_int($cell->row ?? null) && is_int($cell->column ?? null)) {
                    $grid[$cell->row][$cell->column] = $cell;
                }
            }
        }

        return $grid;
    }
}
