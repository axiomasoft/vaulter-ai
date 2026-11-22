<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\TransactionRunner;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Documents\Persistence\VaulterTransactionRunner;
use Vaulter\Ai\Documents\Projection\DefaultDocumentScopeResolver;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Drive\Facades\Vaulter;

uses(OfficeTestCase::class);

test('search, outline p42/p71, block segments, and ACL isolation behave identically over PHP and HTTP', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $editor = TestActor::query()->create(['name' => 'Editor', 'email' => 'editor@example.test']);
    $outsider = TestActor::query()->create(['name' => 'Outsider', 'email' => 'outsider@example.test']);

    $ownerA = TestOwner::query()->create(['name' => 'Workspace A']);
    $ownerB = TestOwner::query()->create(['name' => 'Workspace B']);

    $driveA = Vaulter::drive($ownerA)->asSystem('seed-test');
    $driveB = Vaulter::drive($ownerB)->asSystem('seed-test');

    // Grant Editor full edit on driveA root folder, leave Outsider without access.
    $editorGrant = $driveA->access()->grant($driveA->root()->id, new GrantInput(Subject::ofModel($editor), RoleRegistry::WRITER));
    // Grant Outsider on driveB only.
    $driveB->access()->grant($driveB->root()->id, new GrantInput(Subject::ofModel($outsider), RoleRegistry::WRITER));

    $actingEditor = $driveA->actingAs($editor);
    $contractDocContent = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['level' => 1, 'blockId' => 'h-calc'],
                'content' => [['type' => 'text', 'text' => 'Расчёты']],
            ],
            [
                'type' => 'heading',
                'attrs' => ['level' => 2, 'blockId' => 'h-42'],
                'content' => [['type' => 'text', 'text' => '4.2 Срок оплаты']],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p42'],
                'content' => [
                    ['type' => 'text', 'text' => 'Покупатель оплачивает счёт в течение '],
                    ['type' => 'text', 'text' => '15', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' рабочих дней.'],
                ],
            ],
            [
                'type' => 'heading',
                'attrs' => ['level' => 1, 'blockId' => 'h-claims'],
                'content' => [['type' => 'text', 'text' => 'Претензии']],
            ],
            [
                'type' => 'heading',
                'attrs' => ['level' => 2, 'blockId' => 'h-71'],
                'content' => [['type' => 'text', 'text' => '7.1']],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p71'],
                'content' => [
                    ['type' => 'text', 'text' => 'Срок рассмотрения претензии — 10 рабочих дней.'],
                ],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p-inline'],
                'content' => [
                    ['type' => 'text', 'text' => 'Ссылка ', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example']]]],
                    ['type' => 'text', 'text' => 'один', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.example']]]],
                    ['type' => 'text', 'text' => 'два', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://b.example']]]],
                    ['type' => 'hardBreak'],
                    ['type' => 'text', 'text' => 'конец'],
                ],
            ],
        ],
    ];

    $contractNode = Documents::in($actingEditor)->create('Договор поставки', 'vaulter/rich-text', $contractDocContent);

    // Restricted folder accessible only via system (not granted to Editor).
    $secretFolder = $driveA->folders()->create('Secret');
    $driveA->access()->restrictInheritance($secretFolder->id, InheritanceRestriction::Empty);
    $secretNode = Documents::in($driveA)->create('Договор секретный', 'vaulter/rich-text', $contractDocContent, $secretFolder->id);

    // Foreign workspace document.
    $foreignNode = Documents::in($driveB->actingAs($outsider))->create('Договор чужой', 'vaulter/rich-text', $contractDocContent);

    // Trashed document must also remain hidden.
    $trashedNode = Documents::in($actingEditor)->create('Договор удалённый', 'vaulter/rich-text', $contractDocContent);
    $actingEditor->nodes()->trash($trashedNode->id);

    $beforeSnapshot = Documents::in($actingEditor)->content($contractNode->id);
    $beforeStateVersion = $beforeSnapshot->stateVersion;
    $beforeContentHash = $beforeSnapshot->contentHash;

    $validator = $this->app->make(WireValidator::class);
    $office = $this->app->make(Office::class);

    $editorCtx = new CallContext(
        tenantId: '61',
        projectScope: 'default',
        effectiveSubject: 'user/'.$editor->getKey(),
        profile: 'documents',
        driveId: $driveA->snapshot()->id,
        actor: $editor,
    );

    // 1. Search by title "Договор" (and lowercase "договор") finds only accessible contractNode.
    foreach (['Договор', 'договор'] as $titleQuery) {
        $searchTitle = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Search, (object) [
            'query' => $titleQuery,
            'resource_type' => 'text',
            'limit' => 5,
        ]));
        expect($validator->isValidMessage($searchTitle->toStdClass()))->toBeTrue();
        expect($searchTitle->ok)->toBeTrue();
        expect(array_map(static fn (stdClass $i): string => $i->resource_id, $searchTitle->result?->items ?? []))
            ->toBe([$contractNode->id]);
    }

    // 2. Search by projected body/heading phrase "срок оплаты" discovers contractNode too.
    $searchPhrase = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'срок оплаты',
        'resource_type' => 'text',
        'limit' => 5,
    ]));
    expect($validator->isValidMessage($searchPhrase->toStdClass()))->toBeTrue();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $searchPhrase->result?->items ?? []))
        ->toBe([$contractNode->id]);

    // 3. Read outline with query "срок" returns p42 and p71 with their hierarchical heading paths.
    $outline = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $contractNode->id,
        'selector' => (object) ['kind' => 'text_outline', 'query' => 'срок'],
    ]));
    expect($validator->isValidMessage($outline->toStdClass()))->toBeTrue();
    expect($outline->ok)->toBeTrue();
    $outlineBlocks = $outline->result?->data->blocks ?? [];
    $byId = [];
    foreach ($outlineBlocks as $b) {
        $byId[$b->block_id] = $b;
    }
    expect(array_keys($byId))->toContain('p42', 'p71');
    expect($byId['p42']->path)->toBe(['Расчёты', '4.2 Срок оплаты']);
    expect($byId['p71']->path)->toBe(['Претензии', '7.1']);

    // 4. Read p42 block returns only requested block segments and marks.
    $readP42 = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $contractNode->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]));
    expect($validator->isValidMessage($readP42->toStdClass()))->toBeTrue();
    expect($readP42->ok)->toBeTrue();
    expect($readP42->result?->data->block_id)->toBe('p42');
    expect($readP42->result?->data->segments)->toHaveCount(3);
    expect($readP42->result?->data->segments[0]->text)->toBe('Покупатель оплачивает счёт в течение ');
    expect($readP42->result?->data->segments[1]->text)->toBe('15');
    expect($readP42->result?->data->segments[1]->marks)->toBe(['bold']);
    expect($readP42->result?->data->segments[2]->text)->toBe(' рабочих дней.');

    // 5. Read inline marks & U+FFFC hardBreak block preserves link attr run separation while merging identical attrs.
    $readInline = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $contractNode->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p-inline'],
    ]));
    expect($validator->isValidMessage($readInline->toStdClass()))->toBeTrue();
    $inlineSegs = $readInline->result?->data->segments ?? [];
    expect($inlineSegs)->toHaveCount(4);
    expect($inlineSegs[0]->text)->toBe('Ссылка один');
    expect($inlineSegs[0]->marks)->toBe(['link']);
    expect($inlineSegs[1]->text)->toBe('два');
    expect($inlineSegs[1]->marks)->toBe(['link']);
    expect($inlineSegs[2]->text)->toBe("\u{FFFC}");
    expect($inlineSegs[2]->marks)->toBe([]);
    expect($inlineSegs[3]->text)->toBe('конец');

    // 6. Verify reader never mutated stateVersion or contentHash (ETag stability).
    $afterSnapshot = Documents::in($actingEditor)->content($contractNode->id);
    expect($afterSnapshot->stateVersion)->toBe($beforeStateVersion);
    expect($afterSnapshot->contentHash)->toBe($beforeContentHash);

    // 7. Describe over HTTP matches wire schema cleanly.
    $activeContext = $editorCtx;
    $this->app->instance(CallContextResolver::class, new class($activeContext) implements CallContextResolver
    {
        public function __construct(public ?CallContext $current) {}

        public function resolve(Request $request): ?CallContext
        {
            return $this->current;
        }
    });
    $resolverRef = $this->app->make(CallContextResolver::class);

    $httpDescribe = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.describe',
        'input' => ['resource_id' => $contractNode->id, 'command' => 'text.replace_exact@1'],
    ], JSON_THROW_ON_ERROR));
    $httpDescribe->assertStatus(200);
    $descDecoded = json_decode($httpDescribe->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($validator->isValidMessage($descDecoded))->toBeTrue();
    expect($descDecoded->result->verification)->toBe('text_and_marks');

    // 8. Hidden folder doc, trashed doc, foreign workspace doc, outsider actor, and revoked ACL all hide uniformly.
    foreach ([$secretNode->id, $trashedNode->id, $foreignNode->id] as $hiddenId) {
        $resp = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'version' => 1,
            'operation' => 'office.read',
            'input' => ['resource_id' => $hiddenId, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']],
        ], JSON_THROW_ON_ERROR));
        $resp->assertStatus(404);
        $err = json_decode($resp->getContent(), false, 512, JSON_THROW_ON_ERROR);
        expect($validator->isValidMessage($err))->toBeTrue();
        expect($err->error->code)->toBe('resource_unavailable');
        expect((string) $resp->getContent())->not->toContain('секретный');
        expect((string) $resp->getContent())->not->toContain('Покупатель');
    }

    // Multi-drive enumeration (driveId = null) still discovers only contractNode and hides foreign/secret/trashed.
    $allDrivesCtx = new CallContext(
        tenantId: '61',
        projectScope: 'default',
        effectiveSubject: 'user/'.$editor->getKey(),
        profile: 'documents',
        driveId: null,
        actor: $editor,
    );
    $allDrivesSearch = $office->handle($allDrivesCtx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'Договор',
        'resource_type' => 'text',
        'limit' => 10,
    ]));
    expect($allDrivesSearch->ok)->toBeTrue();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $allDrivesSearch->result?->items ?? []))
        ->toBe([$contractNode->id]);

    // Outsider context returns resource_unavailable on contractNode.
    $resolverRef->current = new CallContext('61', 'default', 'user/'.$outsider->getKey(), 'documents', '1', $driveA->snapshot()->id, $outsider);
    $outsiderRead = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.read',
        'input' => ['resource_id' => $contractNode->id, 'selector' => ['kind' => 'text_outline', 'query' => 'срок']],
    ], JSON_THROW_ON_ERROR));
    $outsiderRead->assertStatus(404);
    expect($outsiderRead->json('error.code'))->toBe('resource_unavailable');

    // Cross-tenant context hides contractNode.
    $resolverRef->current = new CallContext('62', 'default', 'user/'.$editor->getKey(), 'documents', '1', $driveA->snapshot()->id, $editor);
    $otherTenant = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.describe',
        'input' => ['resource_id' => $contractNode->id, 'command' => 'text.replace_exact@1'],
    ], JSON_THROW_ON_ERROR));
    $otherTenant->assertStatus(404);
    expect($otherTenant->json('error.code'))->toBe('resource_unavailable');

    // Revoke Editor's access on driveA root: immediate resource_unavailable over both PHP and HTTP.
    $resolverRef->current = $editorCtx;
    $driveA->access()->revoke($driveA->root()->id, $editorGrant);

    $revokedPhp = $office->handle($editorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $contractNode->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]));
    expect($revokedPhp->ok)->toBeFalse();
    expect($revokedPhp->errorCode)->toBe(ErrorCode::ResourceUnavailable);
    expect($revokedPhp->nextAction)->toBe(NextAction::Stop);

    $revokedHttp = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.read',
        'input' => ['resource_id' => $contractNode->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']],
    ], JSON_THROW_ON_ERROR));
    $revokedHttp->assertStatus(404);
    expect($revokedHttp->json('error.code'))->toBe('resource_unavailable');
});

