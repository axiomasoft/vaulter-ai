<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\ToolCall;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Contracts\Office;
use Vaulter\Ai\Core\Contracts\RunContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;
use Vaulter\Ai\LaravelAi\LaravelOfficeExecutor;
use Vaulter\Ai\LaravelAi\OfficeAgent;
use Vaulter\Ai\Sheets\Tests\Support\SheetCommandFixture;
use Vaulter\Ai\Tests\Support\LaravelAiTestCase;
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TestOwner;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Facades\Documents;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Facades\Vaulter;

uses(LaravelAiTestCase::class);

function bindWorkspaceActors(Application $app, array $tokenMap, CallContext $initiator): void
{
    $app->instance(CallContextResolver::class, new class($tokenMap) implements CallContextResolver
    {
        /** @param array<string, CallContext> $tokenMap */
        public function __construct(private readonly array $tokenMap) {}

        public function resolve(Request $request): ?CallContext
        {
            $bearer = $request->bearerToken();

            return is_string($bearer) ? ($this->tokenMap[$bearer] ?? null) : null;
        }
    });

    $app->instance(ApprovedCommandContextResolver::class, new class($initiator) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $initiator) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->initiator->tenantId === $tenantId ? $this->initiator : null;
        }
    });

    $app->instance(RunContextResolver::class, new class($initiator) implements RunContextResolver
    {
        public function __construct(private readonly CallContext $initiator) {}

        public function resolve(string $tenantId, string $runId): ?CallContext
        {
            return $this->initiator->tenantId === $tenantId ? $this->initiator : null;
        }
    });
}

test('workspace proposal endpoints return initiator, resource, section formatted diff, digest, and separate permissions', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposalP = $fixture->prepare('15', '30', 'p42');
    $proposalQ = $fixture->prepare('Human', 'Automated', 'p71');

    $reviewer = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'rev-inspect@example.test']);
    $reader = TestActor::query()->create(['name' => 'Reader', 'email' => 'reader-inspect@example.test']);
    $rootId = $fixture->drive->root()->id;
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($reader), RoleRegistry::READER));

    $reviewerCtx = new CallContext('61', 'default', 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);
    $readerCtx = new CallContext('61', 'default', 'user/'.$reader->getKey(), driveId: $fixture->context->driveId, actor: $reader);
    $foreignCtx = new CallContext('62', 'default', 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);

    bindWorkspaceActors($this->app, [
        'reviewer-tok' => $reviewerCtx,
        'reader-tok' => $readerCtx,
        'foreign-tok' => $foreignCtx,
    ], $fixture->context);

    $pResp = $this->withToken('reviewer-tok')->getJson('/office/proposals/'.$proposalP->proposal_id);
    $pResp->assertOk();
    $data = $pResp->json('data');

    expect($data['proposal_id'])->toBe($proposalP->proposal_id)
        ->and($data['digest'])->toBe($proposalP->digest)
        ->and($data['initiator'])->toBe($fixture->context->effectiveSubject)
        ->and($data['resource']['resource_id'])->toBe($fixture->node->id)
        ->and($data['resource']['title'])->toBe('Договор поставки')
        ->and($data['diff']['kind'])->toBe('text_replace')
        ->and($data['diff']['section_path'])->toBe(['Расчёты', '4.2 Срок оплаты'])
        ->and($data['diff']['before_segments'][1])->toMatchArray(['text' => '15', 'marks' => [['type' => 'bold']]])
        ->and($data['diff']['after_segments'][1])->toMatchArray(['text' => '30', 'marks' => [['type' => 'bold']]])
        ->and($data['approval']['human_status'])->toBe('pending')
        ->and($data['approval']['can_approve'])->toBeTrue()
        ->and($data['execution']['owner_status'])->toBe('not_started')
        ->and($data['verification']['status'])->toBe('unverified');

    $listResp = $this->withToken('reviewer-tok')->getJson('/office/proposals');
    $listResp->assertOk();
    expect(array_column($listResp->json('data'), 'proposal_id'))
        ->toContain($proposalP->proposal_id, $proposalQ->proposal_id);

    // Reader can inspect diff with can_approve=false, and deciding returns 404.
    $readerResp = $this->withToken('reader-tok')->getJson('/office/proposals/'.$proposalP->proposal_id);
    $readerResp->assertOk();
    expect($readerResp->json('data.approval.can_approve'))->toBeFalse()
        ->and($readerResp->json('data.approval.can_reject'))->toBeFalse();

    $this->withToken('reader-tok')
        ->postJson('/office/proposals/'.$proposalP->proposal_id.'/decision', [
            'digest' => $proposalP->digest,
            'approved' => true,
        ])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource_unavailable');

    // Foreign tenant is refused fail-closed.
    $this->withToken('foreign-tok')
        ->getJson('/office/proposals/'.$proposalP->proposal_id)
        ->assertStatus(404);
});

