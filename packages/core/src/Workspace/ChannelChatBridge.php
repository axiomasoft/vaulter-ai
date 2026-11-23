<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\OwnerRoutes;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\QueryRecord;
use Vaulter\Ai\Core\Persistence\Runs;

/**
 * Links external channel sessions and webhook/streaming events (partial/final) to a durable
 * ChatWorkspace session with idempotent event_id -> run_id binding across profiles.
 */
final readonly class ChannelChatBridge
{
    private const string CHANNEL_PATTERN = '/\A[a-z0-9][a-z0-9._:-]{0,63}\z/';

    private const string ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/';

    public function __construct(
        private OfficeDatabase $db,
        private ChatWorkspace $chat,
        private ResourceDirectory $resources,
        private Clock $clock,
        private ?Runs $runs = null,
        private ?OwnerRoutes $routes = null,
        private ?Proposals $proposals = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $selection
     * @return array{
     *     session_id: string,
     *     chat_session_id: string,
     *     event_id: string,
     *     phase: 'partial'|'final',
     *     channel: string,
     *     published: bool,
     *     duplicate: bool,
     *     run_id: ?string,
     * }
     */
    public function ingest(
        CallContext $context,
        string $channel,
        string $externalSessionId,
        string $eventId,
        string $phase,
        string $resourceId,
        ?string $content = null,
        ?array $selection = null,
        int $retentionSeconds = 86400,
    ): array {
        if (preg_match(self::CHANNEL_PATTERN, $channel) !== 1
            || preg_match(self::ID_PATTERN, $externalSessionId) !== 1
            || preg_match(self::ID_PATTERN, $eventId) !== 1
            || ($phase !== 'partial' && $phase !== 'final')
            || $resourceId === ''
            || strlen($resourceId) > 255
            || str_contains($resourceId, "\0")
            || ! mb_check_encoding($resourceId, 'UTF-8')) {
            throw OfficeException::invalidInput();
        }

        $permission = $phase === 'final' ? 'edit' : 'view';
        if ($this->resources->findAccessible($context, $resourceId, $permission) === null) {
            throw OfficeException::resourceUnavailable();
        }

        return $this->db->transaction(function () use (
            $context,
            $channel,
            $externalSessionId,
            $eventId,
            $phase,
            $resourceId,
            $content,
            $selection,
            $retentionSeconds,
        ): array {
            $chatSessionId = $this->resolveOrOpenChatSession($context, $channel, $externalSessionId, $retentionSeconds);

            if ($phase === 'partial') {
                return [
                    'session_id' => $externalSessionId,
                    'chat_session_id' => $chatSessionId,
                    'event_id' => $eventId,
                    'phase' => 'partial',
                    'channel' => $channel,
                    'published' => false,
                    'duplicate' => false,
                    'run_id' => null,
                ];
            }

            $prompt = $content !== null && trim($content) !== ''
                ? $content
                : "Process {$channel} channel event {$eventId} on {$resourceId}.";

            $alreadyPosted = $this->db->query('ai_chat_messages')
                ->where('session_id', $chatSessionId)
                ->where('message_id', $eventId)
                ->exists();

            $runId = $this->chat->post(
                $context,
                $chatSessionId,
                $eventId,
                $prompt,
                [$resourceId],
                $selection,
            );

            $now = $this->db->utcTimestamp($this->db->clockNow($this->clock));
            $this->db->query('ai_channel_sessions')
                ->where([
                    'tenant_id' => $context->tenantId,
                    'project_scope' => $context->projectScope,
                    'channel' => $channel,
                    'external_session_id' => $externalSessionId,
                ])
                ->update(['updated_at' => $now]);

            return [
                'session_id' => $externalSessionId,
                'chat_session_id' => $chatSessionId,
                'event_id' => $eventId,
                'phase' => 'final',
                'channel' => $channel,
                'published' => true,
                'duplicate' => $alreadyPosted,
                'run_id' => $runId,
            ];
        }, $context->tenantId);
    }

    public function resolveChatSessionId(CallContext $context, string $channel, string $externalSessionId): ?string
    {
        $row = $this->db->query('ai_channel_sessions')
            ->where([
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'channel' => $channel,
                'external_session_id' => $externalSessionId,
            ])
            ->first();

        if ($row === null || QueryRecord::str($row, 'owner_subject') !== $context->effectiveSubject) {
            return null;
        }

        return QueryRecord::str($row, 'chat_session_id');
    }

    /**
     * Resolve or open the durable chat session bound to an external channel session without starting a run.
     */
    public function resolveOrOpenExternalSession(
        CallContext $context,
        string $channel,
        string $externalSessionId,
        int $retentionSeconds = 86400,
    ): string {
        if (preg_match(self::CHANNEL_PATTERN, $channel) !== 1 || preg_match(self::ID_PATTERN, $externalSessionId) !== 1) {
            throw OfficeException::invalidInput();
        }

        return $this->db->transaction(
            fn (): string => $this->resolveOrOpenChatSession($context, $channel, $externalSessionId, $retentionSeconds),
            $context->tenantId,
        );
    }

    /**
     * Bind an already dispatched external channel run (laravel-ai / agentools) and idempotently mirror
     * its step lifecycle into encrypted ChatWorkspace events.
     *
     * @param  list<string>  $scope
     * @param  array<string, mixed>|null  $selection
     */
    public function linkAndSyncExternalRun(
        CallContext $context,
        string $channel,
        string $externalSessionId,
        string $eventId,
        string $runId,
        array $scope,
        ?string $content = null,
        ?array $selection = null,
        int $retentionSeconds = 86400,
    ): string {
        $chatSessionId = $this->resolveOrOpenExternalSession($context, $channel, $externalSessionId, $retentionSeconds);
        $resourceSummary = $scope[0] ?? 'resource';
        $prompt = $content !== null && trim($content) !== ''
            ? $content
            : "Process {$channel} channel event {$eventId} on {$resourceSummary}.";

        $this->chat->linkExternalRunMessage(
            context: $context,
            sessionId: $chatSessionId,
            messageId: $eventId,
            runId: $runId,
            content: $prompt,
            scope: $scope,
            selection: $selection,
        );

        $now = $this->db->utcTimestamp($this->db->clockNow($this->clock));
        $this->db->query('ai_channel_sessions')
            ->where([
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'channel' => $channel,
                'external_session_id' => $externalSessionId,
            ])
            ->update(['updated_at' => $now]);

        $this->syncExternalRunEvents($context, $chatSessionId, $runId, $scope);

        return $chatSessionId;
    }

    /**
     * @param  list<string>  $scope
     */
    public function syncExternalRunEvents(CallContext $context, string $chatSessionId, string $runId, array $scope): void
    {
        if ($this->runs === null) {
            return;
        }
        $run = $this->runs->find($context, $runId);
        if ($run === null) {
            return;
        }

        $steps = $this->runs->listSteps($context, $runId);
        $hasCommitStep = false;
        foreach ($steps as $step) {
            if ($step->operation === OfficeOperation::Commit) {
                $hasCommitStep = true;
            }
        }

        foreach ($steps as $step) {
            $callId = 'ext-step-'.$runId.'-'.$step->stepId;
            $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'tool.requested', [
                'call_id' => $callId,
                'tool' => $step->operation->toolName(),
            ], $scope);

            if ($step->operation === OfficeOperation::Prepare && $step->proposalId !== null) {
                $compactResult = is_array($step->compactResult['result'] ?? null) ? $step->compactResult['result'] : [];
                $approval = $compactResult['approval'] ?? null;
                if ($approval === 'human') {
                    $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'approval.required', [
                        'call_id' => $callId,
                        'proposal_id' => $step->proposalId,
                        'status' => 403,
                    ], $scope);
                }

                if (! $hasCommitStep) {
                    $route = $this->routes?->forProposal($context, $step->proposalId);
                    if ($route !== null && $route->receiptId !== null) {
                        $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'tool.committed', [
                            'call_id' => $callId,
                            'proposal_id' => $step->proposalId,
                            'command_id' => $route->commandId,
                            'receipt_id' => $route->receiptId,
                        ], $scope);
                    } else {
                        $targetResource = $step->resourceId ?? ($scope[0] ?? '');
                        $proposal = $targetResource !== '' ? $this->proposals?->find($context, $targetResource, $step->proposalId) : null;
                        if ($proposal !== null && $proposal->decision === 'rejected') {
                            $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'run.finished', [
                                'state' => 'rejected',
                                'error' => 'proposal_rejected',
                            ], $scope);
                        }
                    }
                }
            }

            if ($step->operation === OfficeOperation::Commit && $step->receiptId !== null && $step->state === 'finished') {
                $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'tool.committed', [
                    'call_id' => $callId,
                    'proposal_id' => $step->proposalId,
                    'command_id' => $step->commandId,
                    'receipt_id' => $step->receiptId,
                ], $scope);
            } elseif ($step->operation === OfficeOperation::Commit && $step->state === 'failed' && $step->safeError === 'proposal_rejected') {
                $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'run.finished', [
                    'state' => 'rejected',
                    'error' => 'proposal_rejected',
                ], $scope);
            }

            if ($step->operation === OfficeOperation::Verify && $step->receiptId !== null) {
                $compactResult = is_array($step->compactResult['result'] ?? null) ? $step->compactResult['result'] : [];
                $outcome = $compactResult['outcome'] ?? ($step->state === 'verified' ? 'passed' : 'failed');
                $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'tool.verified', [
                    'call_id' => $callId,
                    'proposal_id' => $step->proposalId,
                    'command_id' => $step->commandId,
                    'receipt_id' => $step->receiptId,
                    'verification' => ['ok' => $outcome === 'passed', 'result' => ['outcome' => $outcome]],
                ], $scope);
            }
        }

        if (in_array($run->state, ['completed', 'rejected'], true)) {
            $this->chat->appendOnceForRun($context, $chatSessionId, $runId, 'run.finished', [
                'state' => $run->state,
                'error' => $run->state === 'rejected' ? 'rejected' : null,
            ], $scope);
        }
    }

    private function resolveOrOpenChatSession(CallContext $context, string $channel, string $externalSessionId, int $retentionSeconds): string
    {
        $existing = $this->db->query('ai_channel_sessions')
            ->where([
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'channel' => $channel,
                'external_session_id' => $externalSessionId,
            ])
            ->first();

        if ($existing !== null) {
            if (QueryRecord::str($existing, 'owner_subject') !== $context->effectiveSubject) {
                throw OfficeException::resourceUnavailable();
            }

            return QueryRecord::str($existing, 'chat_session_id');
        }

        $chatSessionId = $this->chat->open($context, $retentionSeconds);
        $now = $this->db->utcTimestamp($this->db->clockNow($this->clock));

        $this->db->query('ai_channel_sessions')->insertOrIgnore([
            'tenant_id' => $context->tenantId,
            'project_scope' => $context->projectScope,
            'channel' => $channel,
            'external_session_id' => $externalSessionId,
            'owner_subject' => $context->effectiveSubject,
            'chat_session_id' => $chatSessionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bound = $this->db->query('ai_channel_sessions')
            ->where([
                'tenant_id' => $context->tenantId,
                'project_scope' => $context->projectScope,
                'channel' => $channel,
                'external_session_id' => $externalSessionId,
            ])
            ->first() ?? throw OfficeException::temporarilyUnavailable();

        if (QueryRecord::str($bound, 'owner_subject') !== $context->effectiveSubject) {
            throw OfficeException::resourceUnavailable();
        }

        return QueryRecord::str($bound, 'chat_session_id');
    }
}
