<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;

uses(OfficeTestCase::class);

/*
 * Each guard of office.commit, checked against the document: a refused commit must leave the
 * stored state exactly as it was, and must not bind the command id to the refused attempt.
 */

test('a commit with a digest other than the prepared one is refused without a write', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $before = $fixture->snapshot();

    $input = ['digest' => str_repeat('0', strlen($proposal->digest))] + $fixture->commitInput($proposal);
    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($fixture->snapshot()->stateVersion)->toBe($before->stateVersion);

    // The refused attempt did not claim the command id: the right digest still commits under it.
    expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal))->errorCode)->toBeNull();
});

test('a proposal past its lifetime cannot be committed', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $before = $fixture->snapshot();

    $this->fixedClock->advanceSeconds((int) config('vaulter-ai.limits.proposal_ttl_seconds') + 1);

    expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal))->errorCode)->toBe(ErrorCode::ProposalExpired)
        ->and($fixture->snapshot()->stateVersion)->toBe($before->stateVersion);
});

test('changing the profile policy between prepare and commit invalidates open proposals', function (string $key, string $value): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $before = $fixture->snapshot();

    Config::set("vaulter-ai.profiles.documents.{$key}", $value);

    expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal))->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($fixture->snapshot()->stateVersion)->toBe($before->stateVersion);
})->with([
    'revision bumped' => ['revision', '2'],
    'approval switched to human' => ['approval', 'human'],
]);

test('replaying a commit after its idempotency window is refused instead of re-running', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $input = $fixture->commitInput($proposal);
    $first = $fixture->call(OfficeOperation::Commit, $input);
    expect($first->errorCode)->toBeNull();

    // Inside the window the same request replays the stored receipt.
    $replay = $fixture->call(OfficeOperation::Commit, $input);
    expect($replay->errorCode)->toBeNull()
        ->and($replay->result->receipt_id)->toBe($first->result->receipt_id);

    $this->fixedClock->advanceSeconds(8 * 86400);
    $after = $fixture->snapshot();

    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::IdempotencyWindowExpired)
        ->and($fixture->snapshot()->stateVersion)->toBe($after->stateVersion);
});