test('two reviewers racing on P yield one winning decision, zero owner writes on rejection, and Q stays pending', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposalP1 = $fixture->prepare('15', '30', 'p42');
    $proposalQ1 = $fixture->prepare('Human', 'Updated', 'p71');

    $revA = TestActor::query()->create(['name' => 'Reviewer A', 'email' => 'rev-a-http@example.test']);
    $revB = TestActor::query()->create(['name' => 'Reviewer B', 'email' => 'rev-b-http@example.test']);
    $rootId = $fixture->drive->root()->id;
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($revA), RoleRegistry::WRITER));
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($revB), RoleRegistry::WRITER));

    $ctxA = new CallContext('61', 'default', 'user/'.$revA->getKey(), driveId: $fixture->context->driveId, actor: $revA);
    $ctxB = new CallContext('61', 'default', 'user/'.$revB->getKey(), driveId: $fixture->context->driveId, actor: $revB);

    bindWorkspaceActors($this->app, [
        'tok-a' => $ctxA,
        'tok-b' => $ctxB,
    ], $fixture->context);

    // Direction 1: Reviewer A approves P1; Reviewer B's rejection loses the CAS; Q1 remains pending.
    $win = $this->withToken('tok-a')->postJson('/office/proposals/'.$proposalP1->proposal_id.'/decision', [
        'digest' => $proposalP1->digest,
        'approved' => true,
    ]);
    $win->assertOk();
    expect($win->json('data.won'))->toBeTrue()
        ->and($win->json('data.proposal.approval.human_status'))->toBe('approved')
        ->and($win->json('data.proposal.approval.reviewer'))->toBe($ctxA->effectiveSubject)
        ->and($win->json('data.proposal.execution.owner_status'))->toBe('committed')
        ->and($win->json('data.proposal.execution.receipt_id'))->toBeString();

    $commandId = (string) $win->json('data.proposal.execution.command_id');

    $lose = $this->withToken('tok-b')->postJson('/office/proposals/'.$proposalP1->proposal_id.'/decision', [
        'digest' => $proposalP1->digest,
        'approved' => false,
    ]);
    $lose->assertOk();
    expect($lose->json('data.won'))->toBeFalse()
        ->and($lose->json('data.proposal.approval.human_status'))->toBe('approved')
        ->and($lose->json('data.proposal.execution.command_id'))->toBe($commandId);

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    $db = $this->app->make(OfficeDatabase::class);
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($db->query('ai_owner_routes')->count())->toBe(1)
        ->and($db->query('ai_dispatches')->count())->toBe(1);

    // Q1 stays pending.
    $qView = $this->withToken('tok-b')->getJson('/office/proposals/'.$proposalQ1->proposal_id)->json('data');
    expect($qView['approval']['human_status'])->toBe('pending')
        ->and($qView['execution']['owner_status'])->toBe('not_started');

    // Direction 2: Reviewer B rejects Q1 -> zero routes, zero dispatches, zero commits; A approving Q1 loses cleanly.
    $rejectWin = $this->withToken('tok-b')->postJson('/office/proposals/'.$proposalQ1->proposal_id.'/decision', [
        'digest' => $proposalQ1->digest,
        'approved' => false,
    ]);
    $rejectWin->assertOk();
    expect($rejectWin->json('data.won'))->toBeTrue()
        ->and($rejectWin->json('data.proposal.approval.human_status'))->toBe('rejected')
        ->and($rejectWin->json('data.proposal.execution.owner_status'))->toBe('not_started');

    $approveLose = $this->withToken('tok-a')->postJson('/office/proposals/'.$proposalQ1->proposal_id.'/decision', [
        'digest' => $proposalQ1->digest,
        'approved' => true,
    ]);
    $approveLose->assertOk();
    expect($approveLose->json('data.won'))->toBeFalse()
        ->and($approveLose->json('data.proposal.approval.human_status'))->toBe('rejected');

    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($db->query('ai_owner_routes')->count())->toBe(1)
        ->and($db->query('ai_dispatches')->count())->toBe(1);
});

