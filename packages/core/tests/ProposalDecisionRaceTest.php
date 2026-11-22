<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
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
use Vaulter\Ai\Tests\Support\TestActor;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

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

test('retrying the same approval republishes a durable dispatch after queue publication fails', function (): void {
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare();
    $scope = new CommandScope($fixture->context, $fixture->node->id, 'edit-1');
    $db = $this->app->make(OfficeDatabase::class);
    $before = $fixture->snapshot()->stateVersion;
    $job = null;
    $bus = $this->mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->once()->ordered()->andThrow(new RuntimeException('queue unavailable'));
    $bus->shouldReceive('dispatch')->once()->ordered()
        ->andReturnUsing(static function (DispatchApprovedCommand $published) use (&$job): void {
            $job = $published;
        });
    $this->app->instance(ApprovedCommandContextResolver::class, new class($fixture->context) implements ApprovedCommandContextResolver
    {
        public function __construct(private readonly CallContext $context) {}

        public function resolve(string $tenantId, string $proposalId): ?CallContext
        {
            return $this->context;
        }
    });
    $resolver = $this->app->make(ResolveProposal::class);
    $approve = fn () => $resolver->resolve($scope, $proposal->proposal_id, $proposal->digest, $fixture->context, true);

    expect($approve)->toThrow(RuntimeException::class, 'queue unavailable');
    $eventId = $db->query('ai_dispatches')->value('event_id');
    expect($eventId)->toBeString()
        ->and($db->query('ai_dispatches')->value('state'))->toBe('ready')
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'])->result->state)->toBe('running');

    $otherScope = new CommandScope($fixture->context, $fixture->node->id, 'other-key');
    expect($resolver->resolve($otherScope, $proposal->proposal_id, $proposal->digest, $fixture->context, true))->toBeFalse()
        ->and($job)->toBeNull();
    expect($approve())->toBeFalse()
        ->and($job)->toBeInstanceOf(DispatchApprovedCommand::class);
    $this->app->call([$job, 'handle']);
    $this->app->call([$job, 'handle']);
    $status = $fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1']);
    expect($status->result->state)->toBe('committed')
        ->and($fixture->snapshot()->stateVersion)->toBe($before + 1)
        ->and($db->query('ai_owner_routes')->count())->toBe(1)
        ->and($db->query('ai_dispatches')->pluck('event_id')->all())->toBe([$eventId])
        ->and($db->query('ai_dispatches')->value('state'))->toBe('delivered')
        ->and($approve())->toBeFalse();
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

test('a reviewer waiting for the proposal lock loses the decision if their access is revoked', function (): void {
    Bus::fake([DispatchApprovedCommand::class]);
    $fixture = new TextCommandFixture($this->app, automatic: false);
    $proposal = $fixture->prepare();
    $reviewer = TestActor::query()->create(['name' => 'Reviewer', 'email' => 'reviewer@example.test']);
    $rootId = $fixture->drive->root()->id;
    $grant = $fixture->drive->access()->grant($rootId, new GrantInput(Subject::ofModel($reviewer), RoleRegistry::WRITER));
    $approver = new CallContext(
        $fixture->context->tenantId, $fixture->context->projectScope, 'user/'.$reviewer->getKey(),
        driveId: $fixture->context->driveId, actor: $reviewer,
    );
    $scope = new CommandScope($fixture->context, $fixture->node->id, 'edit-1');
    $db = $this->app->make(OfficeDatabase::class);
    DB::disconnect('testing');
    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $worker = pcntl_fork();
    expect($worker)->toBeGreaterThanOrEqual(0);
    if ($worker === 0) {
        fclose($parent);
        fread($child, 1);
        try {
            fwrite($child, $db->connection()->scalar('select pg_backend_pid()')."\n");
            $won = $this->app->make(ResolveProposal::class)
                ->resolve($scope, $proposal->proposal_id, $proposal->digest, $approver, true);
            fwrite($child, $won ? 'approved' : 'decided');
        } catch (OfficeException $failure) {
            fwrite($child, $failure->errorCode->value);
        } catch (Throwable $failure) {
            fwrite($child, $failure::class.' '.$failure->getMessage());
        }
        fclose($child);
        posix_kill(getmypid(), SIGKILL);
    }

    fclose($child);
    stream_set_timeout($parent, 10);
    try {
        $db->transaction(function () use ($db, $fixture, $proposal, $parent, $rootId, $grant): void {
            $db->query('ai_proposals')->where('proposal_id', $proposal->proposal_id)->lockForUpdate()->first();
            $connection = $db->connection();
            $locker = $connection->scalar('select pg_backend_pid()');
            fwrite($parent, '1');
            $workerPid = (int) fgets($parent);
            $deadline = microtime(true) + 5;
            do {
                $blocked = $connection->scalar('select ? = any(pg_blocking_pids(?))', [$locker, $workerPid]);
                if ($blocked) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            expect($blocked)->toBeTrue('The reviewer must pass the initial ACL check and wait on the proposal lock.');
            $fixture->drive->access()->revoke($rootId, $grant->id);
        }, $fixture->context->tenantId);
        expect(stream_get_contents($parent))->toBe(ErrorCode::ResourceUnavailable->value);
    } finally {
        fclose($parent);
        if (pcntl_waitpid($worker, $status, WNOHANG) === 0) {
            posix_kill($worker, SIGKILL);
            pcntl_waitpid($worker, $status);
        }
    }

    expect($db->query('ai_proposals')->where('proposal_id', $proposal->proposal_id)->value('decision'))->toBe('pending')
        ->and($db->query('ai_owner_routes')->count())->toBe(0)
        ->and($db->query('ai_dispatches')->count())->toBe(0)
        ->and($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal))->errorCode)->toBe(ErrorCode::ApprovalRequired);
})->group('engine:pgsql');
