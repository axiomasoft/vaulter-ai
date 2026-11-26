<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Http\Request;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\OfficeCommand;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Contracts\ResourceReader;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandDescriptor;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadPage;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Http\OfficeController;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Protocol\RequestFingerprint;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Tests\Support\InMemoryResourceDirectory;
use Vaulter\Ai\Tests\Support\OfficeTestCase;

uses(OfficeTestCase::class);

beforeEach(function (): void {
    $this->bootVaulterDomain = false;
    $this->bootDocumentsAi = false;
});

test('provenance matches copied schema and office-sdk fixtures', function (): void {
    $root = dirname(__DIR__, 3);
    $provRaw = file_get_contents($root.'/tests/Fixtures/provenance.json');
    expect($provRaw)->toBeString();

    $prov = json_decode((string) $provRaw, false, 512, JSON_THROW_ON_ERROR);
    expect($prov)->toBeInstanceOf(stdClass::class);
    expect($prov->files)->toHaveCount(2);

    foreach ($prov->files as $entry) {
        $target = $root.'/'.$entry->target_path;
        expect(is_file($target))->toBeTrue("Missing fixture target {$target}");
        $bytes = (string) file_get_contents($target);
        expect(strlen($bytes))->toBe($entry->bytes);
        expect(hash('sha256', $bytes))->toBe($entry->sha256);
    }
});

test('WireValidator validates all 68 messages across all 7 operations in office-sdk.json', function (): void {
    $validator = $this->app->make(WireValidator::class);
    $sdkPath = dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json';
    $sdk = json_decode((string) file_get_contents($sdkPath), false, 512, JSON_THROW_ON_ERROR);

    $messageCount = 0;
    $seenOps = [];

    foreach ($sdk->scenarios as $scenario) {
        foreach ($scenario->steps as $index => $step) {
            expect($validator->isValidMessage($step->request))
                ->toBeTrue("Request failed validation in {$scenario->id} step {$index}");
            expect($validator->isValidMessage($step->response))
                ->toBeTrue("Response failed validation in {$scenario->id} step {$index}");

            $seenOps[$step->request->operation] = true;
            $messageCount += 2;
        }
    }

    expect($messageCount)->toBe(68);
    expect(array_keys($seenOps))->toEqualCanonicalizing(array_map(
        static fn (OfficeOperation $op): string => $op->value,
        OfficeOperation::cases(),
    ));
});

test('ProposalDigest matches office-sdk.json and RFC 8785 JCS edge vectors', function (): void {
    $sdkPath = dirname(__DIR__, 3).'/tests/Fixtures/office-sdk.json';
    $sdk = json_decode((string) file_get_contents($sdkPath), false, 512, JSON_THROW_ON_ERROR);

    $verifiedDigests = 0;
    foreach ($sdk->scenarios as $scenario) {
        foreach ($scenario->steps as $step) {
            if ($step->request->operation === 'office.prepare' && $step->response->ok === true) {
                $computed = ProposalDigest::digest($step->request->input, $step->response->result);
                expect($computed)->toBe($step->response->result->digest, "Digest mismatch in {$scenario->id}");
                $verifiedDigests++;
            }
        }
    }
    expect($verifiedDigests)->toBe(7);

    // RFC 8785 UTF-16BE member sorting: astral 😀 (D83D DE00) sorts BEFORE BMP U+E000 and U+FB33.
    $input = json_decode('{"\\ufb33":"FB33","\\ud83d\\ude00":"1F600","\\ue000":"E000","\\u00e9":"E9","a":"61"}', false, 512, JSON_THROW_ON_ERROR);
    expect(ProposalDigest::canonicalize($input))
        ->toBe('{"a":"61","é":"E9","😀":"1F600","":"E000","דּ":"FB33"}');

    // Empty objects vs arrays and numeric member keys.
    $containers = json_decode('{"c":[{}],"b":[],"a":{},"o":{"1":"b","0":"a"}}', false, 512, JSON_THROW_ON_ERROR);
    expect(ProposalDigest::canonicalize($containers))
        ->toBe('{"a":{},"b":[],"c":[{}],"o":{"0":"a","1":"b"}}');

    // Raw /, DEL, U+2028, U+2029, short escapes, and integral float normalization (-0.0 -> 0).
    $rawAndNums = json_decode('{"s":"a\\/\\u007f\\u2028\\u2029\\u001F","n":27.0,"z":-0.0}', false, 512, JSON_THROW_ON_ERROR);
    expect(ProposalDigest::canonicalize($rawAndNums))
        ->toBe('{"n":27,"s":"a/'."\x7f\u{2028}\u{2029}".'\u001f","z":0}');

    // Unsafe integers and fractions rejected by v1 digest profile.
    expect(static fn (): string => ProposalDigest::canonicalize((object) ['n' => 1.5]))
        ->toThrow(InvalidArgumentException::class);
    expect(static fn (): string => ProposalDigest::canonicalize((object) ['n' => 9007199254740992]))
        ->toThrow(InvalidArgumentException::class);
});

