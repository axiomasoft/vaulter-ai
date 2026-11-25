<?php

declare(strict_types=1);

use AgenTools\Error\ToolExecutionResult;
use Illuminate\Support\Facades\Config;
use Vaulter\Ai\AgenTools\OfficeToolPort;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Tests\Support\AgenToolsTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(AgenToolsTestCase::class);

/*
 * The permission gates an agent hits through the tool port before anything reaches the office:
 * the profile's published commands, the installed command versions, the command's required
 * drive permission, and the re-check when a prepared step is replayed on resume.
 */

function toolPermissionCode(ToolExecutionResult $result): ?string
{
    return $result->isError() ? $result->error?->code : null;
}

function toolPermissionReader(TextCommandFixture $fixture): CallContext
{
    $reader = TestActor::query()->create(['name' => 'Reader', 'email' => 'reader@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($reader), RoleRegistry::READER));

    return new CallContext(
        $fixture->context->tenantId,
        $fixture->context->projectScope,
        'user/'.$reader->getKey(),
        driveId: $fixture->context->driveId,
        actor: $reader,
    );
}

test('a reader can read a document but cannot describe or prepare an edit command on it', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $port = $this->app->make(OfficeToolPort::class)->forContext(toolPermissionReader($fixture));
    $id = $fixture->node->id;

    $read = $port->call('office_read', ['resource_id' => $id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'k-read');
    expect(toolPermissionCode($read))->toBeNull();

    $describe = $port->call('office_describe', ['resource_id' => $id, 'command' => 'text.replace_exact@1'], 'k-describe');
    $prepare = $port->call('office_prepare', [
        'resource_id' => $id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ], 'k-prepare');

    expect(toolPermissionCode($describe))->toBe('vaulter.not_found.resource_unavailable')
        ->and(toolPermissionCode($prepare))->toBe('vaulter.not_found.resource_unavailable');
});

test('commands outside the profile or not installed are refused before the office is called', function (): void {
    $fixture = new TextCommandFixture($this->app);
    Config::set('vaulter-ai.profiles.documents.commands', ['text.replace_exact@1', 'text.replace_exact@9']);
    $port = $this->app->make(OfficeToolPort::class)->forContext($fixture->context);
    $describe = fn (string $command, string $key): ToolExecutionResult => $port->call('office_describe', [
        'resource_id' => $fixture->node->id,
        'command' => $command,
    ], $key);

    expect(toolPermissionCode($describe('text.replace_exact@1', 'k-ok')))->toBeNull()
        ->and(toolPermissionCode($describe('text.delete_block@1', 'k-unpublished')))->toBe('vaulter.permission.resource_unavailable')
        ->and(toolPermissionCode($describe('text.replace_exact@9', 'k-uninstalled')))->toBe('vaulter.not_found.resource_unavailable');
});

test('replaying a prepared step re-checks its arguments and the caller access', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $run = $this->app->make(Runs::class)->createOrFind($fixture->context, 'agentools', 'evt-replay', 'rev-1', ['prompt' => '']);
    $port = $this->app->make(OfficeToolPort::class)->forContext($fixture->context, $run->runId);
    $id = $fixture->node->id;
    $read = $port->call('office_read', ['resource_id' => $id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'k-read');
    $prepare = fn (string $replacement): ToolExecutionResult => $port->call('office_prepare', [
        'resource_id' => $id,
        'command' => 'text.replace_exact@1',
        'read_token' => (string) $read->value['read_token'],
        'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => $replacement],
    ], 'k-prepare');

    $first = $prepare('30');
    expect(toolPermissionCode($first))->toBeNull();

    // Same key and arguments: the stored proposal comes back instead of a second one.
    expect($prepare('30')->value['proposal_id'] ?? null)->toBe($first->value['proposal_id'])
        // Same key, other arguments: refused rather than silently returning the old proposal.
        ->and(toolPermissionCode($prepare('45')))->toBe('vaulter.conflict.idempotency_conflict');

    $fixture->revoke();
    expect(toolPermissionCode($prepare('30')))->toBe('vaulter.not_found.resource_unavailable');
});

test('a commit tool call without its proposal reference is rejected as invalid input', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $port = $this->app->make(OfficeToolPort::class)->forContext($fixture->context);

    expect(toolPermissionCode($port->call('office_commit', ['resource_id' => $fixture->node->id, 'digest' => 'd'], 'k-commit')))
        ->toBe('vaulter.validation.invalid_input');
});

test('replaying a prepared step under its key for another command is an idempotency conflict', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('vaulter-ai.profiles.documents.commands', ['text.replace_exact@1', 'text.delete_block@1']);
    $run = $this->app->make(Runs::class)->createOrFind($fixture->context, 'agentools', 'evt-replay-other', 'rev-1', ['prompt' => '']);
    $port = $this->app->make(OfficeToolPort::class)->forContext($fixture->context, $run->runId);
    $id = $fixture->node->id;
    $read = $port->call('office_read', ['resource_id' => $id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']], 'k-read');
    $arguments = ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'];
    $prepare = fn (string $command): ToolExecutionResult => $port->call('office_prepare', [
        'resource_id' => $id, 'command' => $command, 'read_token' => (string) $read->value['read_token'], 'arguments' => $arguments,
    ], 'k-prepare');

    expect(toolPermissionCode($prepare('text.replace_exact@1')))->toBeNull()
        ->and(toolPermissionCode($prepare('text.delete_block@1')))->toBe('vaulter.conflict.idempotency_conflict');
});
