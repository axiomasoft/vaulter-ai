<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Tests\Support\OfficeTestCase;
use Vaulter\Ai\Tests\Support\TextCommandFixture;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;

uses(OfficeTestCase::class);

test('snapshot replacement keeps the source marks and independently verifies current and historical state', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $proposal = $fixture->prepare();
    $commit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal));
    expect($commit->errorCode)->toBeNull();
    $verify = ['receipt_id' => $commit->result->receipt_id];
    $historical = $fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'at_commit']);
    expect($historical->result->outcome)->toBe('passed')
        ->and($historical->result->checks[0]->actual)->toBe('30')
        ->and($historical->result->checks[1]->actual)->toBe(['bold']);

    $fixture->edit('p42', static function (array $children): array {
        $children[1]['text'] = '12';

        return $children;
    });
    $current = $fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'current']);
    expect($current->result->outcome)->toBe('failed')
        ->and($current->result->marker->position)->toBe($historical->result->marker->position + 1)
        ->and($current->result->checks[0]->actual)->toBe('12')
        ->and($current->result->checks[0]->passed)->toBeFalse()
        ->and($current->result->checks[1]->passed)->toBeTrue()
        ->and($fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'at_commit'])->toArray())->toBe($historical->toArray());

    $this->fixedClock->advanceSeconds(86400);
    expect($fixture->call(OfficeOperation::Verify, $verify + ['mode' => 'at_commit'])->errorCode)->toBe(ErrorCode::EvidenceUnavailable);
});

test('a changed link attribute fails both the prepare pin and commit without a partial write', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $setLink = static fn (string $url): Closure => static function (array $children) use ($url): array {
        $children[1]['marks'] = [['type' => 'link', 'attrs' => ['href' => $url]]];

        return $children;
    };
    $fixture->edit('p42', $setLink('https://original.example/'));
    $read = $fixture->call(OfficeOperation::Read, ['selector' => (object) ['kind' => 'text_block', 'block_id' => 'p42']]);
    $proposal = $fixture->prepare();
    $updated = $fixture->edit('p42', $setLink('https://changed.example/'));

    $stalePrepare = $fixture->call(OfficeOperation::Prepare, [
        'command' => 'text.replace_exact@1',
        'read_token' => $read->result->read_token,
        'arguments' => (object) ['block_id' => 'p42', 'expected' => '15', 'replacement' => '30'],
    ]);
    expect($stalePrepare->errorCode)->toBe(ErrorCode::PreconditionFailed);
    $input = $fixture->commitInput($proposal);
    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::PreconditionFailed)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'])->result->reason)->toBe('precondition_failed')
        ->and($fixture->snapshot()->stateVersion)->toBe($updated->stateVersion)
        ->and($fixture->snapshot()->content->toArray())->toBe($updated->content->toArray());

    $retry = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($fixture->prepare(), 'edit-link'));
    $fixture->edit('p42', $setLink('https://another.example/'));
    $verify = $fixture->call(OfficeOperation::Verify, ['receipt_id' => $retry->result->receipt_id, 'mode' => 'current']);
    expect($verify->result->outcome)->toBe('failed')
        ->and($verify->result->checks[0]->passed)->toBeTrue()
        ->and($verify->result->checks[1]->actual)->toBe(['link'])
        ->and($verify->result->checks[1]->passed)->toBeFalse();
});

test('an error between the document write and evidence insert rolls back the entire owner attempt', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $input = $fixture->commitInput($fixture->prepare());
    $before = $fixture->snapshot();
    $db = $this->app->make(VaulterDatabase::class)->connection();
    $injectFailure = true;
    $db->beforeExecuting(static function (string $sql) use (&$injectFailure): void {
        if ($injectFailure && str_starts_with($sql, 'insert into "v_ai_text_command_evidence"')) {
            $injectFailure = false;
            throw new RuntimeException('owner evidence fault');
        }
    });

    expect(fn () => $fixture->call(OfficeOperation::Commit, $input))->toThrow(RuntimeException::class, 'owner evidence fault');
    expect($fixture->snapshot()->stateVersion)->toBe($before->stateVersion)
        ->and($fixture->snapshot()->content->toArray())->toBe($before->content->toArray())
        ->and($db->table('v_ai_text_commands')->count())->toBe(0)
        ->and($db->table('v_ai_text_command_evidence')->count())->toBe(0)
        ->and($db->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(0)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'])->result->state)->toBe('running');

    $committed = $fixture->call(OfficeOperation::Commit, $input);
    expect($committed->result->state)->toBe('committed')
        ->and($committed->result->marker->position)->toBe($before->stateVersion + 1)
        ->and($db->table('v_ai_text_commands')->count())->toBe(1)
        ->and($db->table('v_ai_text_command_evidence')->count())->toBe(1)
        ->and($db->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);
});