test('two concurrent reviewers racing on P under PostgreSQL row locks yield one winning decision while Q stays pending', function (): void {
    Bus::fake([DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposalP = $fixture->prepare('15', '30', 'p42');
    $proposalQ = $fixture->prepare('Human', 'Updated', 'p71');

    $revA = TestActor::query()->create(['name' => 'PG Reviewer A', 'email' => 'pg-rev-a@example.test']);
    $revB = TestActor::query()->create(['name' => 'PG Reviewer B', 'email' => 'pg-rev-b@example.test']);
    $rootId = $fixture->drive->root()->id;
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($revA), RoleRegistry::WRITER));
    $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($revB), RoleRegistry::WRITER));

    $ctxA = new CallContext('61', 'default', 'user/'.$revA->getKey(), driveId: $fixture->context->driveId, actor: $revA);
    $ctxB = new CallContext('61', 'default', 'user/'.$revB->getKey(), driveId: $fixture->context->driveId, actor: $revB);
    $approvers = [
        [$ctxA, true],
        [$ctxB, false],
    ];

    DB::disconnect('testing');
    $workers = [];
    foreach ($approvers as $idx => [$approverCtx, $approveFlag]) {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);
        if ($pid === 0) {
            fclose($parent);
            fread($child, 1);
            $scope = new CommandScope($fixture->context, $fixture->node->id, 'pg-race-'.$idx);
            try {
                $won = $this->app->make(ResolveProposal::class)
                    ->resolve($scope, $proposalP->proposal_id, $proposalP->digest, $approverCtx, $approveFlag);
                fwrite($child, $won ? 'won' : 'lost');
            } catch (Throwable $failure) {
                fwrite($child, $failure::class.':'.$failure->getMessage());
            }
            fclose($child);
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($child);
        stream_set_timeout($parent, 10);
        $workers[$pid] = $parent;
    }

    foreach ($workers as $pipe) {
        fwrite($pipe, '1');
    }

    $results = [];
    foreach ($workers as $pid => $pipe) {
        $results[] = stream_get_contents($pipe);
        fclose($pipe);
        pcntl_waitpid($pid, $status);
    }

    $db = $this->app->make(OfficeDatabase::class);
    $decision = $db->query('ai_proposals')->where('proposal_id', $proposalP->proposal_id)->value('decision');

    expect($results)->toEqualCanonicalizing(['won', 'lost'])
        ->and(in_array($decision, ['approved', 'rejected'], true))->toBeTrue()
        ->and($db->query('ai_proposals')->where('proposal_id', $proposalQ->proposal_id)->value('decision'))->toBe('pending');

    if ($decision === 'approved') {
        expect($db->query('ai_owner_routes')->count())->toBe(1)
            ->and($db->query('ai_dispatches')->count())->toBe(1);
    } else {
        expect($db->query('ai_owner_routes')->count())->toBe(0)
            ->and($db->query('ai_dispatches')->count())->toBe(0);
    }
})->group('engine:pgsql');

