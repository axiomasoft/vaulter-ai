<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Vaulter\Ai\Core\Actions\ResolveProposal;
use Vaulter\Ai\Core\Contracts\ApprovedCommandContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Persistence\ApprovedDispatches;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;

uses(OfficeTestCase::class);

test('only one decision binds the human proposal, the queue receives only its reference, and a stale lease cannot settle', function (): void {
    Bus::fake([DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare();
    $other = $fixture->prepare();
    $input = $fixture->commitInput($proposal);
    $scope = new CommandScope($fixture->context, $fixture->node->id, 'edit-1');
    $foreign = new CallContext('62', 'default', $fixture->context->effectiveSubject, actor: $fixture->actor);
    $resolver = $this->app->make(ResolveProposal::class);
    $db = $this->app->make(OfficeDatabase::class);

    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::ApprovalRequired);
    expect(fn () => $resolver->resolve($scope, $proposal->proposal_id, $proposal->digest, $foreign, true))
        ->toThrow(OfficeException::class, 'Resource is unavailable.');
    expect($resolver->resolve($scope, $proposal->proposal_id, $proposal->digest, $fixture->context, true))->toBeTrue();
    expect($resolver->resolve($scope, $proposal->proposal_id, $proposal->digest, $fixture->context, false))->toBeFalse();
    Bus::assertDispatchedTimes(DispatchApprovedCommand::class, 1);
    Bus::assertDispatched(DispatchApprovedCommand::class, function (DispatchApprovedCommand $job) use ($proposal): bool {
        return $job->queue === 'office-agent' && $job->tenantId === '61' && $job->proposalId === $proposal->proposal_id;
    });
    expect($db->query('ai_proposals')->where('proposal_id', $other->proposal_id)->value('decision'))->toBe('pending')
        ->and($db->query('ai_dispatches')->count())->toBe(1);
    expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'other-key'))->errorCode)
        ->toBe(ErrorCode::IdempotencyConflict);

    $dispatches = $this->app->make(ApprovedDispatches::class);
    $lease = $dispatches->claim($scope, $proposal->proposal_id);
    expect($lease)->toBeString();
    expect($dispatches->claim($scope, $proposal->proposal_id))->toBeNull();
    $this->fixedClock->advanceSeconds(60);
    expect($dispatches->finish($scope, $proposal->proposal_id, $lease, 'delivered', 'stale-receipt'))->toBeFalse()
        ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBeNull();

    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $context) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->context;
        }
    });
    $job = new DispatchApprovedCommand($fixture->context->tenantId, $proposal->proposal_id);
    $this->app->call([$job, 'handle']);
    $status = $fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1']);
    expect($status->result->state)->toBe('committed')
        ->and($db->query('ai_owner_routes')->value('receipt_id'))->toBe($status->result->receipt_id)
        ->and($db->query('ai_dispatches')->value('state'))->toBe('delivered')
        ->and($db->query('ai_dispatches')->value('attempts'))->toBe(2);
    expect($dispatches->finish($scope, $proposal->proposal_id, $lease, 'exhausted'))->toBeFalse()
        ->and($db->query('ai_dispatches')->value('state'))->toBe('delivered');
});

test('competing approval processes create one route and dispatch on PostgreSQL', function (): void {
    Bus::fake([DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare();
    $other = $fixture->prepare();
    DB::disconnect('testing');

    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $worker = pcntl_fork();
        expect($worker)->toBeGreaterThanOrEqual(0);
        if ($worker === 0) {
            fclose($parent);
            fread($child, 1);
            $scope = new CommandScope($fixture->context, $fixture->node->id, 'edit-'.$index);
            try {
                $won = $this->app->make(ResolveProposal::class)
                    ->resolve($scope, $proposal->proposal_id, $proposal->digest, $fixture->context, true);
                fwrite($child, $won ? 'approved' : 'decided');
            } catch (Throwable $failure) {
                fwrite($child, $failure::class.' '.$failure->getMessage());
            }
            fclose($child);
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($child);
        stream_set_timeout($parent, 10);
        $workers[$worker] = $parent;
    }

    foreach ($workers as $pipe) {
        fwrite($pipe, '1');
    }
    $outcomes = [];
    foreach ($workers as $worker => $pipe) {
        $outcomes[] = stream_get_contents($pipe);
        fclose($pipe);
        pcntl_waitpid($worker, $status);
    }
    $db = $this->app->make(OfficeDatabase::class);
    expect($outcomes)->toEqualCanonicalizing(['approved', 'decided'])
        ->and($db->query('ai_proposals')->where('proposal_id', $other->proposal_id)->value('decision'))->toBe('pending')
        ->and($db->query('ai_owner_routes')->count())->toBe(1)
        ->and($db->query('ai_dispatches')->count())->toBe(1);
})->group('engine:pgsql');
