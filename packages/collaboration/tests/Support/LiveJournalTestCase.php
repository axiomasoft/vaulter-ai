<?php

declare(strict_types=1);

namespace Vaulter\Ai\Collaboration\Tests\Support;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Process\Process;
use Vaulter\Ai\Collaboration\CollaborationAiServiceProvider;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Collaboration\CollaborationCallResolver;
use Vaulter\Collaboration\Http\Support\HubProtocol;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Collaboration\VaulterCollaborationServiceProvider;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Support\Identity;
use Vaulter\Testing\Support\SuiteDatabaseConnection;

abstract class LiveJournalTestCase extends OfficeTestCase
{
    public const string HUB_WEBHOOK_SECRET = 'test-webhook-secret-different-value';

    public const string CONTROL_HTTP_URL = 'http://hub-a42.test';

    protected bool $strictProfile = true;

    private ?Process $hubVerifierProcess = null;

    private ?string $hubVerifierUrl = null;

    private ?string $hubVerifierLogPath = null;

    private ?int $hubVerifierOwnerPid = null;

    /** @var array<string, array{update: array{update_id: string, payload: string, sha256: string}, evidence: array<string, mixed>}> */
    private array $preparedSettlements = [];

    protected function setUp(): void
    {
        $this->preparedSettlements = [];
        $this->startHubVerifierServer();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->stopHubVerifierServer();
        parent::tearDown();
    }

    /**
     * @return array<string, string|bool|null>
     */
    public static function resolveSuiteDatabaseConnection(): array
    {
        $driver = Env::get('DB_CONNECTION');
        $database = Env::get('DB_DATABASE');
        if ($driver === 'pgsql' && ($database === null || $database === '' || $database === ':memory:')) {
            $host = is_scalar(Env::get('PGSQL_HOST')) ? (string) Env::get('PGSQL_HOST') : '127.0.0.1';
            $port = is_scalar(Env::get('PGSQL_PORT')) ? (string) Env::get('PGSQL_PORT') : '54329';
            $pgDatabase = is_scalar(Env::get('PGSQL_DATABASE')) ? (string) Env::get('PGSQL_DATABASE') : 'vaulter_ai_test';
            $username = is_scalar(Env::get('PGSQL_USERNAME')) ? (string) Env::get('PGSQL_USERNAME') : 'vaulter';
            $password = is_scalar(Env::get('PGSQL_PASSWORD')) ? (string) Env::get('PGSQL_PASSWORD') : 'vaulter';

            if (! str_ends_with($pgDatabase, '_test')) {
                throw new RuntimeException('Test database must end with _test: '.$pgDatabase);
            }

            $token = Env::get('TEST_TOKEN');
            if (Env::get('VAULTER_TEST_DB_PER_PROCESS') === '1' && is_scalar($token) && preg_match('/^[0-9]+$/', (string) $token) === 1) {
                $pgDatabase = substr($pgDatabase, 0, -strlen('_test')).'_w'.$token.'_test';
            }

            return [
                'driver' => 'pgsql',
                'host' => $host,
                'port' => $port,
                'database' => $pgDatabase,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ];
        }

        return SuiteDatabaseConnection::resolve();
    }

    /**
     * @return array<string, string|bool|null>
     */
    protected function suiteDatabaseConnection(): array
    {
        return self::resolveSuiteDatabaseConnection();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            VaulterCollaborationServiceProvider::class,
            CollaborationAiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.providers.users.model', TestActor::class);
        $app['config']->set('vaulter.modules.collaboration', true);
        $app['config']->set('vaulter-collaboration.strict_profile', $this->strictProfile);
        $app['config']->set('vaulter-collaboration.hub.jwt_keys', ['k1' => 'test-collab-jwt-secret-key-at-least-32-bytes']);
        $app['config']->set('vaulter-collaboration.hub.jwt_active_kid', 'k1');
        $app['config']->set('vaulter-collaboration.hub.issuer', 'https://vaulter-ai.test');
        $app['config']->set('vaulter-collaboration.hub.webhook_secret', self::HUB_WEBHOOK_SECRET);
        $app['config']->set('vaulter-collaboration.control.transport', 'http');
        $app['config']->set('vaulter-collaboration.control.http.urls', [
            $this->hubVerifierUrl ?? self::CONTROL_HTTP_URL,
        ]);
    }

