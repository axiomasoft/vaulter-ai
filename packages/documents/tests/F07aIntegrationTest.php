<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\ContentProtectionPolicy;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Enums\ResourceType;
use Vaulter\Ai\Core\Schema\EditorContextSchema;
use Vaulter\Ai\Core\Workspace\ChatTools;
use Vaulter\Ai\Documents\Read\TextReader;
use Vaulter\Ai\LaravelAi\OfficeToolSchema;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Support\Identity;

uses(OfficeTestCase::class);

function createDocumentSuggestionsSchema(VaulterDatabase $db): void
{
    $conn = $db->connection();
    $schema = $conn->getSchemaBuilder();
    if ($schema->hasTable('v_document_suggestions')) {
        return;
    }

    $driver = $conn->getDriverName();
    $schema->create('v_document_suggestions', function (Blueprint $table) use ($driver): void {
        if ($driver === 'pgsql') {
            $table->uuid('id')->primary();
            $table->string('tenant_id', 64);
            $table->uuid('document_id');
            $table->uuid('group_id');
            $table->uuid('proposal_id')->nullable();
        } else {
            $table->string('id', 36)->primary();
            $table->string('tenant_id', 64);
            $table->string('document_id', 36);
            $table->string('group_id', 36);
            $table->string('proposal_id', 36)->nullable();
        }
        $table->bigInteger('generation');
        $table->bigInteger('base_position');
        $table->integer('schema_version');
        $table->string('author_subject', 255);
        $table->jsonb('provenance_json')->nullable();
        $table->string('kind', 32);
        $table->string('target_block_id', 255);
        $table->jsonb('target_selector');
        $table->jsonb('payload');
        $table->string('state', 16)->default('open');
        $table->string('decided_by_subject', 255)->nullable();
        $table->timestampTz('decided_at')->nullable();
        if ($driver === 'pgsql') {
            $table->uuid('receipt_id')->nullable();
        } else {
            $table->string('receipt_id', 36)->nullable();
        }
        $table->integer('version')->default(1);
        $table->timestampsTz();
        $table->index('proposal_id', 'v_document_suggestions_proposal_idx');
    });

    if ($driver === 'pgsql') {
        $conn->statement("ALTER TABLE v_document_suggestions ADD CONSTRAINT v_document_suggestions_state_chk CHECK ((state = 'open' AND decided_by_subject IS NULL AND decided_at IS NULL AND receipt_id IS NULL) OR (state = 'accepted' AND decided_by_subject IS NOT NULL AND decided_at IS NOT NULL AND receipt_id IS NOT NULL) OR (state IN ('rejected', 'superseded') AND decided_at IS NOT NULL AND receipt_id IS NULL))");
    } else {
        $conn->statement("CREATE TRIGGER v_document_suggestions_state_chk BEFORE UPDATE ON v_document_suggestions WHEN NOT ((NEW.state = 'open' AND NEW.decided_by_subject IS NULL AND NEW.decided_at IS NULL AND NEW.receipt_id IS NULL) OR (NEW.state = 'accepted' AND NEW.decided_by_subject IS NOT NULL AND NEW.decided_at IS NOT NULL AND NEW.receipt_id IS NOT NULL) OR (NEW.state IN ('rejected', 'superseded') AND NEW.decided_at IS NOT NULL AND NEW.receipt_id IS NULL)) BEGIN SELECT RAISE(ABORT, 'Invalid suggestion decision state'); END");
    }
}