test('two-project scope isolation blocks cross-project drives even when actor has Drive access in both', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Dual Project User', 'email' => 'dual@example.test']);
    $ownerA = TestOwner::query()->create(['name' => 'Org Project A']);
    $ownerB = TestOwner::query()->create(['name' => 'Org Project B']);

    $driveA = Vaulter::drive($ownerA)->asSystem('seed');
    $driveB = Vaulter::drive($ownerB)->asSystem('seed');
    $driveA->access()->grant($driveA->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));
    $driveB->access()->grant($driveB->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));

    $scopes = $this->app->make(DefaultDocumentScopeResolver::class);
    $scopes->bindDriveScope($driveA->snapshot()->id, 61, 'project-a');
    $scopes->bindDriveScope($driveB->snapshot()->id, 61, 'project-b');

    $docContent = static fn (string $text): array => [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => $text]],
        ]],
    ];

    $docA = Documents::in($driveA)->create('Договор проект Альфа', 'vaulter/rich-text', $docContent('Текст Альфа'));
    $docB = Documents::in($driveB)->create('Договор проект Бета', 'vaulter/rich-text', $docContent('Текст Бета'));

    $office = $this->app->make(Office::class);
    $ctxProjectAAll = new CallContext('61', 'project-a', 'user/'.$actor->getKey(), 'documents', '1', null, $actor);
    $ctxProjectAExplicitB = new CallContext('61', 'project-a', 'user/'.$actor->getKey(), 'documents', '1', $driveB->snapshot()->id, $actor);
    $ctxProjectBAll = new CallContext('61', 'project-b', 'user/'.$actor->getKey(), 'documents', '1', null, $actor);

    $searchA = $office->handle($ctxProjectAAll, new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'Договор', 'limit' => 10]));
    expect($searchA->ok)->toBeTrue();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $searchA->result?->items ?? []))->toBe([$docA->id]);

    $searchExplicitBFromA = $office->handle($ctxProjectAExplicitB, new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'Договор', 'limit' => 10]));
    expect($searchExplicitBFromA->ok)->toBeTrue();
    expect($searchExplicitBFromA->result?->items)->toBe([]);

    $readBFromA = $office->handle($ctxProjectAAll, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $docB->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p1'],
    ]));
    expect($readBFromA->ok)->toBeFalse();
    expect($readBFromA->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    $searchB = $office->handle($ctxProjectBAll, new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'Договор', 'limit' => 10]));
    expect($searchB->ok)->toBeTrue();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $searchB->result?->items ?? []))->toBe([$docB->id]);
});

