<?php

declare(strict_types=1);

use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Operations\StepCommitCoordinator;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;

uses(LaravelAiTestCase::class);

/**
 * The coordinator is the only path by which a persisted run commits. Its contract: a
 * commit step is bound to exactly one (resource, proposal, digest, command_id), retries
 * reuse the saved command_id, and anything that would rebind it fails closed with
 * idempotency_conflict before the owner is called.
 */
function stepCommitSetup(TextCommandFixture $fixture, string $event): array
{
    $runs = app(Runs::class);

    return [
        $runs,
        app(StepCommitCoordinator::class),
        $runs->createOrFind($fixture->context, 'laravel-ai', $event, 'rev-1', ['prompt' => 'edit']),
    ];
}

function stepCommitOwnerWrites(): int
{
    return app(VaulterDatabase::class)->connection()->table('v_ai_text_commands')->count();
}

function stepCommitRejectTarget(TextCommandFixture $fixture): void
{
    $fixture->edit('p42', static function (array $children): array {
        $children[1]['text'] = '20';

        return $children;
    });
}

test('a new commit step with a stale digest fails before a step is recorded', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    [$runs, $coordinator, $run] = stepCommitSetup($fixture, 'evt-stale-digest');
    $proposal = $fixture->prepare('15', '30', 'p42');

    $result = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $proposal->proposal_id,
        digest: str_repeat('0', 64),
    );

    expect($result['step'])->toBeNull()
        ->and($result['response']->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($runs->listSteps($fixture->context, $run->runId))->toBe([])
        ->and(stepCommitOwnerWrites())->toBe(0);
});

test('a retry may not rebind a saved step, route, or command_id to another command', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    [$runs, $coordinator, $run] = stepCommitSetup($fixture, 'evt-rebind');
    $first = $fixture->prepare('15', '30', 'p42');
    $second = $fixture->prepare('Human', 'Automated', 'p71');

    $committed = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $first->proposal_id,
        digest: $first->digest,
        preferredCommandId: 'cmd-rebind-1',
    );
    expect($committed['response']->ok)->toBeTrue()
        ->and($committed['step']?->state)->toBe('finished');

    // Same run, same proposal, different command id: the saved step wins.
    $sameRun = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $first->proposal_id,
        digest: $first->digest,
        preferredCommandId: 'cmd-rebind-2',
    );
    expect($sameRun['response']->errorCode)->toBe(ErrorCode::IdempotencyConflict)
        ->and($sameRun['step']?->stepId)->toBe($committed['step']?->stepId);

    // Same run and proposal but another digest.
    $otherDigest = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $first->proposal_id,
        digest: $second->digest,
    );
    expect($otherDigest['response']->errorCode)->toBe(ErrorCode::IdempotencyConflict);

    // Another run: the proposal's owner route already carries cmd-rebind-1.
    [, , $otherRun] = stepCommitSetup($fixture, 'evt-rebind-other-run');
    $otherRoute = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $otherRun->runId,
        resourceId: $fixture->node->id,
        proposalId: $first->proposal_id,
        digest: $first->digest,
        preferredCommandId: 'cmd-rebind-3',
    );
    expect($otherRoute['step'])->toBeNull()
        ->and($otherRoute['response']->errorCode)->toBe(ErrorCode::IdempotencyConflict);

    // Another proposal may not reuse a command id already bound to the first one.
    $stolenCommand = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $otherRun->runId,
        resourceId: $fixture->node->id,
        proposalId: $second->proposal_id,
        digest: $second->digest,
        preferredCommandId: 'cmd-rebind-1',
    );
    expect($stolenCommand['step'])->toBeNull()
        ->and($stolenCommand['response']->errorCode)->toBe(ErrorCode::IdempotencyConflict)
        ->and($runs->listSteps($fixture->context, $otherRun->runId))->toBe([])
        ->and(stepCommitOwnerWrites())->toBe(1);
});

