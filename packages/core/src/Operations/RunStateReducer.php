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
     * @param  list<string>  $expectedResources
     * @return 'awaiting_approval'|'partially_applied'|'completed'|'rejected'|'reconcile'
     */
    public function reduce(array $steps, bool $executionFailed = false, array $expectedResources = []): string
    {
        /** @var array<string, 'verified'|'failed'> $verifyByReceipt */
        $verifyByReceipt = [];
        /** @var array<string, OfficeRunStep> $latestCommitByResource */
        $latestCommitByResource = [];
        /** @var array<string, OfficeRunStep> $latestPrepareByResource */
        $latestPrepareByResource = [];
        $commitStepsCount = 0;

        foreach ($steps as $step) {
            $resourceKey = $step->resourceId ?? ('proposal:'.($step->proposalId ?? 'step:'.$step->stepId));

            if ($step->operation === OfficeOperation::Prepare) {
                $latestPrepareByResource[$resourceKey] = $step;

                continue;
            }

            if ($step->operation === OfficeOperation::Commit) {
                $commitStepsCount++;
                $latestCommitByResource[$resourceKey] = $step;

                continue;
            }

            if ($step->operation === OfficeOperation::Verify) {
                $ok = ($step->compactResult['ok'] ?? null) === true;
                $result = $step->compactResult['result'] ?? null;
                $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
                $verifiedReceipt = is_array($result) && is_string($result['receipt_id'] ?? null)
                    ? $result['receipt_id']
                    : $step->receiptId;

                if (is_string($verifiedReceipt) && $verifiedReceipt !== '') {
                    $verifyByReceipt[$verifiedReceipt] = ($step->state === 'verified' && $ok && $outcome === 'passed')
                        ? 'verified'
                        : 'failed';
                } else {
                    $verifyByReceipt['__step:'.$step->stepId] = 'failed';
                }
            }
        }

        $hasWaitingApproval = false;
        $hasWaitingOther = false;
        $hasInFlight = false;
        $hasCommitFailed = false;
        $hasVerifyFailed = false;
        $hasUnverifiedCommit = false;
        $hasRemainingResource = false;
        $committedCount = 0;
        $hasVerifyStep = $verifyByReceipt !== [];

        foreach ($verifyByReceipt as $status) {
            if ($status === 'failed') {
                $hasVerifyFailed = true;
            }
        }

        foreach ($latestCommitByResource as $resourceKey => $step) {
            $prep = $latestPrepareByResource[$resourceKey] ?? null;
            if ($prep !== null && $prep->stepId > $step->stepId) {
                $prepResult = is_array($prep->compactResult['result'] ?? null) ? $prep->compactResult['result'] : [];
                if ($prep->state === 'failed') {
                    $hasRemainingResource = true;
                } elseif (($prepResult['approval'] ?? null) === 'human') {
                    $hasWaitingApproval = true;
                } else {
                    $hasRemainingResource = true;
                }
            }

            if ($step->state === 'waiting') {
                if ($step->safeError === 'approval_required') {
                    $hasWaitingApproval = true;
                } else {
                    $hasWaitingOther = true;
                }
            } elseif (in_array($step->state, ['pending', 'dispatched'], true)) {
                $hasInFlight = true;
            } elseif ($step->state === 'failed') {
                $hasCommitFailed = true;
            } elseif ($step->state === 'finished' || $step->state === 'verified') {
                $committedCount++;
                $receiptId = $step->receiptId ?? '';
                $verifyStatus = $receiptId !== '' ? ($verifyByReceipt[$receiptId] ?? null) : null;
                if ($verifyStatus === 'failed') {
                    $hasVerifyFailed = true;
                } elseif ($hasVerifyStep && ($receiptId === '' || $verifyStatus !== 'verified')) {
                    $hasUnverifiedCommit = true;
                }
            }
        }

        foreach ($latestPrepareByResource as $resourceKey => $prep) {
            if (isset($latestCommitByResource[$resourceKey])) {
                continue;
            }
            $prepResult = is_array($prep->compactResult['result'] ?? null) ? $prep->compactResult['result'] : [];
            if ($prep->state === 'failed') {
                $hasRemainingResource = true;
            } elseif (($prepResult['approval'] ?? null) === 'human') {
                $hasWaitingApproval = true;
            } else {
                $hasRemainingResource = true;
            }
        }

        foreach ($expectedResources as $expectedResourceId) {
            if ($expectedResourceId !== '' && ! isset($latestCommitByResource[$expectedResourceId])) {
                $hasRemainingResource = true;
            }
        }

        if ($hasInFlight || $hasWaitingOther) {
            return 'reconcile';
        }
        if ($hasWaitingApproval) {
            return 'awaiting_approval';
        }
        if ($committedCount === 0) {
            if ($hasCommitFailed || $hasVerifyFailed || $executionFailed) {
                return 'rejected';
            }
            if ($commitStepsCount > 0 || $hasRemainingResource) {
                return 'reconcile';
            }

            return 'completed';
        }
        if ($hasCommitFailed || $hasVerifyFailed || $hasUnverifiedCommit || $hasRemainingResource || $executionFailed) {
            return 'partially_applied';
        }

        return 'completed';
    }
}
