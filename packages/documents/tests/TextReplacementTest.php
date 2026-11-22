<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Writing\TextReplacement;

function replaceExactVectors(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/text-replace-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
}

test('the ProseMirror corpus pins every replacement and post-prepare case', function (): void {
    $path = dirname(__DIR__, 3).'/tests/Fixtures/text-replace-vectors.json';
    expect(hash_file('sha256', $path))->toBe('726b4f1d4b308d0f9d0f37294b02de9c11d4b29360c0911da3ecd0b0ee4d79f6')
        ->and(replaceExactVectors()['cases'])->toHaveCount(14)
        ->and(replaceExactVectors()['commit_after'])->toHaveCount(3);
});

function replaceExactVector(array $document, array $arguments): array
{
    ['blockId' => $id, 'expected' => $expected, 'replacement' => $replacement] = $arguments;
    TextReplacement::assertArguments($id, $expected, $replacement);
    [$path, $block] = TextReplacement::find($document, $id);
    $target = TextReplacement::target($block, $expected);

    return TextReplacement::apply($document, $path, $block, $target, $expected, $replacement) + ['start_byte' => $target['start']];
}

test('PHP replacement matches all ProseMirror golden vectors including refusal codes', function (array $case): void {
    $document = replaceExactVectors()['doc'];
    if (isset($case['error'])) {
        try {
            replaceExactVector($document, $case['args']);
            $this->fail('The vector must be rejected.');
        } catch (OfficeException $failure) {
            expect([$failure->errorCode->value, $failure->getMessage()])->toBe($case['error']);
        }

        return;
    }
    $out = replaceExactVector($document, $case['args']);
    [, $original] = TextReplacement::find($document, $case['args']['blockId']);
    [, $updated] = TextReplacement::find($out['document'], $case['args']['blockId']);
    expect($out['start_byte'])->toBe($case['ok']['start_utf8_bytes'])
        ->and($out['evidence']['start_utf16'])->toBe($case['ok']['start_utf16'])
        ->and($out['evidence']['committed_text'])->toBe($case['ok']['block_text'])
        ->and(RichTextBlockExtractor::wireSegmentsFromFormattedNode($updated))->toBe($case['ok']['segments'])
        ->and($updated['attrs'])->toBe($original['attrs']);
    if ($case['id'] === 'inside_link_keeps_href') {
        expect($updated['content'][1]['marks'])->toBe($original['content'][1]['marks']);
    }
})->with(array_map(static fn (array $case): array => [$case], replaceExactVectors()['cases']));

test('the same target identity survives only the neighboring golden-vector edit', function (array $variant): void {
    $vectors = replaceExactVectors();
    $case = array_column($vectors['cases'], null, 'id')[$variant['case']];
    $document = $vectors['doc'];
    [$path, $block] = TextReplacement::find($document, $case['args']['blockId']);
    $identity = TextReplacement::identity($block);
    if (isset($variant['edit'])) {
        $document = replaceExactVector($document, $variant['edit'])['document'];
    }
    if (isset($variant['set_link_href'])) {
        $target = &$document;
        foreach ($path as $index) {
            $target = &$target['content'][$index];
        }
        foreach ($target['content'] as &$node) {
            if (($node['marks'][0]['type'] ?? null) === 'link') {
                $node['marks'][0]['attrs']['href'] = $variant['set_link_href'];
            }
        }
        unset($node, $target);
    }
    [, $current] = TextReplacement::find($document, $case['args']['blockId']);
    expect(TextReplacement::identity($current) === $identity)->toBe($variant['result'] === 'ok');
})->with(array_map(static fn (array $variant): array => [$variant], replaceExactVectors()['commit_after']));