test('ContentProtectionPolicy enforces hidden on read/chat and read_only on prepare/commit + OfficeToolSchema', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '701');

    $actor = TestActor::query()->create(['name' => 'Writer', 'email' => 'f07a-writer@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org 701']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    $doc = [
        'type' => 'doc',
        'content' => [
            [
                'type' => 'heading',
                'attrs' => ['blockId' => 'b_secret', 'level' => 6],
                'content' => [['type' => 'text', 'text' => 'Top secret clause']],
            ],
            [
                'type' => 'blockquote',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'attrs' => ['blockId' => 'b_readonly'],
                        'content' => [['type' => 'text', 'text' => 'Locked clause 15 days']],
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'attrs' => ['blockId' => 'b_open'],
                'content' => [['type' => 'text', 'text' => 'Editable clause 10 days']],
            ],
        ],
    ];

    $node = Documents::in($acting)->create('Protected Doc', 'vaulter/rich-text', $doc);
    $policy = ContentProtectionPolicy::fromArray([
        'rules' => [
            ['mode' => 'hidden', 'attribute' => 'level', 'value' => 6],
            ['mode' => 'read_only', 'node_type' => 'blockquote'],
        ],
    ]);

    $ctx = new CallContext(
        tenantId: '701',
        projectScope: 'default',
        effectiveSubject: 'user/'.$actor->getKey(),
        profile: 'documents',
        policyRevision: '1',
        driveId: $systemDrive->snapshot()->id,
        actor: $actor,
        attributes: ['content_protection' => $policy],
    );

    $office = $this->app->make(Office::class);

    // 1. TextReader text_outline omits hidden block and keeps read_only + editable blocks.
    $outline = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_outline'],
    ]));
    expect($outline->ok)->toBeTrue();
    $outlineIds = array_map(static fn (object $b): string => (string) $b->block_id, $outline->result->data->blocks);
    expect($outlineIds)->toBe(['b_readonly', 'b_open']);

    // 2. TextReader text_block rejects hidden block, allows read_only block.
    $hiddenRead = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'b_secret'],
    ]));
    expect($hiddenRead->ok)->toBeFalse()
        ->and($hiddenRead->errorCode)->toBe(ErrorCode::ResourceUnavailable);

    $roRead = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'b_readonly'],
    ]));
    expect($roRead->ok)->toBeTrue();
    $roToken = (string) $roRead->result->read_token;

    // 3. Prepare rejects read_only block even with valid read_token.
    $roPrepare = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => $roToken,
        'arguments' => (object) [
            'block_id' => 'b_readonly',
            'expected' => '15 days',
            'replacement' => '30 days',
        ],
    ]));
    expect($roPrepare->ok)->toBeFalse()
        ->and($roPrepare->errorCode)->toBe(ErrorCode::UnsupportedContent);

    // 4. TOCTOU: Prepare on editable block succeeds, then tightening policy before Commit rejects on server!
    $openRead = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'b_open'],
    ]));
    expect($openRead->ok)->toBeTrue();
    $openPrep = $office->handle($ctx, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $openRead->result->read_token,
        'arguments' => (object) [
            'block_id' => 'b_open',
            'expected' => '10 days',
            'replacement' => '20 days',
        ],
    ]));
    expect($openPrep->ok)->toBeTrue();

    $strictPolicy = ContentProtectionPolicy::fromArray(['read_only_types' => ['paragraph']]);
    $strictCtx = new CallContext(
        tenantId: '701',
        projectScope: 'default',
        effectiveSubject: 'user/'.$actor->getKey(),
        profile: 'documents',
        policyRevision: '1',
        driveId: $systemDrive->snapshot()->id,
        actor: $actor,
        attributes: ['content_protection' => $strictPolicy],
    );
    $cmdId = 'cmd-'.Str::uuid7();
    $this->app->make(ResolveProposal::class)->resolve(
        new CommandScope($ctx, $node->id, $cmdId),
        (string) $openPrep->result->proposal_id,
        (string) $openPrep->result->digest,
        $ctx,
        true,
    );
    $commit = $office->handle($strictCtx, new OfficeRequest(1, OfficeOperation::Commit, (object) [
        'resource_id' => $node->id,
        'proposal_id' => (string) $openPrep->result->proposal_id,
        'digest' => (string) $openPrep->result->digest,
        'command_id' => $cmdId,
    ]));
    expect($commit->ok)->toBeFalse()
        ->and($commit->errorCode)->toBe(ErrorCode::UnsupportedContent);

    // 5. ChatTools::catalog & OfficeToolSchema propagate protection rules.
    $catalog = $this->app->make(ChatTools::class)->catalog($ctx, [$node->id]);
    expect($catalog['office_prepare']['content_protection']['summary'])->toContain('level')
        ->and($catalog['office_read']['editor_context_contract']['version'])->toBe(EditorContextSchema::VERSION);

    $schemaFactory = new JsonSchemaTypeFactory;
    $toolFields = OfficeToolSchema::forOperation(OfficeOperation::Prepare, $schemaFactory, $policy);
    $toolDesc = OfficeToolSchema::descriptionFor(OfficeOperation::Prepare, 'text.replace_exact@1', $policy);
    expect($toolFields)->toHaveKey('content_protection_note')
        ->and($toolDesc)->toContain('Content protection:');
});