test('production ProposalDigest agrees with all pinned JCS and prepare exchange vectors', function (): void {
    $raw = file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/proposal-digest-vectors.json');
    expect(hash('sha256', $raw))->toBe('eb9b52791f5e525fe6d2c61e33b4528baa98ea13fe6b15d18c0a19963df540e8');
    $vectors = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    expect($vectors->jcs)->toHaveCount(16)->and($vectors->proposals)->toHaveCount(5);
    foreach ($vectors->jcs as $case) {
        if ($case->profile === 'reject') {
            try {
                ProposalDigest::canonicalize(json_decode($case->input_json, false, 512, JSON_THROW_ON_ERROR));
                $this->fail("Accepted {$case->id} outside the v1 profile.");
            } catch (InvalidArgumentException|JsonException) {
                continue;
            }
        }
        $encoded = ProposalDigest::canonicalize(json_decode($case->input_json, false, 512, JSON_THROW_ON_ERROR));
        expect($encoded)->toBe($case->jcs, $case->id)
            ->and(bin2hex($encoded))->toBe($case->utf8_hex)
            ->and(hash('sha256', $encoded))->toBe($case->sha256);
        if ($case->id === 'request_fingerprint_text_success') {
            $request = json_decode($case->input_json, false, 512, JSON_THROW_ON_ERROR);
            expect(bin2hex(RequestFingerprint::of($request->command, $request->proposal_id, $request->digest)))->toBe($case->sha256);
        }
    }
    foreach ($vectors->proposals as $case) {
        expect(ProposalDigest::digest($case->prepare_request->input, $case->prepare_response->result))->toBe($case->digest)
            ->and(ProposalDigest::canonicalize(json_decode($case->permuted_record_json, false, 512, JSON_THROW_ON_ERROR)))
            ->toBe($case->canonical_record);
    }
});