test('an owner rejection fails the step and a later resume reports it from office.status without recommitting', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    [$runs, $coordinator, $run] = stepCommitSetup($fixture, 'evt-owner-rejects');
    $proposal = $fixture->prepare('15', '30', 'p42');
    stepCommitRejectTarget($fixture);
    $version = $fixture->snapshot()->stateVersion;

    $rejected = $coordinator->executeOrResume(
        context: $fixture->context,
        runId: $run->runId,
        resourceId: $fixture->node->id,
        proposalId: $proposal->proposal_id,
        digest: $proposal->digest,
        preferredCommandId: 'cmd-owner-rejects',
    );
    $step = $rejected['step'];
    expect($rejected['response']->ok)->toBeFalse()
        ->and($rejected['response']->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($step?->state)->toBe('failed')
        ->and($step?->safeError)->toBe(ErrorCode::PreconditionFailed->value);

    $resumed = $coordinator->resumeStep($fixture->context, $run->runId, $step->stepId);

    expect($resumed['step']->stepId)->toBe($step->stepId)
        ->and($resumed['step']->state)->toBe('failed')
        ->and($resumed['step']->commandId)->toBe('cmd-owner-rejects')
        ->and($resumed['step']->safeError)->toBe(ErrorCode::PreconditionFailed->value)
        ->and($resumed['response']->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($fixture->snapshot()->stateVersion)->toBe($version)
        ->and(stepCommitOwnerWrites())->toBe(1);
});

test('resumeStep refuses a step that is not a commit or does not exist', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    [$runs, $coordinator, $run] = stepCommitSetup($fixture, 'evt-resume-refused');
    $prepareStep = $runs->recordStep(
        context: $fixture->context,
        runId: $run->runId,
        operation: OfficeOperation::Prepare,
        state: 'finished',
        resourceId: $fixture->node->id,
    );

    foreach ([$prepareStep->stepId, $prepareStep->stepId + 100] as $stepId) {
        expect(static fn () => $coordinator->resumeStep($fixture->context, $run->runId, $stepId))
            ->toThrow(static fn (OfficeException $e) => expect($e->errorCode)->toBe(ErrorCode::ResourceUnavailable));
    }
});

test('a standalone commit replay reads the owner outcome instead of committing again', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    $coordinator = app(StepCommitCoordinator::class);
    $accepted = $fixture->prepare('Human', 'Automated', 'p71');
    $rejected = $fixture->prepare('15', '30', 'p42');

    $first = $coordinator->executeStandaloneCommit($fixture->context, $fixture->node->id, $accepted->proposal_id, $accepted->digest, 'cmd-standalone-ok');
    expect($first->ok)->toBeTrue();
    $replay = $coordinator->executeStandaloneCommit($fixture->context, $fixture->node->id, $accepted->proposal_id, $accepted->digest);
    expect($replay->ok)->toBeTrue()
        ->and($replay->result->receipt_id)->toBe($first->result->receipt_id);

    expect($coordinator->executeStandaloneCommit($fixture->context, $fixture->node->id, $accepted->proposal_id, $accepted->digest, 'cmd-standalone-other')->errorCode)
        ->toBe(ErrorCode::IdempotencyConflict)
        ->and($coordinator->executeStandaloneCommit($fixture->context, 'other-resource', $accepted->proposal_id, $accepted->digest)->errorCode)
        ->toBe(ErrorCode::IdempotencyConflict);

    stepCommitRejectTarget($fixture);
    $refused = $coordinator->executeStandaloneCommit($fixture->context, $fixture->node->id, $rejected->proposal_id, $rejected->digest, 'cmd-standalone-rejected');
    expect($refused->errorCode)->toBe(ErrorCode::PreconditionFailed);
    $refusedReplay = $coordinator->executeStandaloneCommit($fixture->context, $fixture->node->id, $rejected->proposal_id, $rejected->digest);
    expect($refusedReplay->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and(stepCommitOwnerWrites())->toBe(2);
});
