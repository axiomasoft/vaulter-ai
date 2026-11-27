<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vaulter\Ai\Collaboration\Tests\Support\LiveJournalTestCase;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;

uses(LiveJournalTestCase::class);

test('recovers strict live-journal commit after Hub A42 settle and SIGKILL before receipt persistence, and verifies current with real Hub verifier and relative-position anchors', function (): void {
    $fixture = $this->openStrictJournalFixture();
    $proposal = $fixture->prepare('15', '30', 'p42');

    $firstCommit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'cmd-a42-1'));
    expect($firstCommit->ok)->toBeTrue()
        ->and($firstCommit->result->state)->toBe('running');

    $ownerUuid = (string) Str::uuid();
    $claimed = $this->hubAcquireAndClaimNext($ownerUuid);

    $settleResp = $this->hubSettleReplacementCommit($claimed);
    $settleResp->assertOk();
    $settledReceiptId = (string) $settleResp->json('receipt_id');
    expect($settledReceiptId)->not->toBe('')
        ->and($settleResp->json('state'))->toBe('committed');

    $driver = DB::connection()->getDriverName();
    if ($driver === 'pgsql') {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl extension is required for PG SIGKILL recovery test')
            ->and(function_exists('posix_kill'))->toBeTrue('posix extension is required for PG SIGKILL recovery test');

        $hookMarker = sys_get_temp_dir().'/vai-sigkill-hook-'.bin2hex(random_bytes(8)).'.flag';
        try {
            // Disconnect inherited PDO handles before fork so child and parent use independent PG sockets.
            DB::disconnect();
            $pid = pcntl_fork();
            expect($pid)->toBeGreaterThanOrEqual(0);

            if ($pid === 0) {
                DB::purge();
                DB::connection()->beforeExecuting(static function (string $sql) use ($hookMarker): void {
                    if (str_contains($sql, 'ai_owner_routes') && str_contains(strtolower($sql), 'update') && str_contains($sql, 'receipt_id')) {
                        file_put_contents($hookMarker, 'reached');
                        posix_kill(getmypid(), SIGKILL);
                    }
                });

                $fixture->call(OfficeOperation::Status, ['command_id' => 'cmd-a42-1']);
                exit(1);
            }

            $status = 0;
            pcntl_waitpid($pid, $status);
            DB::purge();
            expect(pcntl_wifsignaled($status))->toBeTrue()
                ->and(pcntl_wtermsig($status))->toBe(SIGKILL)
                ->and(is_file($hookMarker))->toBeTrue('Receipt-update hook was never reached before process exit')
                ->and(file_get_contents($hookMarker))->toBe('reached');
        } finally {
            @unlink($hookMarker);
        }

        // Route must still hold no settled receipt immediately after the worker SIGKILL.
        $routeBeforeRecovery = $this->app->make(OwnerRoutes::class)->forProposal($fixture->context, $proposal->proposal_id);
        expect($routeBeforeRecovery)->not->toBeNull()
            ->and($routeBeforeRecovery->receiptId)->toBeNull();
    } else {
        // SQLite :memory: cannot share memory across forked processes; prove the real receipt-update hook fires before aborting.
        $hookReached = false;
        $faultArmed = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$hookReached, &$faultArmed): void {
            if ($faultArmed && str_contains($sql, 'ai_owner_routes') && str_contains(strtolower($sql), 'update') && str_contains($sql, 'receipt_id')) {
                $hookReached = true;
                $faultArmed = false;
                throw new RuntimeException('simulated crash before receipt update');
            }
        });

        expect(fn () => $fixture->call(OfficeOperation::Status, ['command_id' => 'cmd-a42-1']))
            ->toThrow(RuntimeException::class, 'simulated crash before receipt update');
        expect($hookReached)->toBeTrue();

        $routeBeforeRecovery = $this->app->make(OwnerRoutes::class)->forProposal($fixture->context, $proposal->proposal_id);
        expect($routeBeforeRecovery)->not->toBeNull()
            ->and($routeBeforeRecovery->receiptId)->toBeNull();
    }

    // Retrying office.commit with identical proposal + command_id AND polling office.status must recover exact receipt without duplicating Hub commands.
    $retryCommit = $fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'cmd-a42-1'));
    expect($retryCommit->ok)->toBeTrue()
        ->and($retryCommit->result->receipt_id)->toBe($settledReceiptId)
        ->and($retryCommit->result->state)->toBe('committed');

    $recoveredStatus = $fixture->call(OfficeOperation::Status, ['command_id' => 'cmd-a42-1']);
    expect($recoveredStatus->ok)->toBeTrue()
        ->and($recoveredStatus->result->state)->toBe('committed')
        ->and($recoveredStatus->result->receipt_id)->toBe($settledReceiptId)
        ->and(DB::table('v_collaboration_commands')->where('document_id', $claimed['document_id'])->count())->toBe(1)
        ->and($this->app->make(OwnerRoutes::class)->forProposal($fixture->context, $proposal->proposal_id)?->receiptId)->toBe($settledReceiptId);

    // 1. at_commit verify succeeds using durable Hub A42 evidence.
    $verifyAtCommit = $fixture->call(OfficeOperation::Verify, [
        'receipt_id' => $settledReceiptId,
        'mode' => 'at_commit',
    ]);
    expect($verifyAtCommit->ok)->toBeTrue()
        ->and($verifyAtCommit->result->outcome)->toBe('passed');

    // 2. Append a real concurrent Yjs prefix insertion into the journal (+8 UTF-16 units) and verify(current) via the real Hub HTTP verifier.
    $seqAfterPrefix = $this->hubAppendConcurrentTextEdit(
        $claimed,
        'p42',
        'insert_prefix',
        ['prefix' => 'URGENT: '],
        0x80000001,
    );
    expect($seqAfterPrefix)->toBe(3);

    $verifyCurrentPassed = $fixture->call(OfficeOperation::Verify, [
        'receipt_id' => $settledReceiptId,
        'mode' => 'current',
    ]);
    expect($verifyCurrentPassed->ok)->toBeTrue()
        ->and($verifyCurrentPassed->result->outcome)->toBe('passed')
        ->and($verifyCurrentPassed->result->marker->generation)->toBe($claimed['generation'])
        ->and($verifyCurrentPassed->result->marker->position)->toBe($seqAfterPrefix)
        ->and($verifyCurrentPassed->result->checks[0]->actual)->toBe('30')
        ->and($verifyCurrentPassed->result->checks[0]->passed)->toBeTrue()
        ->and($verifyCurrentPassed->result->checks[1]->actual)->toBe(['bold'])
        ->and($verifyCurrentPassed->result->checks[1]->passed)->toBeTrue();

    // 3. Append a real concurrent Yjs target overwrite ('30' -> '45') and verify(current) via the real Hub verifier reports failed cleanly.
    $seqAfterOverwrite = $this->hubAppendConcurrentTextEdit(
        $claimed,
        'p42',
        'overwrite_target',
        ['expected' => '30', 'replacement' => '45'],
        0x80000002,
    );
    expect($seqAfterOverwrite)->toBe(4);

    $verifyCurrentFailed = $fixture->call(OfficeOperation::Verify, [
        'receipt_id' => $settledReceiptId,
        'mode' => 'current',
    ]);
    expect($verifyCurrentFailed->ok)->toBeTrue()
        ->and($verifyCurrentFailed->result->outcome)->toBe('failed')
        ->and($verifyCurrentFailed->result->marker->position)->toBe($seqAfterOverwrite)
        ->and($verifyCurrentFailed->result->checks[0]->passed)->toBeFalse()
        ->and($verifyCurrentFailed->result->checks[0]->actual)->toBe('45');
});