test('EditorContextSchema strips DOM hooks and TextReader::editorContext filters hidden nodes', function (): void {
    $policy = ContentProtectionPolicy::fromArray([
        'hidden_attributes' => [['attribute' => 'level', 'values' => [6]]],
        'read_only_types' => ['heading'],
    ]);

    $custom = EditorContextSchema::fromSpecs(
        nodeSpecs: [
            'paragraph' => [
                'group' => 'block',
                'content' => 'inline*',
                'parseDOM' => [['tag' => 'p']],
                'toDOM' => static fn (): array => ['p', 0],
                'attrs' => [
                    'blockId' => ['type' => 'string', 'nullable' => false],
                ],
            ],
            'heading' => [
                'group' => 'block',
                'attrs' => ['level' => ['type' => 'integer', 'default' => 1]],
            ],
        ],
        markSpecs: [
            'bold' => [
                'parseHTML' => [['tag' => 'strong']],
                'renderHTML' => static fn (): array => ['strong', 0],
            ],
        ],
        protection: $policy,
    );

    $arr = $custom->toArray();
    expect($arr['version'])->toBe(EditorContextSchema::VERSION)
        ->and($arr['serializedSchema']['nodes']['paragraph'])->not->toHaveKey('parseDOM')
        ->and($arr['serializedSchema']['nodes']['paragraph'])->not->toHaveKey('toDOM')
        ->and($arr['serializedSchema']['nodes']['heading']['protection'])->toBe('read_only')
        ->and($arr['serializedSchema']['marks']['bold'])->not->toHaveKey('parseHTML')
        ->and($arr['serializedSchema']['marks']['bold'])->not->toHaveKey('renderHTML');

    Config::set('vaulter-ai-documents.default_tenant_id', '702');
    $actor = TestActor::query()->create(['name' => 'Reader', 'email' => 'f07a-reader@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org 702']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $acting = $systemDrive->actingAs($actor);

    $node = Documents::in($acting)->create('Context Doc', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h1', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Title']]],
            ['type' => 'heading', 'attrs' => ['blockId' => 'p_hide', 'level' => 6], 'content' => [['type' => 'text', 'text' => 'Secret']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p_show'], 'content' => [['type' => 'text', 'text' => 'Public']]],
        ],
    ]);

    $ctx = new CallContext('702', 'default', 'user/'.$actor->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $actor);
    $entry = new ResourceEntry($node->id, ResourceType::Text, 'Context Doc', driveId: $systemDrive->snapshot()->id);
    $editorContext = $this->app->make(TextReader::class)->editorContext($ctx, $entry, $policy)->toArray();

    expect(array_column($editorContext['items'], 'block_id'))->toBe(['h1', 'p_show'])
        ->and($editorContext['items'][0]['protection'])->toBe('read_only')
        ->and($editorContext['serializedSchema']['nodes'])->toHaveKey('paragraph');
});