    protected function openStrictJournalFixture(bool $automatic = true, ?array $seed = null): TextCommandFixture
    {
        $fixture = new TextCommandFixture($this->app, automatic: $automatic, seed: $seed);
        Documents::in($fixture->drive->actingAs($fixture->actor))->collaborationSession($fixture->node);

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function hubPostV2(string $routeName, array $body): TestResponse
    {
        $payload = $body + ['nonce' => bin2hex(random_bytes(8))];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $ts = time();
        $sig = CollaborationCallResolver::signature($raw, self::HUB_WEBHOOK_SECRET, $ts);

        return $this->call(
            'POST',
            route($routeName, [], false),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($raw),
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_'.strtoupper(str_replace('-', '_', HubProtocol::HEADER)) => HubProtocol::VERSION,
                'HTTP_'.strtoupper(str_replace('-', '_', CollaborationCallResolver::TIMESTAMP_HEADER)) => (string) $ts,
                'HTTP_'.strtoupper(str_replace('-', '_', CollaborationCallResolver::SIGNATURE_HEADER)) => $sig,
            ],
            $raw,
        );
    }

    /**
     * @return array{document_id: string, node_id: string, generation: int, owner_id: string, owner_fence: int, head_seq: int, command: array<string, mixed>}
     */
    protected function hubAcquireAndClaimNext(string $ownerUuid, int $seedStateVersion = 1): array
    {
        $targetsResp = $this->hubPostV2('vaulter.v1.collaboration.hub.commands.next', []);
        $targetsResp->assertOk();
        /** @var array<int, array<string, mixed>> $targets */
        $targets = $targetsResp->json('targets');
        expect($targets)->not->toBeEmpty();
        $target = $targets[0];
        $documentId = (string) $target['document'];
        $generation = (int) $target['generation'];
        $docRow = DB::table('v_documents')
            ->join('v_document_payloads', 'v_document_payloads.document_id', '=', 'v_documents.id')
            ->where('v_documents.id', Identity::key($documentId) ?? $documentId)
            ->first(['v_documents.node_id', 'v_documents.state_version', 'v_document_payloads.content']);
        $nodeId = $docRow !== null ? Identity::stringify($docRow->node_id) : '';
        expect($nodeId)->not->toBe('');

        $acquireResp = $this->hubPostV2('vaulter.v1.collaboration.hub.ownership.acquire', [
            'document_id' => $documentId,
            'generation' => $generation,
            'owner_id' => $ownerUuid,
            'include_log' => true,
        ]);
        $acquireResp->assertOk();
        $fence = (int) $acquireResp->json('owner_fence');
        $headSeq = (int) $acquireResp->json('head_seq');

        if ($headSeq === 0) {
            $stateVersion = $docRow !== null && is_numeric($docRow->state_version)
                ? (int) $docRow->state_version
                : $seedStateVersion;
            $rawContent = $docRow !== null && is_string($docRow->content)
                ? json_decode($docRow->content, true, 512, JSON_THROW_ON_ERROR)
                : [];
            $tiptap = is_array($rawContent) ? $rawContent : [];
            $seedPayload = $this->hubBuildYjsSeed($tiptap);
            $seedResp = $this->hubPostV2('vaulter.v1.collaboration.hub.commands.settle', [
                'document_id' => $documentId,
                'generation' => $generation,
                'owner_id' => $ownerUuid,
                'owner_fence' => $fence,
                'base_seq' => 0,
                'kind' => 'seed',
                'seed_state_version' => $stateVersion,
                'update' => [
                    'update_id' => (string) Str::uuid(),
                    'payload' => base64_encode($seedPayload),
                    'sha256' => hash('sha256', $seedPayload),
                ],
            ]);
            $seedResp->assertOk();
            $headSeq = (int) $seedResp->json('seq', 1);
            $this->syncHubVerifierLog($nodeId, $generation);
        }

        $nextResp = $this->hubPostV2('vaulter.v1.collaboration.hub.commands.next', [
            'document_id' => $documentId,
            'generation' => $generation,
            'owner_id' => $ownerUuid,
            'owner_fence' => $fence,
        ]);
        $nextResp->assertOk();
        /** @var array<string, mixed> $command */
        $command = $nextResp->json('command');
        expect($command)->toBeArray()
            ->and($command['state'] ?? null)->toBe('running');

        return [
            'document_id' => $documentId,
            'node_id' => $nodeId,
            'generation' => $generation,
            'owner_id' => $ownerUuid,
            'owner_fence' => $fence,
            'head_seq' => $headSeq,
            'command' => $command,
        ];
    }