test('HTTP enforces all three transport boundaries and rejects body authority injection', function (): void {
    $validator = $this->app->make(WireValidator::class);
    $directory = new InMemoryResourceDirectory;
    $directory->put('t1', 'p1', 'user/1', new ResourceEntry('doc-1', ResourceType::Text, 'Alpha doc'));

    $this->app->instance(ResourceDirectory::class, $directory);
    $this->app->instance(HostPolicy::class, new class implements HostPolicy
    {
        public function profile(CallContext $context): HostProfile
        {
            return new HostProfile('documents', 'laravel-ai', null, ['text.replace_exact@1'], ApprovalMode::Automatic);
        }

        public function allowedCommands(CallContext $context, ResourceEntry $resource): array
        {
            return ['text.replace_exact@1'];
        }

        public function allowsCommand(CallContext $context, ResourceEntry $resource, string $command): bool
        {
            return $command === 'text.replace_exact@1';
        }
    });

    $registry = $this->freshRegistry();
    $registry->registerReader(new class implements ResourceReader
    {
        public function resourceType(): ResourceType
        {
            return ResourceType::Text;
        }

        public function read(CallContext $context, ResourceEntry $resource, stdClass $selector, ?int $limit, ?string $cursor, ?string $afterReceipt): ReadPage
        {
            return new ReadPage(
                marker: new Marker(1, 1),
                data: (object) ['kind' => 'text_block', 'block_id' => 'p1', 'segments' => [(object) ['text' => 'hi', 'marks' => []]]],
                truncated: false,
                nextCursor: null,
                selectorKind: 'text_block',
                schemaRevision: '2',
                completeScope: true,
            );
        }
    });
    $registry->registerCommand(new class implements OfficeCommand
    {
        public function descriptor(): CommandDescriptor
        {
            return new CommandDescriptor(
                'text.replace_exact@1',
                'text.replace_exact',
                1,
                ResourceType::Text,
                'single_resource_write',
                (object) [
                    'type' => 'object',
                    'required' => ['block_id', 'expected', 'replacement'],
                    'properties' => (object) [
                        'block_id' => (object) ['type' => 'string', 'minLength' => 1],
                        'expected' => (object) ['type' => 'string', 'minLength' => 1],
                        'replacement' => (object) ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'text_and_marks',
            );
        }

        public function prepare(CallContext $context, ResourceEntry $resource, ReadTokenRecord $token, stdClass $arguments): PreparedPlan
        {
            throw OfficeException::operationUnknown();
        }
    });

    // Without authenticated context: valid v1 request returns 401 authentication_required.
    $unauth = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.search',
        'input' => ['query' => 'alpha'],
    ], JSON_THROW_ON_ERROR));
    $unauth->assertStatus(401);
    $unauthDecoded = json_decode($unauth->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->isValidMessage($unauthDecoded))->toBeTrue();
    expect($unauthDecoded->error->code)->toBe('authentication_required');

    // Boundary 1: malformed JSON, array body, missing operation, or unknown operation => 400 {"error":"invalid_request"}.
    foreach ([
        '{bad-json',
        '[1,2,3]',
        json_encode(['version' => 1, 'input' => ['query' => 'a']]),
        json_encode(['version' => 1, 'operation' => 'office.secret_probe', 'input' => []]),
    ] as $raw) {
        $resp = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) $raw);
        $resp->assertStatus(400);
        expect($resp->json())->toBe(['error' => 'invalid_request']);
        expect((string) $resp->getContent())->not->toContain('secret_probe');
    }

    // Boundary 0: a body above 1 MiB is refused before it is decoded; one of exactly 1 MiB is read.
    $search = json_encode(['version' => 1, 'operation' => 'office.search', 'input' => ['query' => 'alpha']], JSON_THROW_ON_ERROR);
    $post = fn (string $raw) => $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], $raw);
    $post(str_pad($search, WireValidator::MAX_HTTP_BODY_BYTES))->assertStatus(401);
    $tooLarge = $post(str_pad($search, WireValidator::MAX_HTTP_BODY_BYTES + 1));
    $tooLarge->assertStatus(413);
    expect($tooLarge->json())->toBe(['error' => 'request_too_large']);

    // Bind trusted CallContext resolver ignoring request body.
    $this->app->instance(CallContextResolver::class, new class implements CallContextResolver
    {
        public function resolve(Request $request): ?CallContext
        {
            return new CallContext('t1', 'p1', 'user/1');
        }
    });

    // Boundary 2: known operation with unsupported integer version => 400 v1 unsupported_version envelope.
    $badVer = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 2,
        'operation' => 'office.read',
        'input' => ['resource_id' => 'doc-1', 'selector' => ['kind' => 'text_block', 'block_id' => 'p1']],
    ], JSON_THROW_ON_ERROR));
    $badVer->assertStatus(400);
    $badVerDecoded = json_decode($badVer->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->isValidMessage($badVerDecoded))->toBeTrue();
    expect($badVerDecoded->operation)->toBe('office.read');
    expect($badVerDecoded->ok)->toBeFalse();
    expect($badVerDecoded->error->code)->toBe('unsupported_version');
    expect($badVerDecoded->error->next_action)->toBe('stop');

    // Boundary 3: known v1 operation with invalid input or extra authority keys in root/input => 400 invalid_input.
    foreach ([
        ['version' => 1, 'operation' => 'office.search', 'tenant_id' => 't2', 'input' => ['query' => 'alpha']],
        ['version' => 1, 'operation' => 'office.search', 'input' => ['query' => 'alpha', 'actor' => 'user/99']],
        ['version' => 1, 'operation' => 'office.describe', 'input' => ['resource_id' => 'doc-1', 'command' => 'unknown@1']],
    ] as $badPayload) {
        $badInput = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($badPayload, JSON_THROW_ON_ERROR));
        $badInput->assertStatus(400);
        $decoded = json_decode($badInput->getContent(), false, 512, JSON_THROW_ON_ERROR);
        expect($validator->isValidMessage($decoded))->toBeTrue();
        expect($decoded->ok)->toBeFalse();
        expect($decoded->error->code)->toBe('invalid_input');
        expect($decoded->error->next_action)->toBe('stop');
    }

    // HTTP and PHP port produce identical schema-valid search output.
    $httpSearch = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.search',
        'input' => ['query' => 'alpha', 'resource_type' => 'text', 'limit' => 5],
    ], JSON_THROW_ON_ERROR));
    $httpSearch->assertStatus(200);
    $httpDecoded = json_decode($httpSearch->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->isValidMessage($httpDecoded))->toBeTrue();

    $phpSearch = $this->app->make(Office::class)->handle(
        new CallContext('t1', 'p1', 'user/1'),
        new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'alpha', 'resource_type' => 'text', 'limit' => 5]),
    );
    expect($validator->isValidMessage($phpSearch->toStdClass()))->toBeTrue();
    expect($phpSearch->toArray())->toBe($httpSearch->json());
});

test('a cached operations controller takes its authentication resolver from the dispatching scope', function (): void {
    $controller = $this->app->make(OfficeController::class);
    $request = Request::create('/operations', 'POST', content: '{"version":1,"operation":"office.search","input":{"query":"alpha"}}');
    $route = (new Route('POST', '/operations', OfficeController::class))->bind($request);
    foreach ([new Container, new Container] as $scope) {
        $resolver = new class implements CallContextResolver
        {
            public int $calls = 0;

            public function resolve(Request $request): ?CallContext
            {
                $this->calls++;

                return null;
            }
        };
        $scope->instance(Request::class, $request);
        $scope->instance(ContainerContract::class, $scope);
        $scope->instance(CallContextResolver::class, $resolver);
        $response = (new ControllerDispatcher($scope))->dispatch($route, $controller, '__invoke');

        expect($response->getStatusCode())->toBe(401)->and($resolver->calls)->toBe(1);
    }
});