test('ProposalSuggestionBridge syncs v_ai_proposals with v_document_suggestions on prepare, accept/commit, and reject', function (): void {
    Config::set('vaulter-ai-documents.default_tenant_id', '703');
    createDocumentSuggestionsSchema($this->app->make(VaulterDatabase::class));

    $actor = TestActor::query()->create(['name' => 'Author', 'email' => 'f07a-author@example.test']);
    $approver = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'f07a-reviewer@example.test']);
    $owner = TestOwner::query()->create(['name' => 'Org 703']);
    $systemDrive = Vaulter::drive($owner)->asSystem('seed');
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($actor), RoleRegistry::WRITER));
    $systemDrive->access()->grant($systemDrive->root()->id, new GrantInput(Subject::ofModel($approver), RoleRegistry::OWNER));
    $acting = $systemDrive->actingAs($actor);

    $node = Documents::in($acting)->create('Contract', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'Pay in 15 days and ship in 5 days.']],
        ]],
    ]);
    $snap = Documents::in($acting)->content($node->id);
    $docId = Identity::key($snap->documentId) ?? $snap->documentId;

    $initiatorCtx = new CallContext('703', 'default', 'user/'.$actor->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $actor);
    $approverCtx = new CallContext('703', 'default', 'user/'.$approver->getKey(), 'documents', '1', $systemDrive->snapshot()->id, $approver);

    $office = $this->app->make(Office::class);
    $resolve = $this->app->make(ResolveProposal::class);
    $db = $this->app->make(VaulterDatabase::class)->connection();

    // Proposal A: prepare -> open suggestion -> approve & commit -> accepted with receipt_id
    $readA = $office->handle($initiatorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p1'],
    ]));
    $prepA = $office->handle($initiatorCtx, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $readA->result->read_token,
        'arguments' => (object) ['block_id' => 'p1', 'expected' => '15 days', 'replacement' => '30 days'],
    ]));
    expect($prepA->ok)->toBeTrue();
    $proposalA = (string) $prepA->result->proposal_id;

    $suggA = $db->table('v_document_suggestions')->where('proposal_id', $proposalA)->first();
    expect($suggA)->not->toBeNull()
        ->and($suggA->state)->toBe('open')
        ->and((string) $suggA->document_id)->toBe((string) $docId)
        ->and($suggA->target_block_id)->toBe('p1');

    $resolve->resolve(new CommandScope($initiatorCtx, $node->id, 'cmd-'.$proposalA), $proposalA, (string) $prepA->result->digest, $approverCtx, true);
    $commitA = $office->handle($initiatorCtx, new OfficeRequest(1, OfficeOperation::Commit, (object) [
        'resource_id' => $node->id,
        'proposal_id' => $proposalA,
        'digest' => (string) $prepA->result->digest,
        'command_id' => 'cmd-'.$proposalA,
    ]));
    expect($commitA->ok)->toBeTrue();

    $suggAAfter = $db->table('v_document_suggestions')->where('proposal_id', $proposalA)->first();
    expect($suggAAfter->state)->toBe('accepted')
        ->and($suggAAfter->receipt_id)->toBe((string) $commitA->result->receipt_id)
        ->and((int) $suggAAfter->version)->toBe(2);

    // Proposal B: prepare -> reject -> rejected with decided_at & null receipt_id
    $readB = $office->handle($initiatorCtx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $node->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p1'],
    ]));
    $prepB = $office->handle($initiatorCtx, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $readB->result->read_token,
        'arguments' => (object) ['block_id' => 'p1', 'expected' => '5 days', 'replacement' => '2 days'],
    ]));
    expect($prepB->ok)->toBeTrue();
    $proposalB = (string) $prepB->result->proposal_id;

    $resolve->resolve(new CommandScope($initiatorCtx, $node->id, 'cmd-'.$proposalB), $proposalB, (string) $prepB->result->digest, $approverCtx, false);
    $suggBAfter = $db->table('v_document_suggestions')->where('proposal_id', $proposalB)->first();
    expect($suggBAfter->state)->toBe('rejected')
        ->and($suggBAfter->receipt_id)->toBeNull()
        ->and($suggBAfter->decided_at)->not->toBeNull()
        ->and((int) $suggBAfter->version)->toBe(2);
});
