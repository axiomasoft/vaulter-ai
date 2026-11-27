#!/usr/bin/env python3
"""Office SDK vector gate for the fixtures that live in this repository.

Checks ``tests/Fixtures/proposal-digest-vectors.json`` with ``ProposalDigest`` and
``WireValidator``, and ``tests/Fixtures/text-replace-vectors.json`` with
``TextReplacement``. A naive ``json.dumps(sort_keys=True)`` must stay distinct
from the pinned JCS text on the vectors that exist to catch code-point order
and integral-number formatting. Stdlib only; PHP 8.4 and ``vendor/autoload.php``
must be available.

``--self-test`` reruns the gate, then proves a corrupted digest pin is refused.
Exit 0 only when every expectation holds.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
AUTOLOAD = ROOT / "vendor" / "autoload.php"
DIGEST_VECTORS = ROOT / "tests" / "Fixtures" / "proposal-digest-vectors.json"
REPLACE_VECTORS = ROOT / "tests" / "Fixtures" / "text-replace-vectors.json"
SCHEMA = ROOT / "packages" / "core" / "resources" / "contracts" / "office-v1.schema.json"
NAIVE_IDS = (
    "utf16_vs_code_point_order",
    "integral_numbers_by_value",
    "integer_double_rounding",
    "rfc8785_3_2_3_sorting",
)

PHP = r"""<?php
declare(strict_types=1);

require $argv[1];

use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Documents\Projection\RichTextBlockExtractor;
use Vaulter\Ai\Documents\Writing\TextReplacement;

$failures = [];

function fail(array &$failures, string $message): void
{
    $failures[] = $message;
    fwrite(STDERR, "FAIL {$message}\n");
}

function replace_exact(array $document, array $arguments): array
{
    ['blockId' => $id, 'expected' => $expected, 'replacement' => $replacement] = $arguments;
    TextReplacement::assertArguments($id, $expected, $replacement);
    [$path, $block] = TextReplacement::find($document, $id);
    $target = TextReplacement::target($block, $expected);

    return TextReplacement::apply($document, $path, $block, $target, $expected, $replacement) + ['start_byte' => $target['start']];
}

$digestRaw = file_get_contents($argv[2]);
if (! is_string($digestRaw)) {
    fwrite(STDERR, "FAIL unreadable digest vectors\n");
    exit(1);
}
$vectors = json_decode($digestRaw, false, 512, JSON_THROW_ON_ERROR);
if (count($vectors->jcs) !== 16 || count($vectors->proposals) !== 5) {
    fail($failures, 'digest vector counts drifted');
}

foreach ($vectors->jcs as $case) {
    try {
        $encoded = ProposalDigest::canonicalize(json_decode($case->input_json, false, 512, JSON_THROW_ON_ERROR));
        if ($case->profile === 'reject') {
            fail($failures, "accepted {$case->id} outside the v1 profile");
            continue;
        }
        $same = $encoded === $case->jcs
            && bin2hex($encoded) === $case->utf8_hex
            && hash('sha256', $encoded) === $case->sha256;
        if (! $same) {
            fail($failures, "jcs mismatch {$case->id}");
        } else {
            fwrite(STDOUT, "ok   jcs {$case->id}\n");
        }
    } catch (InvalidArgumentException|JsonException $exception) {
        if ($case->profile === 'reject') {
            fwrite(STDOUT, "ok   reject {$case->id}\n");
        } else {
            fail($failures, "rejected {$case->id}: {$exception->getMessage()}");
        }
    }
}

$validator = new WireValidator($argv[4]);
foreach ($vectors->proposals as $case) {
    $request = $case->prepare_request;
    $response = $case->prepare_response;
    if (! $validator->isValidMessage($request) || ! $validator->isValidMessage($response)) {
        fail($failures, "schema {$case->id}");
        continue;
    }
    $digest = ProposalDigest::digest($request->input, $response->result);
    $permuted = ProposalDigest::canonicalize(json_decode($case->permuted_record_json, false, 512, JSON_THROW_ON_ERROR));
    $same = $digest === $case->digest
        && $digest === $response->result->digest
        && $permuted === $case->canonical_record;
    if (! $same) {
        fail($failures, "proposal {$case->id}");
    } else {
        fwrite(STDOUT, "ok   proposal {$case->id}\n");
    }
}

$replaceRaw = file_get_contents($argv[3]);
if (! is_string($replaceRaw)) {
    fail($failures, 'unreadable text-replace vectors');
    exit(1);
}
$replace = json_decode($replaceRaw, true, 512, JSON_THROW_ON_ERROR);
if (count($replace['cases']) !== 14 || count($replace['commit_after']) !== 3) {
    fail($failures, 'text-replace vector counts drifted');
}

foreach ($replace['cases'] as $case) {
    $document = $replace['doc'];
    if (isset($case['error'])) {
        try {
            replace_exact($document, $case['args']);
            fail($failures, "expected refusal {$case['id']}");
        } catch (OfficeException $failure) {
            $got = [$failure->errorCode->value, $failure->getMessage()];
            if ($got !== $case['error']) {
                fail($failures, "error mismatch {$case['id']}");
            } else {
                fwrite(STDOUT, "ok   replace {$case['id']}\n");
            }
        }
        continue;
    }
    try {
        $out = replace_exact($document, $case['args']);
        [, $original] = TextReplacement::find($document, $case['args']['blockId']);
        [, $updated] = TextReplacement::find($out['document'], $case['args']['blockId']);
        $segments = RichTextBlockExtractor::wireSegmentsFromFormattedNode($updated);
        $same = $out['start_byte'] === $case['ok']['start_utf8_bytes']
            && $out['evidence']['start_utf16'] === $case['ok']['start_utf16']
            && $out['evidence']['committed_text'] === $case['ok']['block_text']
            && $segments === $case['ok']['segments']
            && ($updated['attrs'] ?? null) === ($original['attrs'] ?? null);
        if (! $same) {
            fail($failures, "replace mismatch {$case['id']}");
        } else {
            fwrite(STDOUT, "ok   replace {$case['id']}\n");
        }
    } catch (Throwable $exception) {
        fail($failures, "replace threw {$case['id']}: {$exception->getMessage()}");
    }
}