    /**
     * @param  array<string, mixed>  $claimed
     */
    protected function hubSettleReplacementCommit(array $claimed, ?string $updateBytes = null): TestResponse
    {
        /** @var array<string, mixed> $cmd */
        $cmd = $claimed['command'];
        $cmdId = (string) ($cmd['id'] ?? '');
        $nodeId = (string) ($claimed['node_id'] ?? '');
        $generation = (int) $claimed['generation'];

        if (! isset($this->preparedSettlements[$cmdId])) {
            $snapshot = $this->app->make(UpdateLog::class)->snapshot($nodeId, $generation);
            $checkpointB64 = $snapshot->checkpoint !== null ? base64_encode($snapshot->checkpoint) : null;
            $tailB64 = array_values(array_map(
                static fn (array $entry): string => (string) $entry['payload'],
                $snapshot->tail,
            ));
            $prepared = $this->hubPrepareStrictCommand(
                $checkpointB64,
                $tailB64,
                is_array($cmd['intent'] ?? null) ? $cmd['intent'] : [],
                2,
                (string) ($cmd['command_id'] ?? $cmdId),
            );
            $payload = $updateBytes ?? base64_decode((string) $prepared['bytes_base64'], strict: true);
            if (! is_string($payload) || $payload === '') {
                throw new RuntimeException('Hub prepareOnCopy failed to emit valid Yjs update bytes.');
            }
            $this->preparedSettlements[$cmdId] = [
                'update' => [
                    'update_id' => (string) Str::uuid(),
                    'payload' => base64_encode($payload),
                    'sha256' => hash('sha256', $payload),
                ],
                'evidence' => $prepared['evidence'],
            ];
        }

        $settledInput = $this->preparedSettlements[$cmdId];
        $response = $this->hubPostV2('vaulter.v1.collaboration.hub.commands.settle', [
            'id' => $cmdId,
            'fingerprint' => $cmd['fingerprint'],
            'document_id' => $claimed['document_id'],
            'generation' => $generation,
            'owner_id' => $claimed['owner_id'],
            'owner_fence' => $claimed['owner_fence'],
            'base_seq' => $claimed['head_seq'],
            'kind' => 'commit',
            'updates' => [$settledInput['update']],
            'evidence' => $settledInput['evidence'],
        ]);

        if ($response->isSuccessful()) {
            $this->syncHubVerifierLog($nodeId, $generation);
        }

        return $response;
    }

