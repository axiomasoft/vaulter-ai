<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\NextAction;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Protocol\WireValidator;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
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
