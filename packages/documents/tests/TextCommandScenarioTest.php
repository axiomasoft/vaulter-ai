<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;

uses(OfficeTestCase::class);

dataset('text office scenarios', ['text_success', 'text_conflict', 'lost_response', 'acl_revoked']);

function replayTextScenarios(): array
{
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json'), true, 512, JSON_THROW_ON_ERROR);

    return array_column($fixture['scenarios'], null, 'id');
}

function applyTextScenarioEvents(TextCommandFixture $fixture, array $events, int $step): bool
{
    $drop = false;
    foreach ($events as $event) {
        if ($event['before_step'] !== $step) {
            continue;
        }
        $details = $event['details'];
        switch ($event['type']) {
            case 'human_edits_block':
                $state = $fixture->edit($details['block_id'], static function (array $children) use ($details): array {
                    if (isset($details['new_text'])) {
                        return [['type' => 'text', 'text' => $details['new_text']]];
                    }

                    foreach ($children as &$child) {
                        $child['text'] = str_replace($details['replace']['expected'], $details['replace']['replacement'], $child['text']);
                    }

                    return $children;
                });
                expect([$state->epoch, $state->stateVersion])->toBe([$details['marker']['generation'], $details['marker']['position']]);
                break;
            case 'revoke_access':
                $fixture->revoke();
                break;
            case 'drop_next_response':
                $drop = true;
                break;
        }
    }

    return $drop;
}

function mapTextScenarioValues(array $message, array $ids): array
{
    array_walk_recursive($message, static function (mixed &$value) use ($ids): void {
        if (is_string($value) && isset($ids[$value])) {
            $value = $ids[$value];
        }
    });

    return $message;
}