test('office.describe requires edit permission before disclosing text.replace_exact@1 schema', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $viewer = TestActor::query()->create(['name' => 'Viewer', 'email' => 'viewer@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org View']);
    $drive = Vaulter::drive($owner)->asSystem('seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($viewer), RoleRegistry::PREVIEWER));

    $doc = Documents::in($drive)->create('Договор просмотр', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'Только чтение']],
        ]],
    ]);

    $viewerCtx = new CallContext('61', 'default', 'user/'.$viewer->getKey(), 'documents', '1', $drive->snapshot()->id, $viewer);
    $office = $this->app->make(Office::class);

    $readOk = $office->handle($viewerCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $doc->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p1'],
    ]));
    expect($readOk->ok)->toBeTrue();

    $describeDenied = $office->handle($viewerCtx, new OfficeRequest(1, OfficeOperation::Describe, (object) [
        'resource_id' => $doc->id,
        'command' => 'text.replace_exact@1',
    ]));
    expect($describeDenied->ok)->toBeFalse();
    expect($describeDenied->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    $this->app->instance(CallContextResolver::class, new class($viewerCtx) implements CallContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(Request $request): ?CallContext
        {
            return $this->ctx;
        }
    });

    $httpDescribe = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.describe',
        'input' => ['resource_id' => $doc->id, 'command' => 'text.replace_exact@1'],
    ], JSON_THROW_ON_ERROR));
    $httpDescribe->assertStatus(404);
    expect($httpDescribe->json('error.code'))->toBe('resource_unavailable');
});