test('fails closed on verify(current) when document generation changes after Hub A42 commit', function (): void {
    $fixture = $this->openStrictJournalFixture();
    $proposal = $fixture->prepare('15', '30', 'p42');

    expect($fixture->call(OfficeOperation::Commit, $fixture->commitInput($proposal, 'cmd-a42-gen'))->ok)->toBeTrue();
    $claimed = $this->hubAcquireAndClaimNext((string) Str::uuid());
    $settleResp = $this->hubSettleReplacementCommit($claimed);
    $settleResp->assertOk();
    $receiptId = (string) $settleResp->json('receipt_id');

    $settled = $fixture->call(OfficeOperation::Status, ['command_id' => 'cmd-a42-gen']);
    expect($settled->ok)->toBeTrue()
        ->and($settled->result->state)->toBe('committed')
        ->and($settled->result->receipt_id)->toBe($receiptId);

    // Advance the Hub room log generation and document collab_epoch to generation + 1; real Hub /verify-command returns 409 -> GenerationChanged.
    $this->syncHubVerifierLog($claimed['node_id'], $claimed['generation'], overrideGeneration: $claimed['generation'] + 1);
    DB::table('v_documents')->where('id', $claimed['document_id'])->update(['collab_epoch' => $claimed['generation'] + 1]);

    $verifyStale = $fixture->call(OfficeOperation::Verify, [
        'receipt_id' => $receiptId,
        'mode' => 'current',
    ]);
    expect($verifyStale->ok)->toBeFalse()
        ->and($verifyStale->errorCode)->toBe(ErrorCode::GenerationChanged);
});