test('separate decision vs execution auth blocks commit on initiator revocation, revoking view hides stale diff, and expiry uses authoritative clock', function (): void {
    Bus::fake([DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare('15', '30', 'p42');

    $reviewer = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'rev-sep@example.test']);
    $rootId = $fixture->drive->root()->id;
    $reviewerGrant = $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $reviewerCtx = new CallContext('61', 'default', 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);

    bindWorkspaceActors($this->app, ['reviewer-tok' => $reviewerCtx], $fixture->context);

    // 1. Reviewer approves P -> human_status becomes 'approved' while owner_status is still 'pending_dispatch'.
    $decided = $this->withToken('reviewer-tok')->postJson('/office/proposals/'.$proposal->proposal_id.'/decision', [
        'digest' => $proposal->digest,
        'approved' => true,
    ]);
    $decided->assertOk();
    expect($decided->json('data.won'))->toBeTrue()
        ->and($decided->json('data.proposal.approval.human_status'))->toBe('approved')
        ->and($decided->json('data.proposal.execution.owner_status'))->toBe('pending_dispatch');

    // 2. Initiator loses edit permission BEFORE DispatchApprovedCommand runs (reviewer STILL holds permission).
    $fixture->revoke();
    $job = new DispatchApprovedCommand('61', $proposal->proposal_id);
    $this->app->call([$job, 'handle']);

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    $db = $this->app->make(OfficeDatabase::class);
    expect($owner->table('v_ai_text_commands')->count())->toBe(0)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(0)
        ->and($db->query('ai_dispatches')->where('proposal_id', $proposal->proposal_id)->value('state'))->toBe('exhausted');

    $afterFailedDispatch = $this->withToken('reviewer-tok')->getJson('/office/proposals/'.$proposal->proposal_id);
    $afterFailedDispatch->assertOk();
    expect($afterFailedDispatch->json('data.approval.human_status'))->toBe('approved')
        ->and($afterFailedDispatch->json('data.execution.owner_status'))->toBe('rejected')
        ->and($afterFailedDispatch->json('data.execution.rejection_reason'))->toBe('resource_unavailable');

    // 3. Reviewer loses view permission BEFORE reading -> 404 without stale diff.
    $fixture->drive->access()->revoke($rootId, $reviewerGrant->id);
    $this->withToken('reviewer-tok')
        ->getJson('/office/proposals/'.$proposal->proposal_id)
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource_unavailable');

    $listResp = $this->withToken('reviewer-tok')->getJson('/office/proposals');
    $listResp->assertOk();
    expect($listResp->json('data'))->toBe([]);

    // 4. Expired proposal fails with proposal_expired after acquiring lock.
    $drive2Owner = TestOwner::query()->create(['name' => 'Workspace 2']);
    $drive2 = Vaulter::drive($drive2Owner)->asSystem('seed-exp');
    $rev2 = TestActor::query()->create(['name' => 'Reviewer 2', 'email' => 'rev-exp@example.test']);
    $drive2->access()->grant($drive2->root()->id, new GrantInput(Subject::ofModel($rev2), RoleRegistry::WRITER));
    $expiringDoc = Documents::in($drive2)->create('Договор срочный', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-exp', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Срок']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [['type' => 'text', 'text' => 'Pay 15 days.']]],
        ],
    ]);
    $rev2Ctx = new CallContext('61', 'default', 'user/'.$rev2->getKey(), driveId: $drive2->snapshot()->id, actor: $rev2);
    bindWorkspaceActors($this->app, ['rev2-tok' => $rev2Ctx], $rev2Ctx);

    $office = $this->app->make(Office::class);
    $readExp = $office->handle($rev2Ctx, new OfficeRequest(1, OfficeOperation::Read, (object) [
        'resource_id' => $expiringDoc->id,
        'selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42'],
    ]));
    $expiringResp = $office->handle($rev2Ctx, new OfficeRequest(1, OfficeOperation::Prepare, (object) [
        'resource_id' => $expiringDoc->id,
        'command' => 'text.replace_exact@1',
        'read_token' => $readExp->result->read_token,
        'arguments' => (object) ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ]));
    expect($expiringResp->ok)->toBeTrue();
    $expiring = $expiringResp->result;
    $this->fixedClock->advanceSeconds(4000);

    $this->withToken('rev2-tok')
        ->postJson('/office/proposals/'.$expiring->proposal_id.'/decision', [
            'digest' => $expiring->digest,
            'approved' => true,
        ])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'proposal_expired');
});