test('expiry while the route is already bound is a durable owner rejection', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $input = $fixture->commitInput($fixture->prepare());
    $before = $fixture->snapshot()->stateVersion;
    $db = $this->app->make(VaulterDatabase::class)->connection();
    $clock = $this->fixedClock;
    $db->beforeExecuting(static function (string $sql) use ($clock): void {
        if (str_starts_with($sql, 'select * from "v_ai_text_commands"') && str_contains($sql, '"command_id"')) {
            $clock->advanceSeconds(3600);
        }
    });

    expect($fixture->call(OfficeOperation::Commit, $input)->errorCode)->toBe(ErrorCode::ProposalExpired)
        ->and($fixture->call(OfficeOperation::Status, ['command_id' => 'edit-1'])->result->reason)->toBe('proposal_expired')
        ->and($fixture->snapshot()->stateVersion)->toBe($before)
        ->and($db->table('v_ai_text_command_evidence')->count())->toBe(0);
});

test('a real serialization failure retries the entire owner callback', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $input = $fixture->commitInput($fixture->prepare());
    $before = $fixture->snapshot()->stateVersion;
    $db = $this->app->make(VaulterDatabase::class)->connection();
    $attempts = 0;
    $db->beforeExecuting(static function (string $sql, array $bindings, Connection $connection) use (&$attempts): void {
        if (! str_starts_with($sql, 'insert into "v_ai_text_command_evidence"') || ++$attempts !== 1) {
            return;
        }
        $connection->unprepared("DO $$ BEGIN RAISE EXCEPTION 'retry office owner' USING ERRCODE = '40001'; END $$");
    });

    $result = $fixture->call(OfficeOperation::Commit, $input);
    expect($attempts)->toBe(2)
        ->and($result->result->marker->position)->toBe($before + 1)
        ->and($db->table('v_ai_text_commands')->value('receipt_id'))->toBe($result->result->receipt_id)
        ->and($db->table('v_ai_text_command_evidence')->count())->toBe(1)
        ->and($db->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(1);
})->group('engine:pgsql');

test('a writer waiting on the document lock rechecks authorization after access is revoked', function (): void {
    $fixture = new TextCommandFixture($this->app);
    $grant = $fixture->drive->access()->grant($fixture->node, new GrantInput(Subject::ofModel($fixture->actor), RoleRegistry::WRITER));
    $fixture->revoke();
    $input = $fixture->commitInput($fixture->prepare());
    $before = $fixture->snapshot()->stateVersion;
    DB::disconnect('testing');
    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $worker = pcntl_fork();
    expect($worker)->toBeGreaterThanOrEqual(0);
    if ($worker === 0) {
        fclose($parent);
        fread($child, 1);
        try {
            fwrite($child, DB::connection('testing')->scalar('SELECT pg_backend_pid()')."\n");
            $office = $fixture->call(OfficeOperation::Commit, $input);
            fwrite($child, $office->errorCode?->value ?? 'committed');
        } catch (Throwable $failure) {
            fwrite($child, $failure::class.' '.$failure->getMessage());
        }
        fclose($child);
        posix_kill(getmypid(), SIGKILL);
    }

    fclose($child);
    stream_set_timeout($parent, 10);
    $vaulter = $this->app->make(VaulterDatabase::class);
    try {
        $vaulter->transaction(function (Connection $connection) use ($fixture, $grant, $parent): void {
            $fixture->drive->access()->revoke($fixture->node, $grant);
            $revoker = $connection->scalar('SELECT pg_backend_pid()');
            fwrite($parent, '1');
            $writer = (int) fgets($parent);
            $deadline = microtime(true) + 5;
            do {
                $blocked = $connection->scalar('SELECT ? = ANY(pg_blocking_pids(?))', [$revoker, $writer]);
                if ($blocked) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            expect($blocked)->toBeTrue('The writer must wait for the revoker before resolving its final Edit grant.');
        });
        expect(stream_get_contents($parent))->toBe(ErrorCode::ResourceUnavailable->value);
    } finally {
        fclose($parent);
        if (pcntl_waitpid($worker, $status, WNOHANG) === 0) {
            posix_kill($worker, SIGKILL);
            pcntl_waitpid($worker, $status);
        }
    }
    expect($fixture->snapshot()->stateVersion)->toBe($before)
        ->and($vaulter->connection()->table('v_ai_text_commands')->count())->toBe(0)
        ->and($vaulter->connection()->table('v_outbox')->where('type', 'office.text_command_committed')->count())->toBe(0);
})->group('engine:pgsql');
