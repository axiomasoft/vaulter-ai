<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Actions;

use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Enums\ApprovalMode;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Jobs\DispatchApprovedCommand;
use Vaulter\Ai\Core\Jobs\ExecuteChatRun;
use Vaulter\Ai\Core\Operations\Commit;
use Vaulter\Ai\Core\Persistence\DefaultProposalApprovalPolicy;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Workspace\ChannelChatBridge;

/** For the host's authenticated approval application. The two contexts are resolved by the host. */
final readonly class ResolveProposal
{
    private ProposalApprovalPolicy $approvalPolicy;

    public function __construct(
        private OfficeDatabase $db,
        private Proposals $proposals,
        private OwnerRoutes $routes,
        private ResourceDirectory $directory,
        private Commit $commit,
        private Dispatcher $bus,
        private Clock $clock,
        ?ProposalApprovalPolicy $approvalPolicy = null,
        private ?Runs $runs = null,
        private ?ChannelChatBridge $channelBridge = null,
    ) {
        $this->approvalPolicy = $approvalPolicy ?? new DefaultProposalApprovalPolicy($this->directory);
    }

    /** Returns false for an already decided proposal. A matching approval retry can republish its ready dispatch. */
    public function resolve(CommandScope $scope, string $proposalId, string $digest, CallContext $approver, bool $approved): bool
    {
        $initiator = $scope->context;
        if (($approver->attributes['office_subagent'] ?? null) === true
            || $approver->tenantId !== $initiator->tenantId || $approver->projectScope !== $initiator->projectScope
            || $this->directory->findAccessible($approver, $scope->resourceId, 'view') === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $this->db->transaction(function () use ($scope, $proposalId, $digest, $approver, $approved, $initiator): bool {
            // 1. Lock P before evaluating clock_timestamp() and reviewer/initiator permissions.
            $proposal = $this->proposals->findInTransaction($initiator, $scope->resourceId, $proposalId, lock: true);
            $reviewerResource = $this->directory->findAccessible($approver, $scope->resourceId, 'view');
            if ($proposal === null || ! $proposal->hasPayload || $reviewerResource === null
                || ! $this->approvalPolicy->canApprove($approver, $reviewerResource, $proposal)) {
                throw OfficeException::resourceUnavailable();
            }
            $plan = $proposal->plan;
            if ($plan->approval !== ApprovalMode::Human || ! hash_equals($plan->digest, $digest)) {
                throw OfficeException::preconditionFailed();
            }
            if ($proposal->decision !== 'pending') {
                if ($approved && $proposal->decision === 'approved'
                    && $this->db->query('ai_dispatches')->where('proposal_id', $proposalId)->where('state', 'ready')->exists()
                    && $this->routes->find($scope)?->proposalId === $proposalId) {
                    // A previous approval may have committed before its job reached the queue.
                    $this->publishAfterCommit($initiator->tenantId, $proposalId);
                }

                return false;
            }

            $this->commit->authorize($scope, $proposal);

            // 2. Check expiry after acquiring the row lock using authoritative PG clock_timestamp().
            $now = $this->db->clockNow($this->clock);
            if (new DateTimeImmutable($plan->expiresAt) <= $now) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }

            // 3. Conditional CAS update from pending -> approved/rejected.
            if (! $this->proposals->decide($initiator, $scope->resourceId, $proposalId, $approver->effectiveSubject, $approved, $now)) {
                throw new OfficeException(ErrorCode::ProposalExpired);
            }
            if ($approved) {
                $this->routes->bindInTransaction($scope, $plan);
                $this->db->query('ai_dispatches')->insert([
                    'event_id' => $this->db->newUuid(),
                    'proposal_id' => $proposalId,
                    'available_at' => $this->db->utcTimestamp($now),
                ]);
                $this->publishAfterCommit($initiator->tenantId, $proposalId);
            } else {
                if ($this->runs !== null) {
                    $this->runs->syncProposalOutcomeInTransaction(
                        context: $initiator,
                        proposalId: $proposalId,
                        stepState: 'failed',
                        safeError: 'proposal_rejected',
                    );
                }
                $this->publishRejectedChatResumes($initiator, $proposalId);
            }

            return true;
        }, $initiator->tenantId);
    }

    private function publishAfterCommit(string $tenantId, string $proposalId): void
    {
        $this->db->afterCommit(function () use ($tenantId, $proposalId): void {
            $this->bus->dispatch(new DispatchApprovedCommand($tenantId, $proposalId));
        });
    }

    private function publishRejectedChatResumes(CallContext $initiator, string $proposalId): void
    {
        $chatRunIds = $this->findLinkedChatRunIds($initiator, $proposalId);
        $externalLinks = $this->channelBridge !== null ? $this->findLinkedExternalChatSessions($initiator, $proposalId) : [];
        if ($chatRunIds === [] && $externalLinks === []) {
            return;
        }

        $this->db->afterCommit(function () use ($initiator, $chatRunIds, $externalLinks): void {
            foreach ($chatRunIds as $chatRunId) {
                $this->bus->dispatch(new ExecuteChatRun($initiator->tenantId, $chatRunId));
            }
            foreach ($externalLinks as $link) {
                $this->channelBridge?->syncExternalRunEvents($initiator, $link['session_id'], $link['run_id'], $link['scope']);
            }
        });
    }

    /** @return list<array{session_id:string, run_id:string, scope:list<string>}> */
    private function findLinkedExternalChatSessions(CallContext $initiator, string $proposalId): array
    {
        $stepsTable = $this->db->table('ai_run_steps');
        $runsTable = $this->db->table('ai_runs');
        $msgsTable = $this->db->table('ai_chat_messages');
        $rows = $this->db->connection()->table($stepsTable)
            ->join($runsTable, $runsTable.'.run_id', '=', $stepsTable.'.run_id')
            ->join($msgsTable, $msgsTable.'.run_id', '=', $runsTable.'.run_id')
            ->where($msgsTable.'.role', 'user')
            ->where($stepsTable.'.proposal_id', $proposalId)
            ->where($runsTable.'.tenant_id', $initiator->tenantId)
            ->where($runsTable.'.project_scope', $initiator->projectScope)
            ->where($runsTable.'.effective_subject', $initiator->effectiveSubject)
            ->where($runsTable.'.channel', '!=', 'chat')
            ->distinct()
            ->get([$msgsTable.'.session_id', $runsTable.'.run_id', $msgsTable.'.resource_scope_json']);

        $links = [];
        foreach ($rows as $row) {
            $sessionId = QueryRecord::str($row, 'session_id');
            $runId = QueryRecord::str($row, 'run_id');
            $rawScope = json_decode(QueryRecord::str($row, 'resource_scope_json'), true);
            if ($sessionId !== '' && $runId !== '' && is_array($rawScope)) {
                /** @var list<string> $scope */
                $scope = array_values(array_filter($rawScope, is_string(...)));
                $links[] = ['session_id' => $sessionId, 'run_id' => $runId, 'scope' => $scope];
            }
        }

        return $links;
    }

    /** @return list<string> */
    private function findLinkedChatRunIds(CallContext $initiator, string $proposalId): array
    {
        $callsTable = $this->db->table('ai_tool_calls');
        $runsTable = $this->db->table('ai_runs');
        $rows = $this->db->connection()->table($callsTable)
            ->join($runsTable, $runsTable.'.run_id', '=', $callsTable.'.run_id')
            ->where($callsTable.'.proposal_id', $proposalId)
            ->where($runsTable.'.tenant_id', $initiator->tenantId)
            ->where($runsTable.'.project_scope', $initiator->projectScope)
            ->where($runsTable.'.channel', 'chat')
            ->whereNotIn($runsTable.'.state', ['completed', 'rejected'])
            ->distinct()
            ->get([$callsTable.'.run_id']);

        $ids = [];
        foreach ($rows as $row) {
            $rid = QueryRecord::str($row, 'run_id');
            if ($rid !== '') {
                $ids[] = $rid;
            }
        }

        return $ids;
    }
}
