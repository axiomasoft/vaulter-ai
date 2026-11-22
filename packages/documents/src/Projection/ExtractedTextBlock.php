<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Projection;

/**
 * Extracted addressable TipTap block (paragraph or heading) prepared for AI text projection.
 */
final readonly class ExtractedTextBlock
{
    /**
     * @param  list<string>  $headingPath
     * @param  array<string, mixed>  $formattedNode  Normalized TipTap block JSON
     */
    public function __construct(
        public string $blockId,
        public ?string $parentId,
        public int $ordinal,
        public string $nodeKind,
        public array $headingPath,
        public string $plainText,
        public string $searchText,
        public array $formattedNode,
        public string $sourceHashBinary,
    ) {}

    /**
     * Wire segments with unique mark names and U+FFFC leaf tokens matching agent-sdk.md.
     *
     * @return list<array{text: string, marks: list<string>}>
     */
    public function wireSegments(): array
    {
        return RichTextBlockExtractor::wireSegmentsFromFormattedNode($this->formattedNode);
    }
}