test('Office PHP and authorized HTTP replay the text fixture', function (string $scenarioId, string $transport): void {
    $scenario = replayTextScenarios()[$scenarioId];
    $fixture = new TextCommandFixture($this->app, seed: $scenario['events'][0]['details']);
    $fixture->authorizeHttp();
    $this->fixedClock->set('2026-11-21T11:00:00Z');
    $ids = ['contract-17' => $fixture->node->id];

    foreach ($scenario['steps'] as $index => $step) {
        $drop = applyTextScenarioEvents($fixture, $scenario['events'], $index + 1);
        $request = mapTextScenarioValues($step['request'], $ids);
        $wire = json_decode(json_encode($request, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $actual = $transport === 'php'
            ? $this->app->make(Office::class)->handle($fixture->context, new OfficeRequest(
                1, OfficeOperation::from($wire->operation), $wire->input,
            ))->toArray()
            : $this->postJson('/api/vaulter-ai/v1/operations', $request, ['Authorization' => 'Bearer scenario-test-token'])->json();
        if ($drop) {
            continue;
        }

        expect($this->app->make(WireValidator::class)->isValidMessage(
            json_decode(json_encode($actual, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        ))->toBeTrue();
        $expected = $step['response'];
        if ($wire->operation === 'office.prepare') {
            $result = json_decode(json_encode($actual['result'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            expect($result->digest)->toBe(ProposalDigest::digest($wire->input, $result));
            expect($result->summary)->toContain($wire->input->arguments->expected, $wire->input->arguments->replacement);
            $expected['result']['summary'] = $result->summary;
        }
        foreach (['read_token', 'proposal_id', 'digest', 'receipt_id'] as $field) {
            $placeholder = $expected['result'][$field] ?? null;
            if ($placeholder !== null && ! isset($ids[$placeholder])) {
                $ids[$placeholder] = $actual['result'][$field];
            }
        }
        if (($expected['result']['data']['kind'] ?? null) === 'text_outline') {
            $actualBlocks = array_column($actual['result']['data']['blocks'], null, 'block_id');
            foreach ($expected['result']['data']['blocks'] as $block) {
                $observed = $actualBlocks[$block['block_id']];
                expect($observed['path'][0])->toBe($block['path'][0])
                    ->and($observed['path'][1])->toStartWith($block['path'][1])
                    ->and($observed['snippet'])->not->toBeEmpty();
            }
            $expected['result']['data']['blocks'] = $actual['result']['data']['blocks'];
        }
        expect($actual)->toEqual(mapTextScenarioValues($expected, $ids), 'Step '.($index + 1));
    }

    expect($fixture->snapshot()->stateVersion)->toBe(match ($scenarioId) {
        'text_success', 'lost_response' => 29,
        'text_conflict' => 28,
        'acl_revoked' => 27,
    });
})->with('text office scenarios')->with(['php', 'http']);

test('the Python OfficeClient replays the same text fixture over HTTP', function (string $scenarioId): void {
    $scenario = replayTextScenarios()[$scenarioId];
    $fixture = new TextCommandFixture($this->app, seed: $scenario['events'][0]['details']);
    $fixture->authorizeHttp();
    $this->fixedClock->set('2026-11-21T11:00:00Z');
    $server = stream_socket_server('tcp://127.0.0.1:0');
    expect($server)->toBeResource();
    $address = stream_socket_get_name($server, false);
    $root = dirname(__DIR__, 3);
    $process = new Process([
        'python3', $root.'/clients/python/tests/replay_text_scenario.py',
        'http://'.$address.'/api/vaulter-ai/v1/operations',
        $root.'/tests/Fixtures/office-sdk.json', $scenarioId, $fixture->node->id,
    ], $root.'/clients/python', ['PYTHONPATH' => $root.'/clients/python', 'PYTHONDONTWRITEBYTECODE' => '1'], timeout: 30);
    $process->start();

    try {
        foreach ($scenario['steps'] as $index => $step) {
            $ready = [$server];
            $write = $errors = [];
            expect(stream_select($ready, $write, $errors, 5))->toBe(1, $process->getErrorOutput());
            $socket = stream_socket_accept($server, 0);
            expect($socket)->toBeResource($process->getErrorOutput());
            stream_set_timeout($socket, 5);
            try {
                $requestLine = fgets($socket);
                expect($requestLine)->toStartWith('POST /api/vaulter-ai/v1/operations HTTP/');
                $headers = [];
                while (($line = fgets($socket)) !== false && trim($line) !== '') {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower($key)] = trim($value);
                }
                $body = stream_get_contents($socket, (int) $headers['content-length']);
                $drop = applyTextScenarioEvents($fixture, $scenario['events'], $index + 1);
                $response = $this->call('POST', '/api/vaulter-ai/v1/operations', server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => $headers['authorization'] ?? '',
                ], content: $body);
                if (! $drop) {
                    $status = $response->getStatusCode();
                    $json = $response->getContent();
                    fwrite($socket, sprintf("HTTP/1.1 %d %s\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: %d\r\n\r\n%s",
                        $status, Response::$statusTexts[$status], strlen($json), $json,
                    ));
                }
            } finally {
                fclose($socket);
            }
        }

        expect($process->wait())->toBe(0, $process->getErrorOutput());
    } finally {
        fclose($server);
        if ($process->isRunning()) {
            $process->stop(0);
        }
    }
})->with('text office scenarios');

test('text.insert_block@1 and text.delete_block@1 execute atomically with blockId assignment and receipt verification', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $fixture->authorizeHttp();
    $validator = $this->app->make(WireValidator::class);
    $headers = ['Authorization' => 'Bearer scenario-test-token'];

    $postOp = function (string $operation, array $input) use ($headers, $validator): array {
        $res = $this->postJson('/api/vaulter-ai/v1/operations', [
            'version' => 1,
            'operation' => $operation,
            'input' => $input,
        ], $headers);
        expect($res->status())->toBe(200, $res->getContent());
        $json = $res->json();
        expect($validator->isValidMessage(json_decode(json_encode($json, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)))->toBeTrue();

        return $json;
    };

    // 1. Read anchor block p42 and insert a new paragraph block after it.
    $readAnchor = $postOp('office.read', [
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
    ]);
    $prepInsert = $postOp('office.prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.insert_block@1',
        'read_token' => $readAnchor['result']['read_token'],
        'arguments' => [
            'anchor_block_id' => 'p42',
            'position' => 'after',
            'block_type' => 'paragraph',
            'text' => 'Inserted block text.',
            'marks' => ['bold'],
        ],
    ]);
    $newBlockId = $prepInsert['result']['targets'][1] ?? null;
    expect($newBlockId)->toBeString()->not->toBe('');

    $commitInsert = $postOp('office.commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $prepInsert['result']['proposal_id'],
        'digest' => $prepInsert['result']['digest'],
        'command_id' => 'cmd-insert-1',
    ]);
    expect($commitInsert['result']['state'])->toBe('committed');

    foreach (['at_commit', 'current'] as $mode) {
        $verifyInsert = $postOp('office.verify', [
            'resource_id' => $fixture->node->id,
            'receipt_id' => $commitInsert['result']['receipt_id'],
            'mode' => $mode,
        ]);
        expect($verifyInsert['result']['outcome'])->toBe('passed');
    }

    $blocks = $fixture->snapshot()->content->toArray()['content'] ?? [];
    $found = array_values(array_filter($blocks, static fn (array $b): bool => ($b['attrs']['blockId'] ?? null) === $newBlockId));
    expect($found)->toHaveCount(1)
        ->and($found[0]['content'][0]['text'] ?? null)->toBe('Inserted block text.');

    // 2. Read the newly inserted block and delete it by blockId.
    $readNew = $postOp('office.read', [
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => $newBlockId],
    ]);
    $prepDelete = $postOp('office.prepare', [
        'resource_id' => $fixture->node->id,
        'command' => 'text.delete_block@1',
        'read_token' => $readNew['result']['read_token'],
        'arguments' => [
            'block_id' => $newBlockId,
        ],
    ]);
    $commitDelete = $postOp('office.commit', [
        'resource_id' => $fixture->node->id,
        'proposal_id' => $prepDelete['result']['proposal_id'],
        'digest' => $prepDelete['result']['digest'],
        'command_id' => 'cmd-delete-1',
    ]);
    expect($commitDelete['result']['state'])->toBe('committed');

    foreach (['at_commit', 'current'] as $mode) {
        $verifyDelete = $postOp('office.verify', [
            'resource_id' => $fixture->node->id,
            'receipt_id' => $commitDelete['result']['receipt_id'],
            'mode' => $mode,
        ]);
        expect($verifyDelete['result']['outcome'])->toBe('passed');
    }

    $afterDeleteBlocks = $fixture->snapshot()->content->toArray()['content'] ?? [];
    $remaining = array_values(array_filter($afterDeleteBlocks, static fn (array $b): bool => ($b['attrs']['blockId'] ?? null) === $newBlockId));
    expect($remaining)->toBe([]);
});