    /**
     * Append a real Yjs concurrent edit directly to the strict room journal and refresh the Hub verifier log.
     *
     * @param  array<string, mixed>  $claimed
     * @param  array<string, mixed>  $args
     */
    protected function hubAppendConcurrentTextEdit(array $claimed, string $blockId, string $operation, array $args, int $clientId): int
    {
        $nodeId = (string) $claimed['node_id'];
        $generation = (int) $claimed['generation'];
        $updateLog = $this->app->make(UpdateLog::class);
        $snapshot = $updateLog->snapshot($nodeId, $generation);

        $bytes = $this->hubBuildConcurrentEditUpdate(
            $snapshot->checkpoint !== null ? base64_encode($snapshot->checkpoint) : null,
            array_values(array_map(static fn (array $row): string => (string) $row['payload'], $snapshot->tail)),
            $blockId,
            $operation,
            $args,
            $clientId,
        );

        $envelope = new UpdateEnvelope(
            generation: $generation,
            updateId: (string) Str::uuid7(),
            payload: $bytes,
            claimedSha256: hash('sha256', $bytes),
            origin: 'client',
        );
        $ack = $updateLog->appendFenced(
            $nodeId,
            $envelope,
            (string) $claimed['owner_id'],
            (int) $claimed['owner_fence'],
            $snapshot->headSeq,
            static function (): void {},
        );
        $this->syncHubVerifierLog($nodeId, $generation);

        return $ack->seq;
    }

