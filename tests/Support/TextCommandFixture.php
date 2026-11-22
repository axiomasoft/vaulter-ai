<?php

declare(strict_types=1);

namespace Vaulter\Ai\Tests\Support;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Documents\Data\Snapshots\DocumentSnapshot;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Facades\Vaulter;

final readonly class TextCommandFixture
{
    public CallContext $context;

    public TestActor $actor;

    public DriveHandle $drive;

    public NodeSnapshot $node;

    private string $grantId;

    public function __construct(private Application $app, bool $automatic = true, ?array $seed = null)
    {
        Config::set('vaulter-ai-documents.default_tenant_id', '61');
        Config::set('vaulter-ai.profiles.documents.approval', $automatic ? 'automatic' : 'human');

        $this->actor = TestActor::query()->create(['name' => 'Editor', 'email' => 'editor@example.test']);
        $owner = TestOwner::query()->create(['name' => 'Workspace']);
        $this->drive = Vaulter::drive($owner)->asSystem('seed-test');
        $grant = $this->drive->access()->grant($this->drive->root()->id, new GrantInput(Subject::ofModel($this->actor), RoleRegistry::WRITER));
        $this->grantId = $grant->id;
        $segments = $seed['p42_segments'] ?? [
            ['text' => '😀 Pay within ', 'marks' => []],
            ['text' => '15', 'marks' => ['bold']],
            ['text' => ' working days.', 'marks' => []],
        ];
        $this->node = Documents::in($this->drive)->create('Договор поставки', 'vaulter/rich-text', [
            'type' => 'doc',
            'content' => [
                self::block('heading', 'h-calc', [['type' => 'text', 'text' => 'Расчёты']], 1),
                self::block('heading', 'h-42', [['type' => 'text', 'text' => '4.2 Срок оплаты']], 2),
                self::block('paragraph', 'p42', array_map(static fn (array $segment): array => [
                    'type' => 'text', 'text' => $segment['text'],
                    'marks' => array_map(static fn (string $mark): array => ['type' => $mark], $segment['marks']),
                ], $segments)),
                self::block('heading', 'h-claims', [['type' => 'text', 'text' => 'Претензии']], 1),
                self::block('heading', 'h-71', [['type' => 'text', 'text' => '7.1']], 2),
                self::block('paragraph', 'p71', [['type' => 'text', 'text' => $seed['p71_text'] ?? 'Human terms.']]),
            ],
        ]);
        $this->context = new CallContext('61', 'default', 'user/'.$this->actor->getKey(), driveId: $this->drive->snapshot()->id, actor: $this->actor);
        if ($seed !== null) {
            $this->advanceTo($seed['marker']);
        }
    }

    public function call(OfficeOperation $operation, array $input, ?CallContext $context = null): OfficeResponse
    {
        return $this->app->make(Office::class)->handle(
            $context ?? $this->context, new OfficeRequest(1, $operation, (object) (['resource_id' => $this->node->id] + $input)),
        );
    }

    public function authorizeHttp(): void
    {
        $this->app->instance(CallContextResolver::class, new class($this->context) implements CallContextResolver
        {
            public function __construct(private readonly CallContext $context) {}

            public function resolve(Request $request): ?CallContext
            {
                return $request->bearerToken() === 'scenario-test-token' ? $this->context : null;
            }
        });
    }

    public function prepare(string $expected = '15', string $replacement = '30', string $blockId = 'p42'): object
    {
        $read = $this->call(OfficeOperation::Read, ['selector' => (object) ['kind' => 'text_block', 'block_id' => $blockId]]);
        expect($read->ok)->toBeTrue();
        $proposal = $this->call(OfficeOperation::Prepare, [
            'command' => 'text.replace_exact@1',
            'read_token' => $read->result->read_token,
            'arguments' => (object) ['block_id' => $blockId, 'expected' => $expected, 'replacement' => $replacement],
        ]);
        expect($proposal->errorCode)->toBeNull();

        return $proposal->result;
    }

    public function commitInput(object $proposal, string $key = 'edit-1'): array
    {
        return ['proposal_id' => $proposal->proposal_id, 'digest' => $proposal->digest, 'command_id' => $key];
    }

    public function revoke(): void
    {
        $this->drive->access()->revoke($this->drive->root()->id, $this->grantId);
    }

    public function snapshot(): DocumentSnapshot
    {
        return Documents::in($this->drive)->content($this->node);
    }

    public function edit(string $id, callable $transform): DocumentSnapshot
    {
        $state = $this->snapshot();
        $document = $state->content->toArray();
        foreach ($document['content'] as &$node) {
            if ($node['attrs']['blockId'] === $id) {
                $node['content'] = $transform($node['content']);
            }
        }
        unset($node);

        return Documents::in($this->drive)->update($this->node, $document, $state->stateVersion);
    }

    private static function block(string $type, string $id, array $content, ?int $level = null): array
    {
        return [
            'type' => $type,
            'attrs' => ['blockId' => $id] + ($level === null ? [] : ['level' => $level]),
            'content' => $content,
        ];
    }

    private function advanceTo(array $marker): void
    {
        $documents = Documents::in($this->drive);
        $state = $this->snapshot();
        $revision = $state->headRevisionId;
        for ($epoch = $state->epoch; $epoch < $marker['generation']; $epoch++) {
            $state = $documents->restore($this->node, $revision, $state->stateVersion);
        }
        $stable = $state->content->toArray();
        while ($state->stateVersion < $marker['position']) {
            $content = $stable;
            if ($state->stateVersion + 1 < $marker['position']) {
                $content['content'][5]['content'][0]['text'] .= ' (draft '.$state->stateVersion.')';
            }
            $state = $documents->update($this->node, $content, $state->stateVersion);
        }
        expect([$state->epoch, $state->stateVersion])->toBe([$marker['generation'], $marker['position']]);
        expect($state->content->toArray())->toEqual($stable);
    }
}
