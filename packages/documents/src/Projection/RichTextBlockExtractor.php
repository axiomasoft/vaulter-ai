<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

use JsonException;
use Vaulter\Ai\Core\Exceptions\OfficeException;

/**
 * Normalizes TipTap rich-text ASTs and extracts addressable paragraph/heading blocks with
 * Fragment.fromArray mark-run merging, U+FFFC inline leaf mapping, heading paths, and duplicate checks.
 */
final readonly class RichTextBlockExtractor
{
    public const string LEAF_TOKEN = "\u{FFFC}";

    /** @var list<string> */
    private const array ADDRESSABLE_KINDS = ['paragraph', 'heading'];

    /**
     * @param  array<string, mixed>  $doc
     * @return list<ExtractedTextBlock>
     */
    public function extract(array $doc): array
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw OfficeException::unsupportedContent('Root document node must have type doc.');
        }

        /** @var array<int, string> $headingStack */
        $headingStack = [];
        /** @var array<string, true> $seenBlockIds */
        $seenBlockIds = [];
        /** @var list<ExtractedTextBlock> $blocks */
        $blocks = [];

        $children = $doc['content'] ?? [];
        if (is_array($children)) {
            $this->walkChildren($children, null, $headingStack, $seenBlockIds, $blocks);
        }

        return $blocks;
    }

    /**
     * Compute wire segments [{text, marks: string[]}] from a stored normalized block node.
     *
     * @param  array<string, mixed>  $formattedNode
     * @return list<array{text: string, marks: list<string>}>
     */
    public static function wireSegmentsFromFormattedNode(array $formattedNode): array
    {
        $content = $formattedNode['content'] ?? [];
        if (! is_array($content) || $content === []) {
            return [];
        }

        $segments = [];
        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }
            $type = $child['type'] ?? null;
            if ($type === 'text') {
                $text = $child['text'] ?? '';
                if (! is_string($text) || $text === '') {
                    continue;
                }
                $marks = [];
                if (isset($child['marks']) && is_array($child['marks'])) {
                    foreach ($child['marks'] as $mark) {
                        if (is_array($mark) && is_string($mark['type'] ?? null) && $mark['type'] !== '') {
                            $marks[] = $mark['type'];
                        }
                    }
                }
                $segments[] = [
                    'text' => $text,
                    'marks' => array_values(array_unique($marks)),
                ];
            } else {
                $segments[] = [
                    'text' => self::LEAF_TOKEN,
                    'marks' => [],
                ];
            }
        }

        return $segments;
    }

    /**
     * Merge adjacent text nodes carrying identical marks+attrs, drop empty text nodes, and preserve inline leaves.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function normalizeBlock(array $node): array
    {
        $rawContent = $node['content'] ?? null;
        if (! is_array($rawContent)) {
            return $node;
        }

        $normalized = [];
        foreach ($rawContent as $child) {
            if (! is_array($child)) {
                continue;
            }

            /** @var array<string, mixed> $child */
            $type = $child['type'] ?? null;
            if ($type === 'text') {
                $text = $child['text'] ?? '';
                if (! is_string($text) || $text === '') {
                    continue;
                }
                $marks = isset($child['marks']) && is_array($child['marks']) ? $child['marks'] : [];
                if ($marks === []) {
                    unset($child['marks']);
                }

                $lastIdx = array_key_last($normalized);
                if (
                    $lastIdx !== null
                    && ($normalized[$lastIdx]['type'] ?? null) === 'text'
                    && self::markSignature(is_array($normalized[$lastIdx]['marks'] ?? null) ? $normalized[$lastIdx]['marks'] : [])
                    === self::markSignature($marks)
                ) {
                    $prevText = is_string($normalized[$lastIdx]['text'] ?? null) ? $normalized[$lastIdx]['text'] : '';
                    $normalized[$lastIdx]['text'] = $prevText.$text;

                    continue;
                }
            }

            $normalized[] = $child;
        }

        if ($normalized === []) {
            unset($node['content']);
        } else {
            $node['content'] = $normalized;
        }

        return $node;
    }

    /**
     * @param  array<mixed>  $children
     * @param  array<int, string>  $headingStack
     * @param  array<string, true>  $seenBlockIds
     * @param  list<ExtractedTextBlock>  $blocks
     */
    private function walkChildren(
        array $children,
        ?string $parentId,
        array &$headingStack,
        array &$seenBlockIds,
        array &$blocks,
    ): void {
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $type = $child['type'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            /** @var array<string, mixed> $child */
            $attrs = isset($child['attrs']) && is_array($child['attrs']) ? $child['attrs'] : [];
            $rawBlockId = $attrs['blockId'] ?? null;

            if (in_array($type, self::ADDRESSABLE_KINDS, true)) {
                if (! is_string($rawBlockId) || $rawBlockId === '') {
                    // Non-addressable legacy block without blockId: still update heading stack if heading.
                    if ($type === 'heading') {
                        $normalized = self::normalizeBlock($child);
                        $this->pushHeading($headingStack, $attrs['level'] ?? 1, $this->plainText($normalized));
                    }

                    continue;
                }

                if (isset($seenBlockIds[$rawBlockId])) {
                    throw OfficeException::unsupportedContent("Duplicate blockId [{$rawBlockId}] in document.");
                }
                $seenBlockIds[$rawBlockId] = true;

                $normalized = self::normalizeBlock($child);
                $plainText = $this->plainText($normalized);

                if ($type === 'heading') {
                    $this->pushHeading($headingStack, $attrs['level'] ?? 1, $plainText);
                }

                ksort($headingStack);
                $headingPath = array_values(array_filter(
                    $headingStack,
                    static fn (string $label): bool => $label !== '',
                ));

                $searchText = trim(implode(' ', [...$headingPath, $plainText]));
                try {
                    $encodedNode = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $e) {
                    throw new \RuntimeException('Failed to serialize normalized text block.', 0, $e);
                }

                $blocks[] = new ExtractedTextBlock(
                    blockId: $rawBlockId,
                    parentId: $parentId,
                    ordinal: count($blocks),
                    nodeKind: $type,
                    headingPath: $headingPath,
                    plainText: $plainText,
                    searchText: $searchText,
                    formattedNode: $normalized,
                    sourceHashBinary: hash('sha256', $encodedNode, true),
                );

                continue;
            }

            if (isset($child['content']) && is_array($child['content'])) {
                $childParent = is_string($rawBlockId) && $rawBlockId !== '' ? $rawBlockId : $parentId;
                $this->walkChildren($child['content'], $childParent, $headingStack, $seenBlockIds, $blocks);
            }
        }
    }

    /**
     * @param  array<int, string>  $stack
     */
    private function pushHeading(array &$stack, mixed $rawLevel, string $text): void
    {
        $level = is_numeric($rawLevel) ? (int) $rawLevel : 1;
        $level = max(1, min(6, $level));
        foreach (array_keys($stack) as $existingLevel) {
            if ($existingLevel >= $level) {
                unset($stack[$existingLevel]);
            }
        }
        $trimmed = trim($text);
        if ($trimmed !== '') {
            $stack[$level] = $trimmed;
        }
    }

    /** @param array<string, mixed> $block */
    private function plainText(array $block): string
    {
        $content = $block['content'] ?? [];
        if (! is_array($content)) {
            return '';
        }

        $out = '';
        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) === 'text') {
                $text = $child['text'] ?? '';
                if (is_string($text)) {
                    $out .= $text;
                }
            } else {
                $out .= self::LEAF_TOKEN;
            }
        }

        return $out;
    }

    /** @param array<mixed> $marks */
    private static function markSignature(array $marks): string
    {
        if ($marks === []) {
            return '';
        }

        $encoded = [];
        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $encoded[] = json_encode(self::sortRecursive($mark), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        sort($encoded, SORT_STRING);

        return implode("\n", $encoded);
    }

    private static function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortRecursive(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sortRecursive(...), $value);
    }
}
