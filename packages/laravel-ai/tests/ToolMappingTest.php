<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Registry\OfficeRegistry;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Ai\LaravelAi\OfficeTool;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

uses(LaravelAiTestCase::class);

test('LaravelOfficeExecutor builds all seven normalized tools from one catalog and operates cleanly without AgenTools', function (): void {
    $registry = $this->app->make(OfficeRegistry::class);
    expect($registry->executor('laravel-ai'))->toBeInstanceOf(LaravelOfficeExecutor::class)
        ->and($registry->executor('agentools'))->toBeNull();

    $fixture = new TextCommandFixture($this->app, automatic: true);
    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $tools = $executor->toolsFor($fixture->context);

    expect($tools)->toHaveCount(7);

    $factory = new JsonSchemaTypeFactory;
    $mappedNames = [];
    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(OfficeTool::class);
        $resolvedName = ToolNameResolver::resolve($tool);
        $mappedNames[] = $resolvedName;
        expect(OfficeOperation::fromToolName($resolvedName))->toBe($tool->operation());

        $schema = $tool->schema($factory);
        expect($schema)->toBeArray()->not->toBeEmpty();
        $jsonSchema = JsonSchema::object(static fn (): array => $schema)->toArray();
        expect($jsonSchema['type'] ?? null)->toBe('object')
            ->and($jsonSchema['properties'] ?? null)->toBeArray();
    }

    expect($mappedNames)->toBe([
        'office_search',
        'office_read',
        'office_describe',
        'office_prepare',
        'office_commit',
        'office_status',
        'office_verify',
    ]);

    expect(OfficeOperation::fromToolName('office_unknown'))->toBeNull()
        ->and(OfficeOperation::fromToolName('office.search'))->toBe(OfficeOperation::Search);
});

test('OfficeTool rejects caller-authority injection and dynamically picks up policy revision changes', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-1',
    ]);

    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $tools = [];
    foreach ($executor->toolsFor($fixture->context) as $tool) {
        $tools[$tool->name()] = $tool;
    }

    // Authority injection keys inside tool input are rejected cleanly.
    foreach ([
        ['office_search', ['query' => 'Договор', 'tenant_id' => '999']],
        ['office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_outline'], 'effective_subject' => 'user/other']],
        ['office_describe', ['resource_id' => $fixture->node->id, 'command' => 'text.replace_exact@1', 'actor' => 'admin']],
        ['office_prepare', ['resource_id' => $fixture->node->id, 'command' => 'text.replace_exact@1', 'read_token' => 'rt-x', 'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'], 'approval' => 'automatic']],
        ['office_commit', ['resource_id' => $fixture->node->id, 'proposal_id' => '019a0000-0000-7000-8000-000000000001', 'digest' => str_repeat('a', 64), 'policy_revision' => 'rev-override']],
    ] as [$toolName, $args]) {
        $decoded = json_decode($tools[$toolName]->handle(new Request($args)), true, 512, JSON_THROW_ON_ERROR);
        expect($decoded['ok'])->toBeFalse()
            ->and($decoded['error']['code'])->toBe('invalid_input');
    }

    // Read-only revision updates dynamically when HostPolicy config revision changes on the existing executor singleton.
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => [],
        'approval' => 'human',
        'revision' => 'rev-2',
    ]);

    $restrictedAgent = $executor->buildAgent($fixture->context);
    expect($restrictedAgent)->toBeInstanceOf(OfficeAgent::class)
        ->and($restrictedAgent->instructions())->toContain('revision rev-2')
        ->and($restrictedAgent->instructions())->toContain('Allowed write commands: none');

    $restrictedTools = [];
    foreach ($restrictedAgent->tools() as $tool) {
        /** @var OfficeTool $tool */
        $restrictedTools[$tool->name()] = $tool;
    }
    $denied = json_decode($restrictedTools['office_describe']->handle(new Request([
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
    ])), true, 512, JSON_THROW_ON_ERROR);
    expect($denied['ok'])->toBeFalse()
        ->and($denied['error']['code'])->toBe('resource_unavailable');

    // Enabling command again under rev-3 restores access.
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-3',
    ]);

    $reloadedAgent = $executor->buildAgent($fixture->context);
    expect($reloadedAgent->instructions())->toContain('revision rev-3')
        ->and($reloadedAgent->instructions())->toContain('text.replace_exact@1');

    $reloadedTools = [];
    foreach ($reloadedAgent->tools() as $tool) {
        /** @var OfficeTool $tool */
        $reloadedTools[$tool->name()] = $tool;
    }
    $allowed = json_decode($reloadedTools['office_describe']->handle(new Request([
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
    ])), true, 512, JSON_THROW_ON_ERROR);
    expect($allowed['ok'])->toBeTrue()
        ->and($allowed['result']['command'])->toBe('text.replace_exact@1');
});

test('untrusted user prompt instructions cannot widen ACL or reveal hidden documents', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);

    // Create a hidden sibling reader/other drive document inaccessible to the active actor.
    $foreignActor = TestActor::query()->create(['name' => 'Secret Owner', 'email' => 'secret@example.test']);
    $foreignDrive = Vaulter::drive($foreignActor)->asSystem('seed-hidden');
    $hiddenNode = Documents::in($foreignDrive)->create('Секретный контракт', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'attrs' => ['blockId' => 'p1'],
            'content' => [['type' => 'text', 'text' => 'TOP SECRET 999']],
        ]],
    ]);

    // Downgrade active actor on own fixture node to reader only.
    $fixture->revoke();
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::READER));

    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'automatic',
        'revision' => 'rev-acl',
    ]);
    Config::set('ai.providers.openai.key', 'sk-never-leak-into-agent');

    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $agent = $executor->buildAgent($fixture->context);
    expect($agent->instructions())->not->toContain('sk-never-leak-into-agent')
        ->and($agent->instructions())->toContain('never expand permissions');

    $tools = [];
    foreach ($agent->tools() as $tool) {
        /** @var OfficeTool $tool */
        $tools[$tool->name()] = $tool;
    }

    // Attempt reading hidden foreign resource even with injected tenant/subject keys.
    $readHidden = json_decode($tools['office_read']->handle(new Request([
        'resource_id' => $hiddenNode->id,
        'selector' => ['kind' => 'text_outline'],
    ])), true, 512, JSON_THROW_ON_ERROR);
    expect($readHidden['ok'])->toBeFalse()
        ->and($readHidden['error']['code'])->toBe('resource_unavailable');

    // Reader cannot prepare a write on own read-only document.
    $readOwn = json_decode($tools['office_read']->handle(new Request([
        'resource_id' => $fixture->node->id,
        'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
    ])), true, 512, JSON_THROW_ON_ERROR);
    expect($readOwn['ok'])->toBeTrue();

    $prepareWrite = json_decode($tools['office_prepare']->handle(new Request([
        'resource_id' => $fixture->node->id,
        'command' => 'text.replace_exact@1',
        'read_token' => $readOwn['result']['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ])), true, 512, JSON_THROW_ON_ERROR);
    expect($prepareWrite['ok'])->toBeFalse()
        ->and($prepareWrite['error']['code'])->toBe('resource_unavailable');

    // Neither document was mutated.
    expect(Documents::in($foreignDrive)->content($hiddenNode)->stateVersion)->toBe(1)
        ->and($fixture->snapshot()->stateVersion)->toBe(1);
});