test('search applies candidate cap to authorized body matches rather than truncating traversal early', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');
    Config::set('vaulter.ids.host_keys', 'bigint');

    $actor = TestActor::query()->create(['name' => 'Searcher', 'email' => 'searcher@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Search']);
    $drive = Vaulter::drive($owner)->asSystem('seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));

    // Seed 9 visible non-matching documents before the matching 10th document, plus 2 restricted matching docs.
    for ($i = 1; $i <= 9; $i++) {
        Documents::in($drive)->create(sprintf('A-%02d Обычный файл', $i), 'vaulter/rich-text', [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p1'],
                'content' => [['type' => 'text', 'text' => 'Обычный текст без совпадения']],
            ]],
        ]);
    }

    $secretFolder = $drive->folders()->create('Hidden');
    $drive->access()->restrictInheritance($secretFolder->id, InheritanceRestriction::Empty);
    for ($i = 1; $i <= 2; $i++) {
        Documents::in($drive)->create("Secret {$i}", 'vaulter/rich-text', [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p1'],
                'content' => [['type' => 'text', 'text' => 'Скрытый эксклюзивный термин']],
            ]],
        ], $secretFolder->id);
    }

    $tenth = Documents::in($drive)->create('Z-10 Целевой документ', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'Содержит эксклюзивный термин только в теле']],
        ]],
    ]);

    Config::set('vaulter-ai.limits.search_max_candidates', 1);

    $office = $this->app->make(Office::class);
    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $drive->snapshot()->id, $actor);

    $res = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'эксклюзивный',
        'resource_type' => 'text',
        'limit' => 5,
    ]));
    expect($res->ok)->toBeTrue();
    expect(array_map(static fn (stdClass $i): string => $i->resource_id, $res->result?->items ?? []))->toBe([$tenth->id]);

    // Adding a second visible authorized match exceeds search_max_candidates=1 cleanly.
    Documents::in($drive)->create('Z-11 Второй матч', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'Ещё один эксклюзивный термин']],
        ]],
    ]);

    $tooMany = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) [
        'query' => 'эксклюзивный',
        'resource_type' => 'text',
        'limit' => 5,
    ]));
    expect($tooMany->ok)->toBeFalse();
    expect($tooMany->errorCode)->toBe(ErrorCode::LimitExceeded);
});