    protected function syncHubVerifierLog(string $nodeId, int $generation, ?int $overrideGeneration = null): void
    {
        if ($this->hubVerifierLogPath === null) {
            return;
        }
        $snapshot = $this->app->make(UpdateLog::class)->snapshot($nodeId, $generation);
        $log = [
            'document_id' => $snapshot->documentId,
            'generation' => $overrideGeneration ?? $snapshot->generation,
            'checkpoint_seq' => $snapshot->checkpointSeq,
            'head_seq' => $snapshot->headSeq,
            'checkpoint' => $snapshot->checkpoint !== null ? base64_encode($snapshot->checkpoint) : null,
            'tail' => $snapshot->tail,
        ];
        file_put_contents($this->hubVerifierLogPath, json_encode($log, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    protected function hubVerifierUrl(): string
    {
        return $this->hubVerifierUrl ?? throw new RuntimeException('Hub verifier server was not started.');
    }

    /**
     * @param  array<string, mixed>  $tiptapContent
     */
    private function hubBuildYjsSeed(array $tiptapContent): string
    {
        $js = <<<'JS'
import fs from 'node:fs'
import * as Y from 'yjs'
import { TiptapTransformer } from '@hocuspocus/transformer'
import { FIELD, extensionsFor } from './src/schema.js'

const input = JSON.parse(fs.readFileSync(0, 'utf8'))
const doc = TiptapTransformer.toYdoc(input.content, FIELD, extensionsFor('vaulter/rich-text', input.content))
const bytes = Buffer.from(Y.encodeStateAsUpdate(doc))
doc.destroy()
process.stdout.write(JSON.stringify({ bytes_base64: bytes.toString('base64') }))
JS;
        $out = $this->runHubNode($js, ['content' => $tiptapContent]);
        $raw = base64_decode((string) ($out['bytes_base64'] ?? ''), strict: true);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Failed to encode Yjs seed from TipTap content.');
        }

        return $raw;
    }

    /**
     * @param  list<string>  $tailPayloads
     * @param  array<string, mixed>  $intent
     * @return array{bytes_base64: string, evidence: array<string, mixed>}
     */
    private function hubPrepareStrictCommand(?string $checkpointB64, array $tailPayloads, array $intent, int $clientId, string $commandId): array
    {
        $js = <<<'JS'
import fs from 'node:fs'
import * as Y from 'yjs'
import { prepareOnCopy } from './src/room-sequencer.js'

const input = JSON.parse(fs.readFileSync(0, 'utf8'))
for (const cond of input.intent?.preconditions ?? []) {
  for (const child of cond?.expected_block?.content ?? []) {
    if (typeof child?.marks === 'string') {
      child.marks = child.marks === '' ? [] : child.marks.split('\n').map((line) => JSON.parse(line))
    }
  }
}
const base = new Y.Doc({ gc: false })
if (input.checkpoint) Y.applyUpdate(base, Buffer.from(input.checkpoint, 'base64'))
for (const b64 of input.tail) Y.applyUpdate(base, Buffer.from(b64, 'base64'))
const prepared = prepareOnCopy(base, input.intent, input.clientId, input.commandId, 'vaulter/rich-text')
base.destroy()
process.stdout.write(JSON.stringify({
  bytes_base64: prepared.bytes ? Buffer.from(prepared.bytes).toString('base64') : '',
  evidence: prepared.evidence,
}))
JS;
        $out = $this->runHubNode($js, [
            'checkpoint' => $checkpointB64,
            'tail' => $tailPayloads,
            'intent' => $intent,
            'clientId' => $clientId,
            'commandId' => $commandId,
        ]);

        return [
            'bytes_base64' => (string) ($out['bytes_base64'] ?? ''),
            'evidence' => is_array($out['evidence'] ?? null) ? $out['evidence'] : [],
        ];
    }

    /**
     * @param  list<string>  $tailPayloads
     * @param  array<string, mixed>  $args
     */
    private function hubBuildConcurrentEditUpdate(?string $checkpointB64, array $tailPayloads, string $blockId, string $operation, array $args, int $clientId): string
    {
        $js = <<<'JS'
import fs from 'node:fs'
import * as Y from 'yjs'
import { FIELD } from './src/schema.js'

const input = JSON.parse(fs.readFileSync(0, 'utf8'))
const doc = new Y.Doc({ gc: false })
if (input.checkpoint) Y.applyUpdate(doc, Buffer.from(input.checkpoint, 'base64'))
for (const b64 of input.tail) Y.applyUpdate(doc, Buffer.from(b64, 'base64'))

const findBlock = (root, blockId) => {
  for (const child of root.toArray()) {
    if (child instanceof Y.XmlElement) {
      if (child.getAttribute('blockId') === blockId) return child
      const nested = findBlock(child, blockId)
      if (nested) return nested
    }
  }
  return null
}

const block = findBlock(doc.getXmlFragment(FIELD), input.blockId)
if (!block) throw new Error(`Missing blockId ${input.blockId}`)
const xmlText = block.toArray().find((node) => node instanceof Y.XmlText)
if (!xmlText) throw new Error(`Block ${input.blockId} has no Y.XmlText`)

const beforeVector = Y.encodeStateVector(doc)
doc.clientID = input.clientId
doc.transact(() => {
  if (input.operation === 'insert_prefix') {
    xmlText.insert(0, String(input.args.prefix ?? ''))
  } else if (input.operation === 'overwrite_target') {
    const runs = xmlText.toDelta()
    const plain = runs.map((r) => r.insert).join('')
    const expected = String(input.args.expected ?? '')
    const replacement = String(input.args.replacement ?? '')
    const idx = plain.indexOf(expected)
    if (idx < 0) throw new Error(`Expected text [${expected}] not found in [${plain}]`)
    let offset = 0
    const run = runs.find((r) => {
      const from = offset
      offset += r.insert.length
      return from <= idx && idx + expected.length <= offset
    })
    xmlText.delete(idx, expected.length)
    xmlText.insert(idx, replacement, run?.attributes ?? {})
  } else {
    throw new Error(`Unsupported operation ${input.operation}`)
  }
})
const deltaBytes = Buffer.from(Y.encodeStateAsUpdate(doc, beforeVector))
doc.destroy()
process.stdout.write(JSON.stringify({ bytes_base64: deltaBytes.toString('base64') }))
JS;
        $out = $this->runHubNode($js, [
            'checkpoint' => $checkpointB64,
            'tail' => $tailPayloads,
            'blockId' => $blockId,
            'operation' => $operation,
            'args' => $args,
            'clientId' => $clientId,
        ]);
        $raw = base64_decode((string) ($out['bytes_base64'] ?? ''), strict: true);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Failed to encode concurrent Yjs edit update.');
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runHubNode(string $script, array $payload): array
    {
        $process = new Process(['node', '--input-type=module', '-e', $script], $this->hubPackageDir());
        $process->setTimeout(15);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Hub Node helper failed: '.$process->getErrorOutput().' '.$process->getOutput());
        }

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Hub Node helper returned non-object JSON.');
        }

        return $decoded;
    }

    private function startHubVerifierServer(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'vai-hub-log-');
        if (! is_string($logPath)) {
            throw new RuntimeException('Unable to allocate temporary Hub log file.');
        }
        file_put_contents($logPath, json_encode(['document_id' => '', 'generation' => 0, 'checkpoint_seq' => 0, 'head_seq' => 0, 'checkpoint' => null, 'tail' => []], JSON_THROW_ON_ERROR));
        $this->hubVerifierLogPath = $logPath;
        $this->hubVerifierOwnerPid = getmypid() ?: null;

        $serverScript = <<<'JS'
import fs from 'node:fs'
import { createControlChannel } from './src/control.js'
import { createManagement } from './src/management.js'
import { verifyCurrentRange } from './src/room-sequencer.js'

const [secret, logPath] = process.argv.slice(1)
const control = createControlChannel({
  conf: { webhookSecret: secret },
  verifyCommand: async ({ document_id, generation, range }) => {
    const log = JSON.parse(fs.readFileSync(logPath, 'utf8'))
    if (log.document_id !== document_id || log.generation !== generation) {
      throw Object.assign(new Error('collaboration_epoch_mismatch'), { code: 'collaboration_epoch_mismatch' })
    }
    return { generation: log.generation, position: log.head_seq, ...await verifyCurrentRange(log, range) }
  },
})
const mgmt = createManagement({
  host: '127.0.0.1',
  port: 0,
  readiness: () => ({ ready: true, checks: {} }),
  metrics: () => '',
  control,
})
const addr = await mgmt.start()
process.stdout.write(JSON.stringify({ url: `http://127.0.0.1:${addr.port}` }) + '\n')
const shutdown = async () => {
  await mgmt.close()
  process.exit(0)
}
process.on('SIGTERM', shutdown)
process.on('SIGINT', shutdown)
JS;
        $process = new Process(
            ['node', '--input-type=module', '-e', $serverScript, self::HUB_WEBHOOK_SECRET, $logPath],
            $this->hubPackageDir(),
        );
        $process->setTimeout(null);
        $process->start();
        $this->hubVerifierProcess = $process;

        $deadline = microtime(true) + 10.0;
        $buffer = '';
        while (microtime(true) < $deadline) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Hub verifier exited prematurely: '.$process->getErrorOutput());
            }
            $buffer .= $process->getIncrementalOutput();
            $pos = strpos($buffer, "\n");
            if ($pos !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && is_string($decoded['url'] ?? null)) {
                    $this->hubVerifierUrl = $decoded['url'];

                    return;
                }
            }
            usleep(20_000);
        }

        throw new RuntimeException('Hub verifier failed to report listening URL within 10s.');
    }

    private function stopHubVerifierServer(): void
    {
        $currentPid = getmypid();
        if ($this->hubVerifierOwnerPid !== null && $currentPid !== $this->hubVerifierOwnerPid) {
            return;
        }
        if ($this->hubVerifierProcess !== null) {
            if ($this->hubVerifierProcess->isRunning()) {
                $this->hubVerifierProcess->stop(1);
            }
            $this->hubVerifierProcess = null;
        }
        if ($this->hubVerifierLogPath !== null && is_file($this->hubVerifierLogPath)) {
            @unlink($this->hubVerifierLogPath);
            $this->hubVerifierLogPath = null;
        }
        $this->hubVerifierUrl = null;
    }

    private function hubPackageDir(): string
    {
        $dir = dirname(__DIR__, 5).'/vaulter-collaboration/packages/hub';
        if (! is_dir($dir.'/src')) {
            throw new RuntimeException("Missing Hub package directory at {$dir}");
        }

        return $dir;
    }
}