test('browser disconnect between approval/commit and restart recovers run state, human vs owner status, and receipt without duplicate commit', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    Config::set('ai.default', 'openai');
    Config::set('ai.providers.openai', ['driver' => 'openai', 'key' => 'sk-test-71']);
    Config::set('vaulter-ai.profiles.documents', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1'],
        'approval' => 'human',
        'revision' => 'rev-71',
    ]);

    $reviewer = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'rev-restart@example.test']);
    $fixture->drive->access()->grant($fixture->drive->root()->id, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $reviewerCtx = new CallContext('61', 'default', 'user/'.$reviewer->getKey(), driveId: $fixture->context->driveId, actor: $reviewer);
    bindWorkspaceActors($this->app, ['rev-tok' => $reviewerCtx], $fixture->context);

    $runs = $this->app->make(Runs::class);
    $executor = $this->app->make(LaravelOfficeExecutor::class);
    $run = $runs->createOrFind($fixture->context, 'laravel-ai', 'evt-71-disconnect', 'rev-71', [
        'prompt' => 'Replace 15 with 30',
        'resource_id' => $fixture->node->id,
    ]);

    $turn = 0;
    OfficeAgent::fake(function () use (&$turn, $fixture, $runs, $run): mixed {
        $steps = $runs->listSteps($fixture->context, $run->runId);
        $latestOf = static function (OfficeOperation $op) use ($steps): ?array {
            $match = null;
            foreach ($steps as $s) {
                if ($s->operation === $op) {
                    $match = $s->compactResult;
                }
            }

            return $match;
        };

        return match ($turn++) {
            0 => new ToolCall('tc-71-r', 'office_read', ['resource_id' => $fixture->node->id, 'selector' => ['kind' => 'text_block', 'block_id' => 'p42']]),
            1 => new ToolCall('tc-71-p', 'office_prepare', [
                'resource_id' => $fixture->node->id,
                'command' => 'text.replace_exact@1',
                'read_token' => (string) ($latestOf(OfficeOperation::Read)['result']['read_token'] ?? ''),
                'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
            ]),
            2 => new ToolCall('tc-71-c', 'office_commit', [
                'resource_id' => $fixture->node->id,
                'proposal_id' => (string) ($latestOf(OfficeOperation::Prepare)['result']['proposal_id'] ?? ''),
                'digest' => (string) ($latestOf(OfficeOperation::Prepare)['result']['digest'] ?? ''),
            ]),
            default => 'Awaiting human decision.',
        };
    })->preventStrayPrompts();

    $pausedRun = $executor->executeRun($fixture->context, $run->runId);
    expect($pausedRun->state)->toBe('awaiting_approval');

    $runJson = $this->withToken('rev-tok')->getJson('/office/runs/'.$run->runId)->assertOk()->json('data');
    $proposalData = $runJson['resources'][0]['proposal'];
    $proposalId = (string) $proposalData['proposal_id'];
    $digest = (string) $proposalData['digest'];
    $savedCommandId = (string) $proposalData['execution']['command_id'];
    expect($savedCommandId)->not->toBeEmpty()
        ->and($proposalData['approval']['human_status'])->toBe('pending')
        ->and($proposalData['execution']['owner_status'])->toBe('not_started');

    // Approve with queue faked -> distinguishes "human_status=approved" BEFORE owner commit executes.
    $realDispatcher = $this->app->make(Dispatcher::class);
    Bus::fake([DispatchApprovedCommand::class]);
    $this->withToken('rev-tok')->postJson('/office/proposals/'.$proposalId.'/decision', [
        'digest' => $digest,
        'approved' => true,
    ])->assertOk();

    $midJson = $this->withToken('rev-tok')->getJson('/office/runs/'.$run->runId)->assertOk()->json('data');
    expect($midJson['state'])->toBe('reconcile')
        ->and($midJson['resources'][0]['proposal']['approval']['human_status'])->toBe('approved')
        ->and($midJson['resources'][0]['proposal']['execution']['owner_status'])->toBe('pending_dispatch');

    // Now simulate worker completing dispatch AFTER browser disconnect, and forget resolved workspace/executor singletons ("restart").
    Bus::swap($realDispatcher);
    $this->app->call([new DispatchApprovedCommand('61', $proposalId), 'handle']);
    $this->app->forgetInstance(OfficeWorkspaceService::class);
    $this->app->forgetInstance(Runs::class);
    $this->app->forgetInstance(OwnerRoutes::class);

    $recoveredRun = $this->withToken('rev-tok')->getJson('/office/runs/'.$run->runId)->assertOk()->json('data');
    expect($recoveredRun['state'])->toBe('completed')
        ->and($recoveredRun['initiator'])->toBe($fixture->context->effectiveSubject)
        ->and($recoveredRun['can_resume'])->toBeFalse()
        ->and($recoveredRun['confirmed_steps'])->toHaveCount(1)
        ->and($recoveredRun['remaining_steps'])->toBeEmpty()
        ->and($recoveredRun['resources'][0]['proposal']['approval']['human_status'])->toBe('approved')
        ->and($recoveredRun['resources'][0]['proposal']['approval']['reviewer'])->toBe($reviewerCtx->effectiveSubject)
        ->and($recoveredRun['resources'][0]['proposal']['execution']['owner_status'])->toBe('committed')
        ->and($recoveredRun['resources'][0]['proposal']['execution']['command_id'])->toBe($savedCommandId)
        ->and($recoveredRun['resources'][0]['verification']['status'])->toBe('passed');

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->count())->toBe(1)
        ->and($owner->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);
});

