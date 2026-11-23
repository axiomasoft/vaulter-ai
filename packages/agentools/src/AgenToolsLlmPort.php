<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\LlmPort as AgenToolsSdkLlmPort;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use JsonException;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Optional AgenTools streaming adapter implementing Core LlmPort.
 * Delegates to a bound AgenTools SDK LlmPort when present or uses server-side ProviderSecretStore
 * over OpenAI-compatible HTTP SSE without depending on laravel/ai.
 */
final readonly class AgenToolsLlmPort implements LlmPort
{
    public function __construct(
        private Container $container,
        private ProviderSecretStore $secretStore,
        private OfficeToolDescriptorRegistry $descriptors,
        private OfficeRegistry $officeRegistry,
        private ConfigRepository $config,
        private ?HttpFactory $http = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, array<string, mixed>>  $tools
     * @param  Closure(): bool  $cancelled
     * @return iterable<
     *     array{type:'textDelta', text:string}
     *     |array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string, replayCallId?:string}
     *     |array{type:'finish'}
     * >
     */
    public function stream(array $messages, array $tools, HostProfile $profile, Closure $cancelled): iterable
    {
        $maxRetries = $this->intConfig('vaulter-ai-agentools.chat.max_retries', 2, 0, 5);
        $backoffMs = $this->intConfig('vaulter-ai-agentools.chat.retry_backoff_ms', 100, 0, 5000);
        $timeout = $this->intConfig('vaulter-ai-agentools.chat.request_timeout_seconds', 30, 1, 120);
        $maxOutputBytes = $this->intConfig('vaulter-ai-agentools.chat.max_output_bytes', 65536, 1024, 262144);
        $maxToolCalls = $this->intConfig('vaulter-ai-agentools.chat.max_tool_calls', 32, 1, 64);

        $priorToolCalls = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? null) === 'tool') {
                $priorToolCalls[] = is_string($msg['call_id'] ?? null) ? $msg['call_id'] : null;
            }
        }
        $priorToolCount = count($priorToolCalls);
        if ($priorToolCount > $maxToolCalls) {
            throw new OfficeException(ErrorCode::LimitExceeded);
        }
        $canRequestTool = $priorToolCount < $maxToolCalls;

        $filteredTools = $this->filterTools($tools, $profile);
        $sdkPort = $this->container->bound(AgenToolsSdkLlmPort::class)
            ? $this->container->make(AgenToolsSdkLlmPort::class)
            : null;

        if ($sdkPort !== null) {
            yield from $this->streamViaSdkPort(
                $sdkPort, $messages, $filteredTools, $profile, $cancelled,
                $maxRetries, $backoffMs, $maxOutputBytes, $canRequestTool, $priorToolCalls,
            );

            return;
        }

        yield from $this->streamViaHttp(
            $messages, $filteredTools, $profile, $cancelled, $maxRetries, $backoffMs, $timeout, $maxOutputBytes, $canRequestTool,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, array<string, mixed>>  $tools
     * @param  Closure(): bool  $cancelled
     * @param  list<?string>  $priorToolCalls
     * @return iterable<
     *     array{type:'textDelta', text:string}
     *     |array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string, replayCallId?:string}
     *     |array{type:'finish'}
     * >
     */
    private function streamViaSdkPort(
        AgenToolsSdkLlmPort $sdkPort,
        array $messages,
        array $tools,
        HostProfile $profile,
        Closure $cancelled,
        int $maxRetries,
        int $backoffMs,
        int $maxOutputBytes,
        bool $canRequestTool,
        array $priorToolCalls,
    ): iterable {
        $payload = [
            'instructions' => $this->instructions($profile, $tools),
            'messages' => $messages,
            'tools' => $tools,
            'profile' => [
                'name' => $profile->name,
                'revision' => $profile->revision,
                'approval' => $profile->approval->value,
                'commands' => $profile->commands,
            ],
        ];

        $attempt = 0;
        while (true) {
            if ($cancelled()) {
                throw OfficeException::temporarilyUnavailable();
            }

            $yielded = false;
            try {
                $response = $sdkPort->respond($payload);
                $bytes = 0;

                if (is_array($response['deltas'] ?? null)) {
                    foreach ($response['deltas'] as $chunk) {
                        $this->assertNotCancelled($cancelled);
                        if (! is_string($chunk) || $chunk === '') {
                            continue;
                        }
                        if ($bytes + strlen($chunk) > $maxOutputBytes || ! mb_check_encoding($chunk, 'UTF-8')) {
                            throw new OfficeException(ErrorCode::LimitExceeded);
                        }
                        $bytes += strlen($chunk);
                        $yielded = true;
                        yield ['type' => 'textDelta', 'text' => $chunk];
                    }
                } elseif (is_string($response['text'] ?? null) && $response['text'] !== '') {
                    $chunk = $response['text'];
                    if (strlen($chunk) > $maxOutputBytes || ! mb_check_encoding($chunk, 'UTF-8')) {
                        throw new OfficeException(ErrorCode::LimitExceeded);
                    }
                    $yielded = true;
                    yield ['type' => 'textDelta', 'text' => $chunk];
                }

                if (is_array($response['tool_call'] ?? null)) {
                    $tc = $response['tool_call'];
                    $replayCallId = $tc['replayCallId'] ?? null;
                    if (! $canRequestTool && (! is_string($replayCallId) || ! in_array($replayCallId, $priorToolCalls, true))) {
                        throw new OfficeException(ErrorCode::LimitExceeded);
                    }
                    $toolName = $tc['tool'] ?? null;
                    if (! is_string($toolName) || ! isset($tools[$toolName])) {
                        throw OfficeException::resourceUnavailable();
                    }
                    $rawInput = $tc['input'] ?? new stdClass;
                    $encoded = is_string($rawInput)
                        ? $rawInput
                        : json_encode($rawInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $event = [
                        'type' => 'toolInputComplete',
                        'tool' => $toolName,
                        'input' => $encoded,
                    ];
                    if (is_string($tc['providerToolCallId'] ?? null)) {
                        $event['providerToolCallId'] = $tc['providerToolCallId'];
                    }
                    if (is_string($replayCallId)) {
                        $event['replayCallId'] = $replayCallId;
                    }
                    $yielded = true;
                    yield $event;

                    return;
                }

                yield ['type' => 'finish'];

                return;
            } catch (OfficeException $officeFailure) {
                throw $officeFailure;
            } catch (Throwable $failure) {
                if (! $yielded && $attempt < $maxRetries) {
                    $attempt++;
                    $this->sleepBackoff($backoffMs, $attempt, $cancelled);

                    continue;
                }

                throw OfficeException::temporarilyUnavailable($this->secretStore->maskText($failure->getMessage()));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, array<string, mixed>>  $tools
     * @param  Closure(): bool  $cancelled
     * @return iterable<
     *     array{type:'textDelta', text:string}
     *     |array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string, replayCallId?:string}
     *     |array{type:'finish'}
     * >
     */
    private function streamViaHttp(
        array $messages,
        array $tools,
        HostProfile $profile,
        Closure $cancelled,
        int $maxRetries,
        int $backoffMs,
        int $timeout,
        int $maxOutputBytes,
        bool $canRequestTool,
    ): iterable {
        $connection = $profile->providerConnection ?? 'office_model';
        $secret = $this->secretStore->resolve($connection);
        $baseUrl = rtrim($secret->baseUrl ?? 'https://api.openai.com/v1', '/');
        $model = $secret->model ?? 'gpt-4o-mini';

        $toolSpecs = [];
        foreach ($tools as $toolName => $meta) {
            $descriptor = $this->descriptors->descriptor($toolName);
            if ($descriptor === null) {
                continue;
            }
            $toolSpecs[] = [
                'type' => 'function',
                'name' => $toolName,
                'description' => $descriptor->description,
                'parameters' => $descriptor->inputSchema,
            ];
        }

        $body = [
            'model' => $model,
            'instructions' => $this->instructions($profile, $tools),
            'input' => $this->mapHttpInput($messages),
            'tools' => $toolSpecs,
            'stream' => true,
        ];

        $http = $this->http ?? $this->container->make(HttpFactory::class);
        $attempt = 0;

        while (true) {
            if ($cancelled()) {
                throw OfficeException::temporarilyUnavailable();
            }

            $yielded = false;
            $emittedBytes = 0;

            try {
                $request = $http
                    ->createPendingRequest()
                    ->withToken($secret->apiKey())
                    ->timeout($timeout)
                    ->withHeaders($secret->headers)
                    ->withOptions(['stream' => true]);

                $response = $request->post($baseUrl.'/responses', $body)->throw();
                $stream = $response->toPsrResponse()->getBody();
                $pendingToolCalls = [];
                $buffer = '';

                while (! $stream->eof()) {
                    $this->assertNotCancelled($cancelled);
                    $chunk = $stream->read(4096);
                    if ($chunk === '') {
                        continue;
                    }
                    $buffer .= $chunk;
                    while (preg_match('/\r?\n\r?\n/', $buffer, $boundary, PREG_OFFSET_CAPTURE) === 1) {
                        [$separator, $pos] = $boundary[0];
                        $frame = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + strlen($separator));
                        $data = $this->parseSseData($frame);
                        if ($data === null) {
                            continue;
                        }
                        $type = $data['type'] ?? '';
                        if (in_array($type, ['error', 'response.failed', 'response.incomplete', 'response.cancelled'], true)) {
                            throw OfficeException::temporarilyUnavailable();
                        }
                        if ($type === 'response.completed' || $type === '[DONE]') {
                            if ($pendingToolCalls !== []) {
                                throw OfficeException::temporarilyUnavailable();
                            }
                            yield ['type' => 'finish'];

                            return;
                        }
                        if ($type === 'response.output_text.delta') {
                            $delta = is_string($data['delta'] ?? null) ? $data['delta'] : '';
                            if ($delta !== '') {
                                if ($emittedBytes + strlen($delta) > $maxOutputBytes || ! mb_check_encoding($delta, 'UTF-8')) {
                                    throw new OfficeException(ErrorCode::LimitExceeded);
                                }
                                $emittedBytes += strlen($delta);
                                $yielded = true;
                                yield ['type' => 'textDelta', 'text' => $delta];
                            }

                            continue;
                        }
                        if ($type === 'response.output_item.added') {
                            $item = $data['item'] ?? null;
                            if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
                                continue;
                            }
                            $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
                            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                            if ($itemId !== '') {
                                $pendingToolCalls[$itemId] = ['name' => $name, 'arguments' => ''];
                            }

                            continue;
                        }
                        if ($type === 'response.function_call_arguments.delta') {
                            $itemId = is_string($data['item_id'] ?? null) ? $data['item_id'] : '';
                            if ($itemId !== '' && isset($pendingToolCalls[$itemId])) {
                                $pendingToolCalls[$itemId]['arguments'] .= is_string($data['delta'] ?? null) ? $data['delta'] : '';
                            }

                            continue;
                        }
                        if ($type === 'response.function_call_arguments.done') {
                            $itemId = is_string($data['item_id'] ?? null) ? $data['item_id'] : '';
                            $call = $pendingToolCalls[$itemId] ?? null;
                            if ($call === null) {
                                continue;
                            }
                            if (! $canRequestTool) {
                                throw new OfficeException(ErrorCode::LimitExceeded);
                            }
                            $name = $call['name'];
                            if (! isset($tools[$name])) {
                                throw OfficeException::resourceUnavailable();
                            }
                            $args = is_string($data['arguments'] ?? null) && $data['arguments'] !== ''
                                ? $data['arguments']
                                : $call['arguments'];
                            $yielded = true;
                            yield [
                                'type' => 'toolInputComplete',
                                'tool' => $name,
                                'input' => $args !== '' ? $args : '{}',
                                'providerToolCallId' => $itemId,
                            ];

                            return;
                        }
                    }
                }

                throw OfficeException::temporarilyUnavailable();
            } catch (OfficeException $officeFailure) {
                throw $officeFailure;
            } catch (Throwable $failure) {
                if (! $yielded && $this->isRetryableHttpFailure($failure) && $attempt < $maxRetries) {
                    $attempt++;
                    $this->sleepBackoff($backoffMs, $attempt, $cancelled);

                    continue;
                }

                throw OfficeException::temporarilyUnavailable($this->secretStore->maskText($failure->getMessage()));
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $tools
     * @return array<string, array<string, mixed>>
     */
    private function filterTools(array $tools, HostProfile $profile): array
    {
        $filtered = [];
        foreach ($tools as $toolName => $meta) {
            $op = OfficeOperation::fromToolName($toolName);
            if ($op === null || $this->descriptors->descriptor($toolName) === null) {
                continue;
            }
            if (in_array($op, [OfficeOperation::Describe, OfficeOperation::Prepare, OfficeOperation::Commit], true)) {
                $rawCommands = is_array($meta['commands'] ?? null) ? $meta['commands'] : [];
                $hasInstalled = false;
                foreach ($rawCommands as $resCommands) {
                    if (! is_array($resCommands)) {
                        continue;
                    }
                    foreach (array_keys($resCommands) as $cmdName) {
                        if (is_string($cmdName)
                            && in_array($cmdName, $profile->commands, true)
                            && $this->officeRegistry->command($cmdName) !== null) {
                            $hasInstalled = true;
                            break 2;
                        }
                    }
                }
                if (! $hasInstalled) {
                    continue;
                }
            }
            $filtered[$toolName] = $meta;
        }

        return $filtered;
    }

    /** @param array<string, array<string, mixed>> $tools */
    private function instructions(HostProfile $profile, array $tools): string
    {
        $commands = $profile->commands === [] ? 'none' : implode(', ', $profile->commands);

        return implode("\n", [
            'You are the Vaulter Office chat assistant using AgenTools tool contracts.',
            "Profile: {$profile->name} (revision {$profile->revision}). Approval mode: {$profile->approval->value}.",
            "Allowed profile commands: {$commands}. Tools: ".implode(', ', array_keys($tools)).'.',
            'Never request actions outside the published catalog or leak provider credentials.',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function mapHttpInput(array $messages): array
    {
        $input = [];
        foreach ($messages as $item) {
            $role = $item['role'] ?? null;
            if ($role === 'user') {
                $text = is_string($item['content'] ?? null) ? $item['content'] : '';
                if (is_array($item['selection'] ?? null)) {
                    $text .= "\n\n<editor_selection>\n".json_encode($item['selection'], JSON_THROW_ON_ERROR)."\n</editor_selection>";
                }
                $input[] = ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $text]]];

                continue;
            }
            if ($role === 'tool') {
                $callId = is_string($item['call_id'] ?? null) ? $item['call_id'] : '';
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                $args = json_encode($item['arguments'] ?? new stdClass, JSON_THROW_ON_ERROR);
                $output = is_string($item['response'] ?? null)
                    ? $item['response']
                    : json_encode($item['response'] ?? new stdClass, JSON_THROW_ON_ERROR);
                $input[] = ['type' => 'function_call', 'call_id' => $callId, 'name' => $name, 'arguments' => $args];
                $input[] = ['type' => 'function_call_output', 'call_id' => $callId, 'output' => $output];
            }
        }

        return $input;
    }

    /** @return array<string, mixed>|null */
    private function parseSseData(string $frame): ?array
    {
        $dataLines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $frame)) as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return null;
        }
        $raw = implode("\n", $dataLines);
        if ($raw === '[DONE]') {
            return ['type' => '[DONE]'];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function isRetryableHttpFailure(Throwable $failure): bool
    {
        if ($failure instanceof ConnectionException) {
            return true;
        }
        if ($failure instanceof RequestException && $failure->response !== null) {
            $status = $failure->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    /** @param Closure(): bool $cancelled */
    private function assertNotCancelled(Closure $cancelled): void
    {
        if ($cancelled()) {
            throw OfficeException::temporarilyUnavailable();
        }
    }

    /** @param Closure(): bool $cancelled */
    private function sleepBackoff(int $backoffMs, int $attempt, Closure $cancelled): void
    {
        if ($backoffMs <= 0 || $cancelled()) {
            return;
        }
        usleep(min(500_000, $backoffMs * 1000 * (1 << max(0, $attempt - 1))));
    }

    private function intConfig(string $key, int $default, int $min, int $max): int
    {
        $val = $this->config->get($key, $default);
        if (! is_int($val)) {
            return $default;
        }

        return max($min, min($max, $val));
    }
}
