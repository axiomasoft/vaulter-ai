<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Vaulter\Ai\Core\Data\OfficeRunStep;
use Vaulter\Ai\Core\Enums\OfficeOperation;

/**
 * Pure reducer computing the authoritative v_ai_runs state from persisted v_ai_run_steps.
 */
final readonly class RunStateReducer
{
    /**
     * @param  list<OfficeRunStep>  $steps
     * @return 'awaiting_approval'|'partially_applied'|'completed'|'rejected'|'reconcile'
     */
    public function reduce(array $steps, bool $executionFailed = false): string
    {
        $hasCommit = false;
        $hasWaitingApproval = false;
        $hasWaitingOther = false;
        $hasCommitFailed = false;
        /** @var list<string> $committedReceipts */
        $committedReceipts = [];

        $hasVerifyStep = false;
        $hasVerifyFailed = false;
        /** @var array<string, true> $verifiedReceipts */
        $verifiedReceipts = [];

        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Commit) {
                $hasCommit = true;
                if ($step->state === 'waiting') {
                    if ($step->safeError === 'approval_required') {
                        $hasWaitingApproval = true;
                    } else {
                        $hasWaitingOther = true;
                    }
                } elseif ($step->state === 'failed') {
                    $hasCommitFailed = true;
                } elseif ($step->state === 'finished' || $step->state === 'verified') {
                    $committedReceipts[] = $step->receiptId ?? '';
                }

                continue;
            }

            if ($step->operation === OfficeOperation::Verify) {
                $hasVerifyStep = true;
                $ok = ($step->compactResult['ok'] ?? null) === true;
                $result = $step->compactResult['result'] ?? null;
                $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
                $verifiedReceipt = is_array($result) && is_string($result['receipt_id'] ?? null)
                    ? $result['receipt_id']
                    : $step->receiptId;

                if ($step->state === 'failed' || ! $ok || $outcome !== 'passed') {
                    $hasVerifyFailed = true;
                } elseif ($step->state === 'verified' && is_string($verifiedReceipt) && $verifiedReceipt !== '') {
                    $verifiedReceipts[$verifiedReceipt] = true;
                }
            }
        }

        if ($hasWaitingApproval) {
            return 'awaiting_approval';
        }
        if ($hasWaitingOther) {
            return 'reconcile';
        }

        $hasCommitted = $committedReceipts !== [];
        if ($hasCommitFailed && ! $hasCommitted) {
            return 'rejected';
        }
        if ($hasCommit && ! $hasCommitted) {
            return 'reconcile';
        }
        if ($hasVerifyFailed) {
            return $hasCommitted ? 'partially_applied' : 'rejected';
        }
        if ($hasCommitted && $hasVerifyStep) {
            foreach ($committedReceipts as $receiptId) {
                if ($receiptId === '' || ! isset($verifiedReceipts[$receiptId])) {
                    return 'partially_applied';
                }
            }
        }
        if ($executionFailed) {
            return $hasCommitted ? 'partially_applied' : 'rejected';
        }

        return 'completed';
    }
}