test('multi-resource draft run preserves confirmed doc1, re-reads conflicted doc2, and continues only doc2 after resolution or neighboring edit', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: true);
    $doc2 = Documents::in($fixture->drive)->create('Приложение 2', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-app2', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Приложение 2']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                ['type' => 'text', 'text' => 'Penalty 22 percent today.'],
            ]],
        ],
    ]);

    bindWorkspaceActors($this->app, ['writer-tok' => $fixture->context], $fixture->context);
    $workspace = $this->app->make(OfficeWorkspaceService::class);

    // 1. Draft run expects "15" in both doc1 and doc2 -> doc1 succeeds & verifies; doc2 conflicts with precondition_failed.
    $initialRun = $workspace->createDraftRun($fixture->context, 'draft', 'evt-multi-doc-1', 'Update both docs', [
        [
            'resource_id' => $fixture->node->id,
            'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
            'command' => 'text.replace_exact@1',
            'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
        ],
        [
            'resource_id' => $doc2->id,
            'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
            'command' => 'text.replace_exact@1',
            'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '25'],
        ],
    ]);

    $runId = (string) $initialRun['run_id'];
    expect($initialRun['state'])->toBe('partially_applied')
        ->and($initialRun['confirmed_steps'])->toHaveCount(1)
        ->and($initialRun['confirmed_steps'][0]['resource_id'])->toBe($fixture->node->id)
        ->and($initialRun['confirmed_steps'][0]['verification']['status'])->toBe('passed')
        ->and($initialRun['remaining_steps'])->toHaveCount(1)
        ->and($initialRun['remaining_steps'][0]['resource_id'])->toBe($doc2->id)
        ->and($initialRun['remaining_steps'][0]['status'])->toBe('conflict')
        ->and($initialRun['remaining_steps'][0]['requires_reread'])->toBeTrue();

    $doc1Receipt = $initialRun['confirmed_steps'][0]['execution']['receipt_id'];

    // 2. Resuming without updated target arguments re-reads doc2 (exposing updated text "22"), keeps doc1 untouched, stays partially_applied.
    $rereadResume = $this->withToken('writer-tok')->postJson('/office/runs/'.$runId.'/resume', [])->assertOk()->json('data');
    expect($rereadResume['state'])->toBe('partially_applied')
        ->and($rereadResume['confirmed_steps'][0]['execution']['receipt_id'])->toBe($doc1Receipt)
        ->and($rereadResume['remaining_steps'][0]['requires_reread'])->toBeTrue()
        ->and(json_encode($rereadResume['remaining_steps'][0]['latest_read'], JSON_THROW_ON_ERROR))->toContain('Penalty 22 percent today.');

    // 3. Resuming with arguments matching the freshly read content finishes ONLY doc2; doc1 write count stays 1!
    $completed = $this->withToken('writer-tok')->postJson('/office/runs/'.$runId.'/resume', [
        'arguments_by_resource' => [
            $doc2->id => ['block_id' => 'p42', 'expected' => '22', 'replacement' => '25'],
        ],
    ])->assertOk()->json('data');

    expect($completed['state'])->toBe('completed')
        ->and($completed['can_resume'])->toBeFalse()
        ->and($completed['confirmed_steps'])->toHaveCount(2)
        ->and($completed['remaining_steps'])->toBeEmpty();

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->where('resource_id', $fixture->node->id)->count())->toBe(1)
        ->and($owner->table('v_ai_text_commands')->where('resource_id', $doc2->id)->count())->toBe(1);
});