$byId = [];
foreach ($replace['cases'] as $case) {
    $byId[$case['id']] = $case;
}
foreach ($replace['commit_after'] as $variant) {
    $case = $byId[$variant['case']];
    $document = $replace['doc'];
    [$path, $block] = TextReplacement::find($document, $case['args']['blockId']);
    $identity = TextReplacement::identity($block);
    if (isset($variant['edit'])) {
        $document = replace_exact($document, $variant['edit'])['document'];
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
    $same = TextReplacement::identity($current) === $identity;
    if ($same !== ($variant['result'] === 'ok')) {
        fail($failures, "commit_after {$variant['id']}");
    } else {
        fwrite(STDOUT, "ok   commit_after {$variant['id']}\n");
    }
}

if ($failures !== []) {
    fwrite(STDERR, count($failures)." failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "sdk vectors agree\n");
exit(0);
"""


def fail(message: str) -> None:
    print(f"FAIL {message}", file=sys.stderr)


def naive_jcs(text: str) -> str:
    return json.dumps(json.loads(text), ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def distinguish_naive_serializers(path: Path) -> list[str]:
    vectors = json.loads(path.read_text(encoding="utf-8"))
    by_id = {case["id"]: case for case in vectors["jcs"]}
    problems = []
    for case_id in NAIVE_IDS:
        case = by_id[case_id]
        if naive_jcs(case["input_json"]) == case["jcs"]:
            problems.append(f"naive json.dumps(sort_keys) matches pinned JCS for {case_id}")
    order = list(json.loads(naive_jcs(by_id["utf16_vs_code_point_order"]["input_json"])))
    if order[-1] != "\U0001f600":
        problems.append("code point order no longer places U+1F600 last")
    pinned = path.read_bytes()
    if hashlib.sha256(pinned).hexdigest() != "eb9b52791f5e525fe6d2c61e33b4528baa98ea13fe6b15d18c0a19963df540e8":
        problems.append("proposal-digest-vectors.json sha256 drifted from the WireContractTest pin")
    replace_pin = hashlib.sha256(REPLACE_VECTORS.read_bytes()).hexdigest()
    if replace_pin != "726b4f1d4b308d0f9d0f37294b02de9c11d4b29360c0911da3ecd0b0ee4d79f6":
        problems.append("text-replace-vectors.json sha256 drifted from the TextReplacementTest pin")
    return problems


def run_php(digest_path: Path, replace_path: Path, schema_path: Path) -> subprocess.CompletedProcess[str]:
    if not AUTOLOAD.is_file():
        raise FileNotFoundError(AUTOLOAD)
    return subprocess.run(
        ["php", "--", str(AUTOLOAD), str(digest_path), str(replace_path), str(schema_path)],
        input=PHP,
        text=True,
        capture_output=True,
        check=False,
    )


def check(digest_path: Path, replace_path: Path, schema_path: Path) -> int:
    problems = distinguish_naive_serializers(digest_path if digest_path == DIGEST_VECTORS else DIGEST_VECTORS)
    if digest_path != DIGEST_VECTORS:
        problems = [item for item in problems if "sha256 drifted" not in item]
    for problem in problems:
        fail(problem)
    if problems:
        return 1
    completed = run_php(digest_path, replace_path, schema_path)
    if completed.stdout:
        print(completed.stdout, end="")
    if completed.returncode != 0 and completed.stderr:
        print(completed.stderr, end="", file=sys.stderr)
    return completed.returncode


def self_test() -> int:
    positive = check(DIGEST_VECTORS, REPLACE_VECTORS, SCHEMA)
    if positive != 0:
        fail("self-test positive gate failed")
        return positive
    vectors = json.loads(DIGEST_VECTORS.read_text(encoding="utf-8"))
    flipped = False
    for case in vectors["jcs"]:
        if case["profile"] == "accept":
            case["sha256"] = "0" * 64
            flipped = True
            break
    if not flipped:
        fail("self-test found no accept vector to corrupt")
        return 1
    with tempfile.TemporaryDirectory() as tmp:
        corrupted = Path(tmp) / "proposal-digest-vectors.json"
        corrupted.write_text(json.dumps(vectors), encoding="utf-8")
        completed = run_php(corrupted, REPLACE_VECTORS, SCHEMA)
    if completed.returncode == 0:
        fail("self-test accepted a corrupted digest pin")
        return 1
    print("ok   self-test refuses a corrupted digest pin")
    return 0


def main() -> int:
    for path in (AUTOLOAD, DIGEST_VECTORS, REPLACE_VECTORS, SCHEMA):
        if not path.is_file():
            fail(f"missing {path}")
            return 1
    if "--self-test" in sys.argv[1:]:
        return self_test()
    return check(DIGEST_VECTORS, REPLACE_VECTORS, SCHEMA)


if __name__ == "__main__":
    sys.exit(main())
