<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use JsonException;
use Vaulter\Ai\Core\Data\StoredProposal;

/**
 * Reconstructs structured human-review diff payloads from immutable stored proposal preconditions.
 */
final readonly class ProposalDiffBuilder
{
    /**
     * @return array<string, mixed>|null
     */
    public function build(StoredProposal $proposal): ?array
    {
        if (! $proposal->hasPayload) {
            return null;
        }

        $plan = $proposal->plan;
        $pre = $plan->preconditions;

        if ($plan->command === 'text.replace_exact@1' || isset($pre['block_id'], $pre['expected'], $pre['replacement'])) {
            return $this->buildTextDiff($plan->targets, $pre);
        }

        if ($plan->command === 'sheet.fill_formula@1' || isset($pre['sheet_id'], $pre['table_id'])) {
            return $this->buildSheetDiff($plan->targets, $pre);
        }

        return [
            'kind' => 'command',
            'command' => $plan->command,
            'targets' => $plan->targets,
            'summary' => $plan->summary,
        ];
    }

    /**
     * @param  list<string>  $targets
     * @param  array<string, mixed>  $pre
     * @return array<string, mixed>
     */
    private function buildTextDiff(array $targets, array $pre): array
    {
        $blockId = is_string($pre['block_id'] ?? null) ? $pre['block_id'] : ($targets[0] ?? '');
        $expected = is_string($pre['expected'] ?? null) ? $pre['expected'] : '';
        $replacement = is_string($pre['replacement'] ?? null) ? $pre['replacement'] : '';
        $startUtf16 = is_int($pre['start_utf16'] ?? null) ? $pre['start_utf16'] : 0;

        $sectionPath = [];
        if (is_array($pre['section_path'] ?? null)) {
            foreach ($pre['section_path'] as $heading) {
                if (is_string($heading) && $heading !== '') {
                    $sectionPath[] = $heading;
                }
            }
        }

        $beforeSegments = $this->segmentsFromBlockIdentity($pre['block_identity'] ?? null);
        if ($beforeSegments === []) {
            $beforeSegments = $expected !== '' ? [['text' => $expected, 'marks' => []]] : [];
            $afterSegments = $replacement !== '' ? [['text' => $replacement, 'marks' => []]] : [];
        } else {
            $afterSegments = $this->applyReplacementToSegments($beforeSegments, $startUtf16, $expected, $replacement);
        }

        return [
            'kind' => 'text_replace',
            'block_id' => $blockId,
            'section_path' => $sectionPath,
            'expected' => $expected,
            'replacement' => $replacement,
            'start_utf16' => $startUtf16,
            'before_segments' => $beforeSegments,
            'after_segments' => $afterSegments,
        ];
    }

    /**
     * @param  list<string>  $targets
     * @param  array<string, mixed>  $pre
     * @return array<string, mixed>
     */
    private function buildSheetDiff(array $targets, array $pre): array
    {
        $sheetId = is_string($pre['sheet_id'] ?? null) ? $pre['sheet_id'] : '';
        $tableId = is_string($pre['table_id'] ?? null) ? $pre['table_id'] : '';
        $scope = is_string($pre['scope'] ?? null) ? $pre['scope'] : 'fixed_members';
        $destColId = is_string($pre['destination_column_id'] ?? null) ? $pre['destination_column_id'] : '';
        $destLetter = is_string($pre['destination_column_letter'] ?? null) ? $pre['destination_column_letter'] : '';
        $leftLetter = is_string($pre['left_column_letter'] ?? null) ? $pre['left_column_letter'] : '';
        $rightLetter = is_string($pre['right_column_letter'] ?? null) ? $pre['right_column_letter'] : '';

        $rowIds = [];
        $rawRowIds = is_array($pre['row_ids'] ?? null) ? $pre['row_ids'] : $targets;
        foreach ($rawRowIds as $rowId) {
            if (is_string($rowId) && $rowId !== '') {
                $rowIds[] = $rowId;
            }
        }

        $inputs = [];
        if (is_array($pre['expected_inputs'] ?? null)) {
            foreach ($rowIds as $rowId) {
                $rowInput = $pre['expected_inputs'][$rowId] ?? null;
                if (is_array($rowInput)) {
                    $leftNum = is_array($rowInput['left'] ?? null) ? ($rowInput['left']['number'] ?? null) : null;
                    $rightNum = is_array($rowInput['right'] ?? null) ? ($rowInput['right']['number'] ?? null) : null;
                    $inputs[] = [
                        'row_id' => $rowId,
                        'left' => is_int($leftNum) || is_float($leftNum) ? $leftNum : null,
                        'right' => is_int($rightNum) || is_float($rightNum) ? $rightNum : null,
                    ];
                }
            }
        }

        $template = $leftLetter !== '' && $rightLetter !== ''
            ? "={$leftLetter}{row}-{$rightLetter}{row}"
            : 'subtract';

        return [
            'kind' => 'sheet_fill_formula',
            'sheet_id' => $sheetId,
            'table_id' => $tableId,
            'scope' => $scope,
            'row_ids' => $rowIds,
            'destination_column_id' => $destColId,
            'destination_column_letter' => $destLetter,
            'left_column_letter' => $leftLetter,
            'right_column_letter' => $rightLetter,
            'formula_template' => $template,
            'inputs' => $inputs,
        ];
    }

    /**
     * @return list<array{text: string, marks: list<array{type: string, attrs?: array<string, mixed>}>}>
     */
    private function segmentsFromBlockIdentity(mixed $identity): array
    {
        if (! is_string($identity) || $identity === '') {
            return [];
        }

        try {
            $node = json_decode($identity, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($node) || ! is_array($node['content'] ?? null)) {
            return [];
        }

        $segments = [];
        foreach ($node['content'] as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) === 'text' && is_string($child['text'] ?? null) && $child['text'] !== '') {
                $marks = $this->parseMarks($child['marks'] ?? null);
                $segments[] = [
                    'text' => $child['text'],
                    'marks' => $marks,
                ];
            } else {
                $segments[] = [
                    'text' => "\u{FFFC}",
                    'marks' => [],
                ];
            }
        }

        return $segments;
    }

    /**
     * @return list<array{type: string, attrs?: array<string, mixed>}>
     */
    private function parseMarks(mixed $rawMarks): array
    {
        $items = [];
        if (is_array($rawMarks)) {
            $items = $rawMarks;
        } elseif (is_string($rawMarks) && $rawMarks !== '') {
            foreach (explode("\n", $rawMarks) as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $items[] = $decoded;
                    }
                } catch (JsonException) {
                    continue;
                }
            }
        }

        $marks = [];
        foreach ($items as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null) || $mark['type'] === '') {
                continue;
            }
            $entry = ['type' => $mark['type']];
            if (is_array($mark['attrs'] ?? null)) {
                $attrs = array_filter($mark['attrs'], is_string(...), ARRAY_FILTER_USE_KEY);
                if ($attrs !== []) {
                    $entry['attrs'] = $attrs;
                }
            }
            $marks[] = $entry;
        }

        return $marks;
    }

    /**
     * @param  list<array{text: string, marks: list<array{type: string, attrs?: array<string, mixed>}>}>  $segments
     * @return list<array{text: string, marks: list<array{type: string, attrs?: array<string, mixed>}>}>
     */
    private function applyReplacementToSegments(array $segments, int $startUtf16, string $expected, string $replacement): array
    {
        $expectedUtf16Len = $this->utf16Length($expected);
        $endUtf16 = $startUtf16 + $expectedUtf16Len;
        $cursor = 0;
        $after = [];

        foreach ($segments as $segment) {
            $segText = $segment['text'];
            $segLen = $this->utf16Length($segText);
            $segEnd = $cursor + $segLen;

            if ($segEnd <= $startUtf16 || $cursor >= $endUtf16) {
                $after[] = $segment;
                $cursor = $segEnd;

                continue;
            }

            $localStart = max(0, $startUtf16 - $cursor);
            $localEnd = min($segLen, $endUtf16 - $cursor);
            $prefix = $this->utf16Slice($segText, 0, $localStart);
            $suffix = $this->utf16Slice($segText, $localEnd, $segLen - $localEnd);
            $replaced = $prefix.($cursor <= $startUtf16 ? $replacement : '').$suffix;
            if ($replaced !== '') {
                $after[] = [
                    'text' => $replaced,
                    'marks' => $segment['marks'],
                ];
            }

            $cursor = $segEnd;
        }

        return $after;
    }

    private function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function utf16Slice(string $text, int $startUnits, int $lengthUnits): string
    {
        if ($lengthUnits <= 0) {
            return '';
        }

        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $slice = substr($utf16, $startUnits * 2, $lengthUnits * 2);

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE');
    }
}