test('multi-resource draft run across text and spreadsheet resumes cleanly when neighbor changes or target conflicts', function (): void {
    $sheetFixture = new SheetCommandFixture($this->app, automatic: true);
    Config::set('vaulter-ai.profiles.office', [
        'executor' => 'laravel-ai',
        'provider_connection' => 'office_model',
        'commands' => ['text.replace_exact@1', 'sheet.fill_formula@1'],
        'approval' => 'automatic',
        'revision' => 'rev-office-1',
    ]);
    $officeContext = new CallContext(
        tenantId: $sheetFixture->context->tenantId,
        projectScope: $sheetFixture->context->projectScope,
        effectiveSubject: $sheetFixture->context->effectiveSubject,
        profile: 'office',
        policyRevision: 'rev-office-1',
        driveId: $sheetFixture->context->driveId,
        actor: $sheetFixture->actor,
    );

    $docNode = Documents::in($sheetFixture->drive)->create('Сопроводительная записка', 'vaulter/rich-text', [
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['blockId' => 'h-note', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Примечание']]],
            ['type' => 'paragraph', 'attrs' => ['blockId' => 'p42'], 'content' => [
                ['type' => 'text', 'text' => 'Approve budget delta = 15 days.'],
            ]],
        ],
    ]);

    bindWorkspaceActors($this->app, ['sheet-tok' => $officeContext], $officeContext);
    $workspace = $this->app->make(OfficeWorkspaceService::class);

    $partialRun = $workspace->createDraftRun($officeContext, 'draft', 'evt-doc-sheet-1', 'Update doc + sheet', [
        [
            'resource_id' => $docNode->id,
            'selector' => ['kind' => 'text_block', 'block_id' => 'p42'],
            'command' => 'text.replace_exact@1',
            'arguments' => ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
        ],
        [
            'resource_id' => $sheetFixture->node->id,
            'selector' => [
                'kind' => 'sheet_table',
                'sheet_id' => 'expenses',
                'table_id' => 'costs',
                'columns' => ['actual', 'plan'],
                'row_ids' => ['r-telecom', 'r-travel'],
            ],
            'command' => 'sheet.fill_formula@1',
            'arguments' => [
                'sheet_id' => 'expenses',
                'table_id' => 'other-costs',
                'scope' => 'fixed_members',
                'destination_column_id' => 'delta',
                'expression' => [
                    'op' => 'subtract',
                    'left_column_id' => 'actual',
                    'right_column_id' => 'plan',
                ],
            ],
        ],
    ]);

    expect($partialRun['state'])->toBe('partially_applied')
        ->and($partialRun['confirmed_steps'])->toHaveCount(1)
        ->and($partialRun['confirmed_steps'][0]['resource_id'])->toBe($docNode->id)
        ->and($partialRun['remaining_steps'])->toHaveCount(1)
        ->and($partialRun['remaining_steps'][0]['resource_id'])->toBe($sheetFixture->node->id)
        ->and($partialRun['remaining_steps'][0]['requires_reread'])->toBeTrue();

    // Resume only the sheet with the matching table_id; docNode must remain committed once with the same receipt.
    $docReceipt = $partialRun['confirmed_steps'][0]['execution']['receipt_id'];
    $completed = $this->withToken('sheet-tok')->postJson('/office/runs/'.$partialRun['run_id'].'/resume', [
        'arguments_by_resource' => [
            $sheetFixture->node->id => [
                'sheet_id' => 'expenses',
                'table_id' => 'costs',
                'scope' => 'fixed_members',
                'destination_column_id' => 'delta',
                'expression' => [
                    'op' => 'subtract',
                    'left_column_id' => 'actual',
                    'right_column_id' => 'plan',
                ],
            ],
        ],
    ])->assertOk()->json('data');

    expect($completed['state'])->toBe('completed')
        ->and($completed['confirmed_steps'])->toHaveCount(2)
        ->and($completed['confirmed_steps'][0]['execution']['receipt_id'])->toBe($docReceipt)
        ->and($completed['confirmed_steps'][1]['resource_id'])->toBe($sheetFixture->node->id)
        ->and($completed['confirmed_steps'][1]['verification']['status'])->toBe('passed');

    $owner = $this->app->make(VaulterDatabase::class)->connection();
    expect($owner->table('v_ai_text_commands')->where('resource_id', $docNode->id)->count())->toBe(1);

    // Also inspect neighboring-text edit on a fresh document proposal: human changes adjacent paragraph, approval still commits cleanly!
    $neighborFixture = new TextCommandFixture($this->app, automatic: false);
    $neighborProposal = $neighborFixture->prepare('15', '30', 'p42');
    $neighborFixture->edit('p71', static fn (): array => [['type' => 'text', 'text' => 'Updated legal annex.']]);

    bindWorkspaceActors($this->app, ['neighbor-tok' => $neighborFixture->context], $neighborFixture->context);
    $approved = $this->withToken('neighbor-tok')->postJson('/office/proposals/'.$neighborProposal->proposal_id.'/decision', [
        'digest' => $neighborProposal->digest,
        'approved' => true,
    ])->assertOk()->json('data');

    expect($approved['won'])->toBeTrue()
        ->and($approved['proposal']['execution']['owner_status'])->toBe('committed')
        ->and($approved['proposal']['verification']['status'])->toBe('passed');
    $snap = $neighborFixture->snapshot()->content->toArray();
    expect($snap['content'][2]['content'][1]['text'])->toBe('30')
        ->and($snap['content'][5]['content'][0]['text'])->toBe('Updated legal annex.');
});