test('HTTP enforces byte limit on the exact UTF-8 envelope sent over HTTP for Cyrillic text', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '61');

    $actor = TestActor::query()->create(['name' => 'Cyrillic Reader', 'email' => 'cyrillic@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org Cyrillic']);
    $drive = Vaulter::drive($owner)->asSystem('seed');
    $drive->access()->grant($drive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::READER));

    $doc = Documents::in($drive)->create('Кириллица', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p-4k'],
                'content' => [['type' => 'text', 'text' => str_repeat('Б', 4000)]],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'p-9k'],
                'content' => [['type' => 'text', 'text' => str_repeat('Б', 9000)]],
            ],
        ],
    ]);

    $ctx = new CallContext('61', 'default', 'user/'.$actor->getKey(), 'documents', '1', $drive->snapshot()->id, $actor);
    $this->app->instance(CallContextResolver::class, new class($ctx) implements CallContextResolver
    {
        public function __construct(private readonly CallContext $ctx) {}

        public function resolve(Request $request): ?CallContext
        {
            return $this->ctx;
        }
    });

    $okResp = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.read',
        'input' => ['resource_id' => $doc->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p-4k']],
    ], JSON_THROW_ON_ERROR));
    $okResp->assertStatus(200);
    $rawOk = (string) $okResp->getContent();
    expect(strlen($rawOk))->toBeLessThanOrEqual(16384);
    expect($rawOk)->toContain('ББББ');

    $officeDb = $this->app->make(OfficeDatabase::class);
    $tokensBefore = $officeDb->query('ai_read_tokens')->count();

    $tooLarge = $this->call('POST', '/api/vaulter-ai/v1/operations', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
        'version' => 1,
        'operation' => 'office.read',
        'input' => ['resource_id' => $doc->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p-9k']],
    ], JSON_THROW_ON_ERROR));
    $tooLarge->assertStatus(413);
    expect($tooLarge->json('error.code'))->toBe('limit_exceeded');
    expect($officeDb->query('ai_read_tokens')->count())->toBe($tokensBefore);

    // Search snapshot creation uses VaulterTransactionRunner and propagates tenant context inside PG transaction.
    expect($this->app->make(TransactionRunner::class))->toBeInstanceOf(VaulterTransactionRunner::class);
    if ($officeDb->driver() === 'pgsql') {
        $vDb = $this->app->make(VaulterDatabase::class);
        $observedTenant = null;
        $conn = $vDb->connection();
        $conn->listen(function (QueryExecuted $query) use ($conn, &$observedTenant): void {
            if ($observedTenant === null && str_contains($query->sql, 'ai_search_pages') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                $observedTenant = $conn->selectOne("SELECT NULLIF(current_setting('vaulter.tenant_id', true), '') AS t")?->t;
            }
        });

        $office = $this->app->make(Office::class);
        $search = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Search, (object) ['query' => 'Кириллица', 'limit' => 5]));
        expect($search->ok)->toBeTrue();
        expect($observedTenant)->toBe('61');
    }
});
