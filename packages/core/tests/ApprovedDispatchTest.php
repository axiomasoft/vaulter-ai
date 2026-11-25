<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(LaravelAiTestCase::class);

/**
 * Human approval only records a decision; the owner re-checks its preconditions when
 * the approved command is dispatched. These tests pin what happens when the document
 * moved on between prepare and approval, and the alternate decision payloads.
 */
function approvedDispatchReviewer(TextCommandFixture $fixture, string $email): void
{
    $reviewer = TestActor::query()->create(['name' => 'Reviewer', 'email' => $email]);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $reviewerCtx = new CallContext('61', 'default', 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);

    app()->instance(CallContextResolver::class, new class($reviewerCtx) implements CallContextResolver
    {
        public function __construct(private readonly CallContext $context) {}

        public function resolve(Request $request): ?CallContext
        {
            return $request->bearerToken() === 'rev' ? $this->context : null;
        }
    });
    app()->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $initiator) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->initiator->tenantId === $tenantId ? $this->initiator : null;
        }
    });
}

test('an approved proposal whose target text changed is rejected by the owner and writes nothing', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare('15', '30', 'p42');
    approvedDispatchReviewer($fixture, 'rev-dispatch-stale@example.test');

    $edited = $fixture->edit('p42', static function (array $children): array {
        $children[1]['text'] = '20';

        return $children;
    });

    $response = $this->withToken('rev')->postJson('/office/proposals/'.$proposal->proposal_id.'/decision', [
        'digest' => $proposal->digest,
        'approved' => true,
    ]);

    // The human decision stands, but execution reports the owner's refusal instead of a receipt.
    $response->assertOk()
        ->assertJsonPath('data.won', true)
        ->assertJsonPath('data.proposal.approval.human_status', 'approved')
        ->assertJsonPath('data.proposal.execution.owner_status', 'rejected')
        ->assertJsonPath('data.proposal.execution.dispatch_state', 'delivered')
        ->assertJsonPath('data.proposal.execution.rejection_reason', 'precondition_failed')
        ->assertJsonPath('data.proposal.execution.receipt_id', null);

    $after = $fixture->snapshot();
    expect($after->stateVersion)->toBe($edited->stateVersion)
        ->and(json_encode($after->content->toArray()))->not->toContain('"30"');
});

test('the decision alias and string booleans are accepted, anything else is invalid input', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $rejected = $fixture->prepare('15', '30', 'p42');
    $approved = $fixture->prepare('Human', 'Automated', 'p71');
    approvedDispatchReviewer($fixture, 'rev-dispatch-alias@example.test');
    $before = $fixture->snapshot();

    $this->withToken('rev')->postJson('/office/proposals/'.$rejected->proposal_id.'/decision', [
        'digest' => $rejected->digest,
        'approved' => 'maybe',
    ])->assertStatus(400)->assertJsonPath('error.code', 'invalid_input');

    $this->withToken('rev')->postJson('/office/proposals/'.$rejected->proposal_id.'/decision', [
        'digest' => $rejected->digest,
        'decision' => 'postpone',
    ])->assertStatus(400)->assertJsonPath('error.code', 'invalid_input');

    $this->withToken('rev')->postJson('/office/proposals/'.$rejected->proposal_id.'/decision', [
        'digest' => $rejected->digest,
        'decision' => 'reject',
    ])->assertOk()
        ->assertJsonPath('data.proposal.approval.human_status', 'rejected')
        ->assertJsonPath('data.proposal.execution.owner_status', 'not_started');
    expect($fixture->snapshot()->stateVersion)->toBe($before->stateVersion);

    $this->withToken('rev')->postJson('/office/proposals/'.$approved->proposal_id.'/decision', [
        'digest' => $approved->digest,
        'approved' => 'yes',
    ])->assertOk()
        ->assertJsonPath('data.proposal.approval.human_status', 'approved')
        ->assertJsonPath('data.proposal.execution.owner_status', 'committed');
});
