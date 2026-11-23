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
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\OfficeDatabase;
use Vaulter\Ai\Core\Persistence\Runs;
use Vaulter\Ai\Core\Persistence\ToolCallLedger;

final readonly class ChatTurn
{
    private const int MAX_TOOL_CALLS = 32;

    private const int MAX_OUTPUT_BYTES = 65536;

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
            || in_array($run->state, ['completed', 'rejected'], true)) {
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
            $messages = [$userMessage];
            $calls = $this->ledger->calls($context, $runId, $lease);
            foreach ($calls as $call) {
                $tool = $this->tools->deliver($context, $session, $runId, $lease, $call, $scope);
                $delay = $this->waitForTool($context, $runId, $lease, $tool['state']);
                if ($delay !== null) {
                    return $delay;
                }
                $messages[] = $tool['message'];
            }
            if (! in_array($run->state, ['queued', 'awaiting_approval'], true)) {
                $this->delta($context, $session, $runId, $lease, 'assistant.interrupted', [], $scope);
            }

            return $this->generate($llm, $context, $session, $runId, $lease, $messages, $scope, $calls);
        } catch (OfficeException $exception) {
            if ($exception->errorCode === ErrorCode::TemporarilyUnavailable) {
                $this->runs->releaseLease($context, $runId, $lease);

                return 5;
            }
            if (in_array($exception->errorCode, [ErrorCode::CursorExpired, ErrorCode::ResourceUnavailable], true)) {
                $this->runs->finishRun($context, $runId, $lease, 'rejected');
            } else {
                $this->finish($context, $session, $runId, $lease, '', $scope, $exception->errorCode);
            }

            return null;
        } catch (Throwable $exception) {
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
        $connection = $profile->providerConnection ?? 'office_model';
        $key = $this->secretStore?->status($connection)['configured'] === true
            ? $this->secretStore->resolve($connection)->apiKey()
            : '';
        $redactor = new AssistantTextRedactor($key);
        $deliveries = 0;
        $maxToolCalls = $this->limitInt('vaulter-ai.chat.max_tool_calls', self::MAX_TOOL_CALLS, 1, self::MAX_TOOL_CALLS);
        $maxOutputBytes = $this->limitInt('vaulter-ai.chat.max_output_bytes', self::MAX_OUTPUT_BYTES, 1024, self::MAX_OUTPUT_BYTES);
        $deadline = hrtime(true) + 70_000_000_000;
        $cancelled = static fn (): bool => hrtime(true) >= $deadline;
        while (! $cancelled()) {
            $this->chat->turnInput($context, $session, $runId);
            $stream = $llm->stream($messages, $this->tools->catalog($context, $scope), $profile, $cancelled);
            foreach ($stream as $event) {
                if ($cancelled()) {
                    throw OfficeException::temporarilyUnavailable();
                }
                if ($event['type'] === 'finish') {
                    $tail = $redactor->finish();
                    if ($tail !== '') {
                        if (strlen($text) + strlen($tail) > $maxOutputBytes) {
                            throw new OfficeException(ErrorCode::LimitExceeded);
                        }
                        $this->delta($context, $session, $runId, $lease, 'assistant.delta', ['text' => $tail], $scope);
                        $text .= $tail;
                    }
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
                        $this->delta($context, $session, $runId, $lease, 'assistant.delta', ['text' => $safe], $scope);
                    }

                    continue;
                }
                $replay = $event['replayCallId'] ?? null;
                if (++$deliveries > $maxToolCalls || ($replay === null && count($calls) >= $maxToolCalls)) {
                    throw new OfficeException(ErrorCode::LimitExceeded);
                }
                $index = $replay === null ? count($calls) : array_search($replay, array_column($calls, 'id'), true);
                if ($index === false) {
                    throw OfficeException::invalidInput();
                }
                $this->chat->turnInput($context, $session, $runId);
                $this->tools->request($context, $session, $runId, $lease, $index + 1, $event['tool'], $event['input'], $scope);
                $calls = $this->ledger->calls($context, $runId, $lease);
                $call = $calls[$index];
                $tool = $this->tools->deliver($context, $session, $runId, $lease, $call, $scope);
                $delay = $this->waitForTool($context, $runId, $lease, $tool['state']);
                if ($delay !== null) {
                    return $delay;
                }
                $messages[] = $tool['message'];
                unset($stream);

                continue 2;
            }

            // A provider disconnect must leave the turn resumable.
            break;
        }

        throw OfficeException::temporarilyUnavailable();
    }

    private function waitForTool(CallContext $context, string $runId, string $lease, string $state): ?int
    {
        if ($state === 'awaiting_approval') {
            $this->runs->finishRun($context, $runId, $lease, 'awaiting_approval');

            return 30;
        }
        if ($state === 'dispatched') {
            $this->runs->finishRun($context, $runId, $lease, 'reconcile');

            return 5;
        }

        return null;
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
        CallContext $context, string $session, string $runId, string $lease, string $text, array $scope, ?ErrorCode $error = null,
    ): void {
        $this->db->transaction(function () use ($context, $session, $runId, $lease, $text, $scope, $error): void {
            $this->ledger->lockRun($context, $runId, $lease);
            $state = $error === null ? 'completed' : 'rejected';
            if ($error === null && $text !== '') {
                $this->chat->finishAssistantMessage($context, $session, $runId, $text, $scope);
            }
            $this->chat->append($context, $session, $runId, 'run.finished', ['state' => $state, 'error' => $error?->value], $scope);
            if (! $this->runs->finishRun($context, $runId, $lease, $state)) {
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
