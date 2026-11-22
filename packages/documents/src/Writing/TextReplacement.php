<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Writing;

use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;

/**
 * @phpstan-type Node array<string, mixed>
 * @phpstan-type Run array{start: int, end: int, index: int, marks: list<Node>|null}
 * @phpstan-type Target array{start: int, run: Run}
 */
final class TextReplacement
{
    public static function assertArguments(string $blockId, string $expected, string $replacement): void
    {
        if ($blockId === '' || ! mb_check_encoding($blockId, 'UTF-8')
            || $expected === '' || ! mb_check_encoding($expected, 'UTF-8')
            || ! mb_check_encoding($replacement, 'UTF-8')
            || str_contains($expected, RichTextBlockExtractor::LEAF_TOKEN)
            || str_contains($replacement, RichTextBlockExtractor::LEAF_TOKEN)) {
            throw OfficeException::invalidInput('invalid_arguments');
        }
    }

    /**
     * @param  Node  $document
     * @return array{list<int>, Node}
     */
    public static function find(array $document, string $blockId): array
    {
        $matches = self::paths($document, $blockId);
        if ($matches === []) {
            throw OfficeException::preconditionFailed('block_missing');
        }
        if (count($matches) !== 1) {
            throw OfficeException::unsupportedContent('duplicate_block_id');
        }

        [$path, $block] = $matches[0];
        if (! in_array($block['type'] ?? null, ['paragraph', 'heading'], true)) {
            throw OfficeException::unsupportedContent('unsupported_block');
        }

        return [$path, RichTextBlockExtractor::normalizeBlock($block)];
    }

    /**
     * @param  Node  $node
     * @param  list<int>  $path
     * @return list<array{list<int>, Node}>
     */
    private static function paths(array $node, string $blockId, array $path = []): array
    {
        $matches = [];
        foreach (self::nodes($node['content'] ?? null) as $index => $child) {
            $childPath = [...$path, $index];
            $attrs = $child['attrs'] ?? null;
            if (is_array($attrs) && ($attrs['blockId'] ?? null) === $blockId) {
                $matches[] = [$childPath, $child];
            }
            array_push($matches, ...self::paths($child, $blockId, $childPath));
        }

        return $matches;
    }

    /** @param Node $block */
    public static function identity(array $block): string
    {
        $block = RichTextBlockExtractor::normalizeBlock($block);
        $children = self::nodes($block['content'] ?? null);
        foreach ($children as &$child) {
            if (isset($child['marks'])) {
                $child['marks'] = RichTextBlockExtractor::markSignature(self::nodes($child['marks']));
            }
        }
        unset($child);
        if (isset($block['content'])) {
            $block['content'] = $children;
        }

        return json_encode(RichTextBlockExtractor::sortRecursive($block), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  Node  $block
     * @return array{string, list<Run>}
     */
    public static function runs(array $block): array
    {
        $text = '';
        $runs = [];
        foreach (self::nodes($block['content'] ?? null) as $index => $child) {
            $start = strlen($text);
            $value = $child['text'] ?? null;
            $isText = ($child['type'] ?? null) === 'text' && is_string($value);
            $text .= $isText ? $value : RichTextBlockExtractor::LEAF_TOKEN;
            $runs[] = [
                'start' => $start,
                'end' => strlen($text),
                'index' => $index,
                'marks' => $isText ? self::nodes($child['marks'] ?? null) : null,
            ];
        }

        return [$text, $runs];
    }

    /**
     * @param  Node  $block
     * @return Target
     */
    public static function target(array $block, string $expected): array
    {
        [$text, $runs] = self::runs($block);
        $start = strpos($text, $expected);
        if ($start === false) {
            throw OfficeException::preconditionFailed('expected_absent');
        }
        if (strpos($text, $expected, $start + 1) !== false) {
            throw OfficeException::invalidInput('ambiguous_target');
        }
        foreach ($runs as $run) {
            if ($run['marks'] !== null && $run['start'] <= $start && $start + strlen($expected) <= $run['end']) {
                return ['start' => $start, 'run' => $run];
            }
        }

        throw OfficeException::unsupportedContent('crosses_formatting_or_inline');
    }

    /**
     * @param  Node  $document
     * @param  list<int>  $path
     * @param  Node  $block
     * @param  Target  $target
     * @return array{document: Node, evidence: array<string, mixed>}
     */
    public static function apply(array $document, array $path, array $block, array $target, string $expected, string $replacement): array
    {
        $children = self::nodes($block['content'] ?? null);
        $run = $target['run'];
        $index = $run['index'];
        $start = $target['start'];
        [$before] = self::runs($block);
        $offset = $start - $run['start'];
        $runText = substr($before, $run['start'], $run['end'] - $run['start']);
        $children[$index]['text'] = substr_replace($runText, $replacement, $offset, strlen($expected));
        $block['content'] = $children;
        $block = RichTextBlockExtractor::normalizeBlock($block);
        [$after] = self::runs($block);

        return [
            'document' => self::setAt($document, $path, $block),
            'evidence' => [
                'start_utf16' => self::length(substr($before, 0, $start)),
                'length_utf16' => self::length($replacement),
                'replacement' => $replacement,
                'marks' => $replacement === '' ? [] : $run['marks'],
                'committed_text' => $after,
            ],
        ];
    }

    /**
     * @param  Node  $node
     * @param  list<int>  $path
     * @param  Node  $block
     * @return Node
     */
    private static function setAt(array $node, array $path, array $block): array
    {
        $index = array_shift($path);
        if ($index === null) {
            return $block;
        }
        $children = self::nodes($node['content'] ?? null);
        $children[$index] = self::setAt($children[$index], $path, $block);
        $node['content'] = $children;

        return $node;
    }

    /** @return list<Node> */
    public static function nodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(self::node(...), array_filter($value, is_array(...))));
    }

    /** @return Node */
    public static function node(mixed $value): array
    {
        return is_array($value) ? array_filter($value, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }

    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
