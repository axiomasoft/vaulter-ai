<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Workspace;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Context;
use Throwable;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;

final readonly class ChatTurn
{
    private const int MAX_TOOL_CALLS = 32;

    private const int MAX_OUTPUT_BYTES = 65536;

    private const int DEFAULT_DELTA_BATCH_BYTES = 512;

    private const int DEFAULT_DELTA_BATCH_MS = 100;

    private const int SQLITE_CANCEL_POLL_NS = 100_000_000;

    private const int TRANSACTIONAL_CANCEL_POLL_NS = 1_000_000_000;

    public function __construct(
        private OfficeDatabase $db,
        private Runs $runs,
        private ChatWorkspace $chat,
        private ChatTools $tools,
        private ToolCallLedger $ledger,
        private HostPolicy $policy,
        private ?ConfigRepository $config = null,
        private ?ProviderSecretStore $secretStore = null,
    ) {}

    /** Returns the next delivery delay for a live or recoverable run. */
    public function execute(CallContext $context, string $runId, LlmPort $llm): ?int
    {
        $run = $this->runs->find($context, $runId) ?? throw OfficeException::resourceUnavailable();
        $session = $run->input['session_id'] ?? null;
        if ($run->channel !== 'chat' || ! is_string($session) || ($run->input['profile'] ?? null) !== $context->profile
            || in_array($run->state, ['completed', 'rejected', 'cancelled'], true)) {
            return null;
        }
        $lease = $this->runs->claimLease($context, $runId, ToolCallLedger::LEASE_SECONDS);
        if ($lease === null) {
            return 5;
        }

        Context::add([
            'vaulter.ai.tenant_id' => $context->tenantId,
            'vaulter.ai.project_scope' => $context->projectScope,
            'vaulter.ai.run_id' => $runId,
        ]);

        $scope = [];
        try {
            $input = $this->chat->turnInput($context, $session, $runId);
            $scope = $input['resource_ids'];
            $userMessage = ['role' => 'user', 'content' => $input['content']];
            if ($input['selection'] !== null) {
                $userMessage['selection'] = $input['selection'];
            }
            $messages = [...$this->chat->history($context, $session, $runId, $scope), $userMessage];
            if ($this->chat->cancellationRequested($context, $runId)) {
                $this->finish($context, $session, $runId, $lease, '', $scope);

                return null;
            }
            $calls = $this->ledger->calls($context, $runId, $lease);
            foreach ($calls as $call) {
                if ($this->chat->cancellationRequested($context, $runId)) {
                    $this->finish($context, $session, $runId, $lease, '', $scope);

                    return null;
                }
                $tool = $this->tools->deliver($context, $session, $runId, $lease, $call, $scope);
                $delay = $this->waitForTool($context, $session, $runId, $lease, $tool['state'], $scope);
                if ($delay !== null) {
                    return $delay === 0 ? null : $delay;
                }
                $messages[] = $tool['message'];
            }
            if (! in_array($run->state, ['queued', 'awaiting_approval'], true)) {
                $this->delta($context, $session, $runId, $lease, 'assistant.interrupted', [], $scope);
            }

            return $this->generate($llm, $context, $session, $runId, $lease, $messages, $scope, $calls);
        } catch (OfficeException $exception) {
            if (in_array($exception->errorCode, [ErrorCode::CursorExpired, ErrorCode::ResourceUnavailable], true)) {
                $this->runs->finishRun($context, $runId, $lease, 'rejected');

                return null;
            }
            if ($this->chat->cancellationRequested($context, $runId)) {
                $this->finish($context, $session, $runId, $lease, '', $scope);

                return null;
            }
            if ($exception->errorCode === ErrorCode::TemporarilyUnavailable) {
                $this->runs->releaseLease($context, $runId, $lease);

                return 5;
            }
            $this->finish($context, $session, $runId, $lease, '', $scope, $exception->errorCode);

            return null;
        } catch (Throwable $exception) {
            if ($this->chat->cancellationRequested($context, $runId)) {
                $this->finish($context, $session, $runId, $lease, '', $scope);

                return null;
            }
            $this->runs->releaseLease($context, $runId, $lease);
            if ($this->secretStore !== null) {
                $masked = $this->secretStore->maskText($exception->getMessage());
                if ($masked !== $exception->getMessage()) {
                    throw new \RuntimeException($masked, (int) $exception->getCode());
                }
            }

            throw $exception;
        } finally {
            Context::forget(['vaulter.ai.tenant_id', 'vaulter.ai.project_scope', 'vaulter.ai.run_id']);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $messages
     * @param  list<string>  $scope
     * @param  list<array{id:string, step_id:int, state:string}>  $calls
     */
    private function generate(
        LlmPort $llm, CallContext $context, string $session, string $runId, string $lease,
        array $messages, array $scope, array $calls,
    ): ?int {
        $text = '';
        $outputBytes = 0;
        $profile = $this->policy->profile($context);
        $budgeted = $profile->maxTokens !== null || $profile->maxCostMicros !== null;
        $inputTokens = 0;
        $outputTokens = 0;
        if ($budgeted) {
            foreach ($this->runs->listSteps($context, $runId) as $step) {
                $inputTokens += min(PHP_INT_MAX - $inputTokens, $step->inputTokens ?? 0);
                $outputTokens += min(PHP_INT_MAX - $outputTokens, $step->outputTokens ?? 0);
            }
            if ($profile->exceedsUsageBudget($inputTokens, $outputTokens)) {
                $this->finish($context, $session, $runId, $lease, '', $scope, 'budget_exceeded');

                return null;
            }
        }
        $connection = $profile->providerConnection ?? 'office_model';
        $key = $this->secretStore?->status($connection)['configured'] === true
            ? $this->secretStore->resolve($connection)->apiKey()
            : '';
        $redactor = new AssistantTextRedactor($key);
        $deliveries = 0;
        $maxToolCalls = $this->limitInt('vaulter-ai.chat.max_tool_calls', self::MAX_TOOL_CALLS, 1, self::MAX_TOOL_CALLS);
        $maxOutputBytes = $this->limitInt('vaulter-ai.chat.max_output_bytes', self::MAX_OUTPUT_BYTES, 1024, self::MAX_OUTPUT_BYTES);
        $deltaBatchBytes = $this->limitInt('vaulter-ai.chat.delta_batch_bytes', self::DEFAULT_DELTA_BATCH_BYTES, 1, self::MAX_OUTPUT_BYTES);
        $deltaBatchNs = $this->limitInt('vaulter-ai.chat.delta_batch_ms', self::DEFAULT_DELTA_BATCH_MS, 1, 10_000) * 1_000_000;
        $deltaBuffer = '';
        $deltaBufferStartedNs = null;

        $deadline = hrtime(true) + 70_000_000_000;
        $nextCancelCheck = 0;
        $cancelPollNs = $this->db->driver() === 'sqlite' ? self::SQLITE_CANCEL_POLL_NS : self::TRANSACTIONAL_CANCEL_POLL_NS;
        $requested = false;
        $cancelled = function () use ($deadline, $context, $runId, $cancelPollNs, &$nextCancelCheck, &$requested): bool {
            $now = hrtime(true);
            if ($now >= $deadline) {
                return true;
            }
            if (! $requested && $now >= $nextCancelCheck) {
                $requested = $this->chat->cancellationRequested($context, $runId);
                $nextCancelCheck = $now + $cancelPollNs;
            }

            return $requested;
        };
        while (! $cancelled()) {
            $hasUsage = false;
            $stream = $llm->stream($messages, $this->tools->catalog($context, $scope), $profile, $cancelled);
            foreach ($stream as $event) {
                if ($cancelled()) {
                    throw OfficeException::temporarilyUnavailable();
                }
                if ($event['type'] === 'toolInputStart' || $event['type'] === 'toolInputDelta') {
                    continue;
                }
                if ($event['type'] === 'usage') {
                    if ($hasUsage || $event['inputTokens'] < 0 || $event['outputTokens'] < 0) {
                        throw OfficeException::invalidInput();
                    }
                    $hasUsage = true;
                    $this->db->transaction(function () use ($context, $runId, $lease, $event): void {
                        $this->ledger->lockRun($context, $runId, $lease);
                        $this->runs->recordStep($context, $runId, OfficeOperation::Status, 'finished',
                            inputTokens: $event['inputTokens'], outputTokens: $event['outputTokens']);
                    }, $context->tenantId);
                    $inputTokens += min(PHP_INT_MAX - $inputTokens, $event['inputTokens']);
                    $outputTokens += min(PHP_INT_MAX - $outputTokens, $event['outputTokens']);
                    if ($profile->exceedsUsageBudget($inputTokens, $outputTokens)) {
                        $this->finish($context, $session, $runId, $lease, '', $scope, 'budget_exceeded');

                        return null;
                    }

                    continue;
                }
                if ($event['type'] === 'error') {
                    throw new OfficeException($event['code']);
                }
                if ($event['type'] === 'finish' || $event['type'] === 'toolInputComplete') {
                    if ($budgeted && ! $hasUsage) {
                        $this->finish($context, $session, $runId, $lease, '', $scope, 'usage_unavailable');

                        return null;
                    }
                }
                if ($event['type'] === 'finish') {
                    $tail = $redactor->finish();
                    if ($tail !== '') {
                        if (strlen($text) + strlen($tail) > $maxOutputBytes) {
                            throw new OfficeException(ErrorCode::LimitExceeded);
                        }
                        $text .= $tail;
                        $deltaBuffer .= $tail;
                    }
                    $this->flushDeltaBuffer($context, $session, $runId, $lease, $scope, $deltaBuffer, $deltaBufferStartedNs);
                    $this->finish($context, $session, $runId, $lease, $text, $scope);

                    return null;
                }
                if ($event['type'] === 'textDelta') {
                    $outputBytes += strlen($event['text']);
                    if ($outputBytes > $maxOutputBytes || ! mb_check_encoding($event['text'], 'UTF-8')) {
                        throw new OfficeException(ErrorCode::LimitExceeded);
                    }
                    $safe = $redactor->push($event['text']);
                    if ($safe !== '') {
                        if (strlen($text) + strlen($safe) > $maxOutputBytes) {
                            throw new OfficeException(ErrorCode::LimitExceeded);
                        }
                        $text .= $safe;
                        $deltaBuffer .= $safe;
                        $deltaBufferStartedNs ??= hrtime(true);
                        if (strlen($deltaBuffer) >= $deltaBatchBytes || (hrtime(true) - $deltaBufferStartedNs) >= $deltaBatchNs) {
                            $this->flushDeltaBuffer($context, $session, $runId, $lease, $scope, $deltaBuffer, $deltaBufferStartedNs);
                        }
                    }

                    continue;
                }
                if ($this->chat->cancellationRequested($context, $runId)) {
                    $this->finish($context, $session, $runId, $lease, '', $scope);

                    return null;
                }
                $replay = $event['replayCallId'] ?? null;
                if (++$deliveries > $maxToolCalls || ($replay === null && count($calls) >= $maxToolCalls)) {
                    throw new OfficeException(ErrorCode::LimitExceeded);
                }
                $index = $replay === null ? count($calls) : array_search($replay, array_column($calls, 'id'), true);
                if ($index === false) {
                    throw OfficeException::invalidInput();
                }
                $this->flushDeltaBuffer($context, $session, $runId, $lease, $scope, $deltaBuffer, $deltaBufferStartedNs);
                $this->tools->request($context, $session, $runId, $lease, $index + 1, $event['tool'], $event['input'], $scope);
                $calls = $this->ledger->calls($context, $runId, $lease);
                $call = $calls[$index];
                if ($this->chat->cancellationRequested($context, $runId)) {
                    $this->finish($context, $session, $runId, $lease, '', $scope);

                    return null;
                }
                $tool = $this->tools->deliver($context, $session, $runId, $lease, $call, $scope);
                $delay = $this->waitForTool($context, $session, $runId, $lease, $tool['state'], $scope);
                if ($delay !== null) {
                    return $delay === 0 ? null : $delay;
                }
                $messages[] = $tool['message'];
                unset($stream);

                continue 2;
            }

            // A provider disconnect must leave the turn resumable.
            $this->flushDeltaBuffer($context, $session, $runId, $lease, $scope, $deltaBuffer, $deltaBufferStartedNs);
            break;
        }

        throw OfficeException::temporarilyUnavailable();
    }

    /** @param list<string> $scope */
    private function flushDeltaBuffer(
        CallContext $context,
        string $session,
        string $runId,
        string $lease,
        array $scope,
        string &$deltaBuffer,
        int|float|null &$startedNs,
    ): void {
        if ($deltaBuffer === '') {
            return;
        }
        $this->delta($context, $session, $runId, $lease, 'assistant.delta', ['text' => $deltaBuffer], $scope);
        $deltaBuffer = '';
        $startedNs = null;
    }

    /** @param list<string> $scope */
    private function waitForTool(CallContext $context, string $session, string $runId, string $lease, string $state, array $scope): ?int
    {
        if (! in_array($state, ['awaiting_approval', 'dispatched'], true)) {
            return null;
        }

        return $this->db->transaction(function () use ($context, $session, $runId, $lease, $state, $scope): int {
            $this->ledger->lockRun($context, $runId, $lease);
            if ($this->chat->cancellationRequested($context, $runId)) {
                $this->finish($context, $session, $runId, $lease, '', $scope);

                return 0;
            }
            $waiting = $state === 'awaiting_approval';
            $this->runs->finishRun($context, $runId, $lease, $waiting ? 'awaiting_approval' : 'reconcile');

            return $waiting ? 30 : 5;
        }, $context->tenantId);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $scope
     */
    private function delta(CallContext $context, string $session, string $runId, string $lease, string $type, array $payload, array $scope): void
    {
        $this->db->transaction(function () use ($context, $session, $runId, $lease, $type, $payload, $scope): void {
            $this->ledger->lockRun($context, $runId, $lease);
            $this->chat->append($context, $session, $runId, $type, $payload, $scope);
        }, $context->tenantId);
    }

    /** @param list<string> $scope */
    private function finish(
        CallContext $context, string $session, string $runId, string $lease, string $text, array $scope, ErrorCode|string|null $error = null,
    ): void {
        $this->db->transaction(function () use ($context, $session, $runId, $lease, $text, $scope, $error): void {
            $this->ledger->lockRun($context, $runId, $lease);
            $cancelled = $this->chat->cancellationRequested($context, $runId);
            $state = $cancelled ? 'cancelled' : ($error === null ? 'completed' : 'rejected');
            if ($state === 'completed' && $text !== '') {
                $this->chat->finishAssistantMessage($context, $session, $runId, $text, $scope);
            }
            $errorCode = $error instanceof ErrorCode ? $error->value : $error;
            $this->chat->append($context, $session, $runId, 'run.finished', ['state' => $state, 'error' => $cancelled ? null : $errorCode], $scope);
            if (! $this->runs->finishRun($context, $runId, $lease, $state === 'cancelled' ? 'rejected' : $state)) {
                throw OfficeException::temporarilyUnavailable();
            }
        }, $context->tenantId);
    }

    private function limitInt(string $key, int $default, int $min, int $max): int
    {
        $configured = $this->config?->get($key, $default);
        if (! is_int($configured)) {
            return $default;
        }

        return max($min, min($max, $configured));
    }
}
