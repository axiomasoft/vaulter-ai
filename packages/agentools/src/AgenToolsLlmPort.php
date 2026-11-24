<?php

declare(strict_types=1);

namespace Vaulter\Ai\AgenTools;

use AgenTools\Contracts\LlmPort as AgenToolsSdkLlmPort;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use JsonException;
use stdClass;
use Throwable;
use Vaulter\Ai\Core\Contracts\LlmPort;
use Vaulter\Ai\Core\Contracts\ProviderSecretStore;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ProviderSecret;
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
        $format = $this->resolveWireFormat($connection, $secret);
        $endpointUrl = $this->resolveEndpointUrl($format, $secret);
        $model = $secret->model ?? ($format === 'anthropic' ? 'claude-3-5-sonnet-latest' : 'gpt-4o-mini');
        $body = $this->buildHttpPayload($format, $model, $messages, $tools, $profile);

        $http = $this->http ?? $this->container->make(HttpFactory::class);
        $attempt = 0;

        while (true) {
            if ($cancelled()) {
                throw OfficeException::temporarilyUnavailable();
            }

            $yielded = false;
            $emittedBytes = 0;

            try {
                $request = $this->buildHttpRequest($http, $format, $secret, $timeout);
                $response = $request->post($endpointUrl, $body)->throw();
                $stream = $response->toPsrResponse()->getBody();
                /** @var array{pendingToolCalls: array<string, array{id: string, name: string, arguments: string}>} $state */
                $state = ['pendingToolCalls' => []];
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
                        $parsed = $this->parseSseFrame($frame);
                        if ($parsed === null) {
                            continue;
                        }

                        $step = $this->handleSseEvent(
                            $format,
                            $parsed['event'],
                            $parsed['data'],
                            $state,
                            $tools,
                            $maxOutputBytes,
                            $emittedBytes,
                            $canRequestTool,
                        );
                        foreach ($step['events'] as $event) {
                            $yielded = true;
                            yield $event;
                        }
                        if ($step['done']) {
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

                $masked = $this->secretStore->maskText($failure->getMessage());
                if (strlen($secret->apiKey()) >= 6) {
                    $masked = str_replace($secret->apiKey(), '[REDACTED]', $masked);
                }

                throw OfficeException::temporarilyUnavailable($masked);
            }
        }
    }

    private function resolveWireFormat(string $connection, ProviderSecret $secret): string
    {
        $candidates = [
            $this->config->get('vaulter-ai-agentools.providers.'.$connection.'.format'),
            $this->config->get('vaulter-ai-agentools.providers.'.$connection.'.wire_format'),
            $this->config->get('vaulter-ai.secret_store.connections.'.$connection.'.wire_format'),
            $this->config->get('vaulter-ai.secret_store.connections.'.$connection.'.api_format'),
            $this->config->get('vaulter-ai.secret_store.connections.'.$connection.'.format'),
            $this->config->get('vaulter-ai-agentools.chat.wire_format'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            return match (strtolower(trim($candidate))) {
                'anthropic', 'messages', 'anthropic_messages' => 'anthropic',
                'chat_completions', 'chat/completions', 'openai_chat', 'openai-chat' => 'chat_completions',
                default => 'responses',
            };
        }

        $baseUrl = strtolower(rtrim($secret->baseUrl ?? '', '/'));
        if (str_ends_with($baseUrl, '/chat/completions')) {
            return 'chat_completions';
        }
        if (str_ends_with($baseUrl, '/messages')) {
            return 'anthropic';
        }
        if (str_ends_with($baseUrl, '/responses')) {
            return 'responses';
        }

        return match (strtolower(trim($secret->driver))) {
            'anthropic', 'claude' => 'anthropic',
            'openai_chat', 'openai-chat', 'chat_completions', 'openai_chat_completions' => 'chat_completions',
            default => 'responses',
        };
    }

    private function resolveEndpointUrl(string $format, ProviderSecret $secret): string
    {
        $defaultBase = $format === 'anthropic' ? 'https://api.anthropic.com/v1' : 'https://api.openai.com/v1';
        $baseUrl = rtrim($secret->baseUrl ?? $defaultBase, '/');

        if ($format === 'anthropic') {
            if (str_ends_with($baseUrl, '/messages')) {
                return $baseUrl;
            }
            if (preg_match('#^https?://[^/]+$#i', $baseUrl) === 1) {
                return $baseUrl.'/v1/messages';
            }

            return $baseUrl.'/messages';
        }

        $suffix = $format === 'chat_completions' ? '/chat/completions' : '/responses';

        return str_ends_with($baseUrl, $suffix) ? $baseUrl : $baseUrl.$suffix;
    }

    private function buildHttpRequest(
        HttpFactory $http,
        string $format,
        ProviderSecret $secret,
        int $timeout,
    ): PendingRequest {
        $request = $http->createPendingRequest()
            ->timeout($timeout)
            ->withOptions(['stream' => true]);

        if ($format === 'anthropic') {
            return $request->withHeaders([
                'x-api-key' => $secret->apiKey(),
                'anthropic-version' => '2023-06-01',
            ] + $secret->headers);
        }

        return $request
            ->withToken($secret->apiKey())
            ->withHeaders($secret->headers);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    private function buildHttpPayload(
        string $format,
        string $model,
        array $messages,
        array $tools,
        HostProfile $profile,
    ): array {
        $instructions = $this->instructions($profile, $tools);

        if ($format === 'chat_completions') {
            $toolSpecs = [];
            foreach ($tools as $toolName => $meta) {
                $descriptor = $this->descriptors->descriptor($toolName);
                if ($descriptor === null) {
                    continue;
                }
                $toolSpecs[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $toolName,
                        'description' => $descriptor->description,
                        'parameters' => $descriptor->inputSchema,
                    ],
                ];
            }

            $payload = [
                'model' => $model,
                'messages' => $this->mapChatCompletionsMessages($messages, $instructions),
                'stream' => true,
            ];
            if ($toolSpecs !== []) {
                $payload['tools'] = $toolSpecs;
            }

            return $payload;
        }

        if ($format === 'anthropic') {
            $toolSpecs = [];
            foreach ($tools as $toolName => $meta) {
                $descriptor = $this->descriptors->descriptor($toolName);
                if ($descriptor === null) {
                    continue;
                }
                $toolSpecs[] = [
                    'name' => $toolName,
                    'description' => $descriptor->description,
                    'input_schema' => $descriptor->inputSchema,
                ];
            }

            $payload = [
                'model' => $model,
                'max_tokens' => $this->intConfig('vaulter-ai-agentools.chat.max_tokens', 4096, 1, 65536),
                'system' => $instructions,
                'messages' => $this->mapAnthropicMessages($messages),
                'stream' => true,
            ];
            if ($toolSpecs !== []) {
                $payload['tools'] = $toolSpecs;
            }

            return $payload;
        }

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

        return [
            'model' => $model,
            'instructions' => $instructions,
            'input' => $this->mapHttpInput($messages),
            'tools' => $toolSpecs,
            'stream' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{pendingToolCalls: array<string, array{id: string, name: string, arguments: string}>}  $state
     * @param  array<string, array<string, mixed>>  $tools
     * @return array{events: list<array{type:'textDelta', text:string}|array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string}|array{type:'finish'}>, done: bool}
     */
    private function handleSseEvent(
        string $format,
        ?string $sseEvent,
        array $data,
        array &$state,
        array $tools,
        int $maxOutputBytes,
        int &$emittedBytes,
        bool $canRequestTool,
    ): array {
        if ($format === 'chat_completions') {
            return $this->handleChatCompletionsEvent($data, $state, $tools, $maxOutputBytes, $emittedBytes, $canRequestTool);
        }

        if ($format === 'anthropic') {
            return $this->handleAnthropicEvent($sseEvent, $data, $state, $tools, $maxOutputBytes, $emittedBytes, $canRequestTool);
        }

        return $this->handleResponsesEvent($data, $state, $tools, $maxOutputBytes, $emittedBytes, $canRequestTool);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{pendingToolCalls: array<string, array{id: string, name: string, arguments: string}>}  $state
     * @param  array<string, array<string, mixed>>  $tools
     * @return array{events: list<array{type:'textDelta', text:string}|array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string}|array{type:'finish'}>, done: bool}
     */
    private function handleResponsesEvent(
        array $data,
        array &$state,
        array $tools,
        int $maxOutputBytes,
        int &$emittedBytes,
        bool $canRequestTool,
    ): array {
        $type = $data['type'] ?? '';
        if (in_array($type, ['error', 'response.failed', 'response.incomplete', 'response.cancelled'], true)) {
            throw OfficeException::temporarilyUnavailable();
        }
        if ($type === 'response.completed' || $type === '[DONE]') {
            if ($state['pendingToolCalls'] !== []) {
                throw OfficeException::temporarilyUnavailable();
            }

            return ['events' => [['type' => 'finish']], 'done' => true];
        }
        if ($type === 'response.output_text.delta') {
            $delta = is_string($data['delta'] ?? null) ? $data['delta'] : '';
            if ($delta !== '') {
                $this->assertOutputBudget($delta, $emittedBytes, $maxOutputBytes);

                return ['events' => [['type' => 'textDelta', 'text' => $delta]], 'done' => false];
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'response.output_item.added') {
            $item = $data['item'] ?? null;
            if (is_array($item) && ($item['type'] ?? null) === 'function_call') {
                $itemId = is_string($item['id'] ?? null) ? $item['id'] : '';
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                if ($itemId !== '') {
                    $state['pendingToolCalls'][$itemId] = ['id' => $itemId, 'name' => $name, 'arguments' => ''];
                }
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'response.function_call_arguments.delta') {
            $itemId = is_string($data['item_id'] ?? null) ? $data['item_id'] : '';
            if ($itemId !== '' && isset($state['pendingToolCalls'][$itemId])) {
                $state['pendingToolCalls'][$itemId]['arguments'] .= is_string($data['delta'] ?? null) ? $data['delta'] : '';
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'response.function_call_arguments.done') {
            $itemId = is_string($data['item_id'] ?? null) ? $data['item_id'] : '';
            $call = $state['pendingToolCalls'][$itemId] ?? null;
            if ($call === null) {
                return ['events' => [], 'done' => false];
            }
            $args = is_string($data['arguments'] ?? null) && $data['arguments'] !== ''
                ? $data['arguments']
                : $call['arguments'];

            return [
                'events' => [$this->buildToolEvent($call['name'], $args, $itemId, $tools, $canRequestTool)],
                'done' => true,
            ];
        }

        return ['events' => [], 'done' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{pendingToolCalls: array<string, array{id: string, name: string, arguments: string}>}  $state
     * @param  array<string, array<string, mixed>>  $tools
     * @return array{events: list<array{type:'textDelta', text:string}|array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string}|array{type:'finish'}>, done: bool}
     */
    private function handleChatCompletionsEvent(
        array $data,
        array &$state,
        array $tools,
        int $maxOutputBytes,
        int &$emittedBytes,
        bool $canRequestTool,
    ): array {
        if (isset($data['error'])) {
            throw OfficeException::temporarilyUnavailable();
        }
        if (($data['type'] ?? null) === '[DONE]') {
            if ($state['pendingToolCalls'] !== []) {
                throw OfficeException::temporarilyUnavailable();
            }

            return ['events' => [['type' => 'finish']], 'done' => true];
        }

        $choices = $data['choices'] ?? null;
        if (! is_array($choices) || ! isset($choices[0]) || ! is_array($choices[0])) {
            return ['events' => [], 'done' => false];
        }

        $choice = $choices[0];
        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $events = [];

        $content = $delta['content'] ?? null;
        if (is_string($content) && $content !== '') {
            $this->assertOutputBudget($content, $emittedBytes, $maxOutputBytes);
            $events[] = ['type' => 'textDelta', 'text' => $content];
        }

        if (is_array($delta['tool_calls'] ?? null)) {
            foreach ($delta['tool_calls'] as $tc) {
                if (! is_array($tc)) {
                    continue;
                }
                $rawIdx = $tc['index'] ?? 0;
                $idx = is_int($rawIdx) || is_string($rawIdx) ? (string) $rawIdx : '0';
                if (! isset($state['pendingToolCalls'][$idx])) {
                    $state['pendingToolCalls'][$idx] = ['id' => '', 'name' => '', 'arguments' => ''];
                }
                if (is_string($tc['id'] ?? null) && $tc['id'] !== '') {
                    $state['pendingToolCalls'][$idx]['id'] = $tc['id'];
                }
                $fn = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                if (is_string($fn['name'] ?? null) && $fn['name'] !== '') {
                    $state['pendingToolCalls'][$idx]['name'] = $fn['name'];
                }
                if (is_string($fn['arguments'] ?? null) && $fn['arguments'] !== '') {
                    $state['pendingToolCalls'][$idx]['arguments'] .= $fn['arguments'];
                }
            }
        }

        $finishReason = $choice['finish_reason'] ?? null;
        if (in_array($finishReason, ['tool_calls', 'function_call'], true) && $state['pendingToolCalls'] !== []) {
            $first = reset($state['pendingToolCalls']);
            $events[] = $this->buildToolEvent($first['name'], $first['arguments'], $first['id'], $tools, $canRequestTool);

            return ['events' => $events, 'done' => true];
        }
        if ($finishReason === 'stop') {
            if ($state['pendingToolCalls'] !== []) {
                throw OfficeException::temporarilyUnavailable();
            }
            $events[] = ['type' => 'finish'];

            return ['events' => $events, 'done' => true];
        }
        if (in_array($finishReason, ['length', 'content_filter'], true)) {
            throw OfficeException::temporarilyUnavailable();
        }

        return ['events' => $events, 'done' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{pendingToolCalls: array<string, array{id: string, name: string, arguments: string}>}  $state
     * @param  array<string, array<string, mixed>>  $tools
     * @return array{events: list<array{type:'textDelta', text:string}|array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string}|array{type:'finish'}>, done: bool}
     */
    private function handleAnthropicEvent(
        ?string $sseEvent,
        array $data,
        array &$state,
        array $tools,
        int $maxOutputBytes,
        int &$emittedBytes,
        bool $canRequestTool,
    ): array {
        $type = is_string($data['type'] ?? null) ? $data['type'] : ($sseEvent ?? '');
        if ($type === 'error' || isset($data['error'])) {
            throw OfficeException::temporarilyUnavailable();
        }
        if ($type === 'ping' || $type === 'message_start') {
            return ['events' => [], 'done' => false];
        }
        $rawIndex = $data['index'] ?? '0';
        $index = is_int($rawIndex) || is_string($rawIndex) ? (string) $rawIndex : '0';
        if ($type === 'content_block_start') {
            $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
            $blockType = $block['type'] ?? null;
            if ($blockType === 'text') {
                $text = is_string($block['text'] ?? null) ? $block['text'] : '';
                if ($text !== '') {
                    $this->assertOutputBudget($text, $emittedBytes, $maxOutputBytes);

                    return ['events' => [['type' => 'textDelta', 'text' => $text]], 'done' => false];
                }
            } elseif ($blockType === 'tool_use') {
                $id = is_string($block['id'] ?? null) ? $block['id'] : '';
                $name = is_string($block['name'] ?? null) ? $block['name'] : '';
                $rawInput = $block['input'] ?? null;
                $initialArgs = is_array($rawInput) && $rawInput !== []
                    ? json_encode($rawInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '';
                $state['pendingToolCalls'][$index] = ['id' => $id, 'name' => $name, 'arguments' => $initialArgs];
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'content_block_delta') {
            $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
            $deltaType = $delta['type'] ?? null;
            if ($deltaType === 'text_delta') {
                $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
                if ($text !== '') {
                    $this->assertOutputBudget($text, $emittedBytes, $maxOutputBytes);

                    return ['events' => [['type' => 'textDelta', 'text' => $text]], 'done' => false];
                }
            } elseif ($deltaType === 'input_json_delta' && isset($state['pendingToolCalls'][$index])) {
                $partial = is_string($delta['partial_json'] ?? null) ? $delta['partial_json'] : '';
                $state['pendingToolCalls'][$index]['arguments'] .= $partial;
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'content_block_stop') {
            $call = $state['pendingToolCalls'][$index] ?? null;
            if ($call !== null) {
                unset($state['pendingToolCalls'][$index]);

                return [
                    'events' => [$this->buildToolEvent($call['name'], $call['arguments'], $call['id'], $tools, $canRequestTool)],
                    'done' => true,
                ];
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'message_delta') {
            $msgDelta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
            $stopReason = $msgDelta['stop_reason'] ?? null;
            if (in_array($stopReason, ['max_tokens', 'refusal'], true)) {
                throw OfficeException::temporarilyUnavailable();
            }

            return ['events' => [], 'done' => false];
        }
        if ($type === 'message_stop') {
            if ($state['pendingToolCalls'] !== []) {
                throw OfficeException::temporarilyUnavailable();
            }

            return ['events' => [['type' => 'finish']], 'done' => true];
        }

        return ['events' => [], 'done' => false];
    }

    /**
     * @param  array<string, array<string, mixed>>  $tools
     * @return array{type:'toolInputComplete', tool:string, input:string, providerToolCallId?:string}
     */
    private function buildToolEvent(
        string $name,
        string $rawArgs,
        string $providerToolCallId,
        array $tools,
        bool $canRequestTool,
    ): array {
        if (! $canRequestTool) {
            throw new OfficeException(ErrorCode::LimitExceeded);
        }
        if (! isset($tools[$name])) {
            throw OfficeException::resourceUnavailable();
        }

        $event = [
            'type' => 'toolInputComplete',
            'tool' => $name,
            'input' => $rawArgs !== '' ? $rawArgs : '{}',
        ];
        if ($providerToolCallId !== '') {
            $event['providerToolCallId'] = $providerToolCallId;
        }

        return $event;
    }

    private function assertOutputBudget(string $delta, int &$emittedBytes, int $maxOutputBytes): void
    {
        if ($emittedBytes + strlen($delta) > $maxOutputBytes || ! mb_check_encoding($delta, 'UTF-8')) {
            throw new OfficeException(ErrorCode::LimitExceeded);
        }
        $emittedBytes += strlen($delta);
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

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function mapChatCompletionsMessages(array $messages, string $instructions): array
    {
        $out = [];
        if ($instructions !== '') {
            $out[] = ['role' => 'system', 'content' => $instructions];
        }

        foreach ($messages as $item) {
            $role = $item['role'] ?? null;
            if ($role === 'user') {
                $out[] = ['role' => 'user', 'content' => $this->formatUserText($item)];

                continue;
            }
            if ($role === 'tool') {
                $callId = is_string($item['call_id'] ?? null) ? $item['call_id'] : '';
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                $args = json_encode($item['arguments'] ?? new stdClass, JSON_THROW_ON_ERROR);
                $output = is_string($item['response'] ?? null)
                    ? $item['response']
                    : json_encode($item['response'] ?? new stdClass, JSON_THROW_ON_ERROR);
                $out[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $callId,
                        'type' => 'function',
                        'function' => ['name' => $name, 'arguments' => $args],
                    ]],
                ];
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => $output,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function mapAnthropicMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $item) {
            $role = $item['role'] ?? null;
            if ($role === 'user') {
                $out[] = ['role' => 'user', 'content' => $this->formatUserText($item)];

                continue;
            }
            if ($role === 'tool') {
                $callId = is_string($item['call_id'] ?? null) ? $item['call_id'] : '';
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';
                $rawArgs = $item['arguments'] ?? new stdClass;
                $inputObj = match (true) {
                    $rawArgs instanceof stdClass => $rawArgs,
                    is_array($rawArgs) && $rawArgs !== [] => $rawArgs,
                    default => new stdClass,
                };
                $output = is_string($item['response'] ?? null)
                    ? $item['response']
                    : json_encode($item['response'] ?? new stdClass, JSON_THROW_ON_ERROR);
                $out[] = [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => $callId,
                        'name' => $name,
                        'input' => $inputObj,
                    ]],
                ];
                $out[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $callId,
                        'content' => $output,
                    ]],
                ];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $item */
    private function formatUserText(array $item): string
    {
        $text = is_string($item['content'] ?? null) ? $item['content'] : '';
        if (is_array($item['selection'] ?? null)) {
            $text .= "\n\n<editor_selection>\n".json_encode($item['selection'], JSON_THROW_ON_ERROR)."\n</editor_selection>";
        }

        return $text;
    }

    /** @return array{event: ?string, data: array<string, mixed>}|null */
    private function parseSseFrame(string $frame): ?array
    {
        $eventName = null;
        $dataLines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $frame)) as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventName = ltrim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return null;
        }
        $raw = implode("\n", $dataLines);
        if ($raw === '[DONE]') {
            return ['event' => $eventName, 'data' => ['type' => '[DONE]']];
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
        return ['event' => $eventName, 'data' => $decoded];
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
