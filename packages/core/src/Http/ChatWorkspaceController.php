<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Generator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Workspace\ChatWorkspace;

final readonly class ChatWorkspaceController
{
    public function __construct(
        private WireErrorMapper $errors,
    ) {}

    public function open(Request $request, CallContextResolver $resolver, Container $container): JsonResponse
    {
        try {
            $context = $this->context($request, $resolver);
            $retention = 86400;
            $rawContent = $request->getContent();
            if ($request->isJson() && trim($rawContent) !== '') {
                $body = json_decode($rawContent, false, 512, JSON_THROW_ON_ERROR);
                if (! $body instanceof stdClass) {
                    throw OfficeException::invalidInput();
                }
                if (property_exists($body, 'retention_seconds')) {
                    if (! is_int($body->retention_seconds) || $body->retention_seconds < 1 || $body->retention_seconds > 604800) {
                        throw OfficeException::invalidInput();
                    }
                    $retention = $body->retention_seconds;
                }
            }
            $chat = $container->make(ChatWorkspace::class);
            $sessionId = $chat->open($context, $retention);

            return new JsonResponse(['data' => $chat->show($context, $sessionId)], 201);
        } catch (JsonException) {
            return $this->error(OfficeException::invalidInput());
        } catch (OfficeException $exception) {
            return $this->error($exception);
        }
    }

    public function show(Request $request, CallContextResolver $resolver, Container $container, string $id): JsonResponse
    {
        try {
            $context = $this->context($request, $resolver);

            return new JsonResponse(['data' => $container->make(ChatWorkspace::class)->show($context, $id)]);
        } catch (OfficeException $exception) {
            return $this->error($exception);
        }
    }

    public function post(Request $request, CallContextResolver $resolver, Container $container, string $id): JsonResponse
    {
        try {
            $context = $this->context($request, $resolver);
            // Workspace middleware normalizes the parsed copy of the request body.
            $body = $request->isJson()
                ? json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR)
                : (object) $request->request->all();
            if (! $body instanceof stdClass) {
                throw OfficeException::invalidInput();
            }
            $message = $body->message_id ?? null;
            $content = $body->content ?? null;
            $resources = property_exists($body, 'resource_ids') ? $body->resource_ids : [];
            if (! is_string($message) || ! is_string($content) || ! is_array($resources) || ! array_is_list($resources)
                || array_filter($resources, is_string(...)) !== $resources) {
                throw OfficeException::invalidInput();
            }

            $selection = null;
            if (property_exists($body, 'selection') && $body->selection !== null) {
                if (! $body->selection instanceof stdClass) {
                    throw OfficeException::invalidInput();
                }
                $decoded = json_decode(json_encode($body->selection, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw OfficeException::invalidInput();
                }
                /** @var array<string, mixed> $decoded */
                $selection = $decoded;
            }

            $runId = $container->make(ChatWorkspace::class)->post($context, $id, $message, $content, $resources, $selection);

            return new JsonResponse(['data' => ['message_id' => $message, 'run_id' => $runId]], 202);
        } catch (JsonException) {
            return $this->error(OfficeException::invalidInput());
        } catch (OfficeException $exception) {
            return $this->error($exception);
        }
    }

    public function events(Request $request, CallContextResolver $resolver, Container $container, ResponseFactory $responses, string $id): StreamedResponse|JsonResponse
    {
        try {
            $context = $this->context($request, $resolver);
            $raw = $request->headers->get('Last-Event-ID') ?? $request->query('cursor', '0');
            $cursor = is_string($raw) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $raw) === 1
                ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
            if (! is_int($cursor)) {
                throw OfficeException::invalidInput();
            }
            $chat = $container->make(ChatWorkspace::class);
            $chat->events($context, $id, $cursor);
        } catch (OfficeException $exception) {
            return $this->error($exception);
        }

        /** @return Generator<int, string> */
        $frames = function () use ($request, $resolver, $chat, $id, $cursor): Generator {
            $start = hrtime(true);
            $deadline = $start + 25_000_000_000;
            $keepAlive = $start + 12_000_000_000;
            $backoff = new IdlePollBackoff;
            yield ": connected\n\n";
            while (hrtime(true) < $deadline && connection_aborted() === 0) {
                try {
                    $batch = $chat->events($this->context($request, $resolver), $id, $cursor);
                } catch (OfficeException $exception) {
                    if ($exception->errorCode === ErrorCode::CursorExpired) {
                        yield "event: reset\ndata: {}\n\n";
                    }

                    return;
                }
                foreach ($batch as $event) {
                    if (hrtime(true) >= $deadline || connection_aborted() !== 0) {
                        return;
                    }
                    $cursor = $event['seq'];
                    $data = json_encode((object) $event['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    yield "id: {$cursor}\nevent: {$event['type']}\ndata: {$data}\n\n";
                }
                if (hrtime(true) >= $keepAlive) {
                    yield ": keep-alive\n\n";
                    $keepAlive += 12_000_000_000;
                }
                // Never sleep past the connection cap: the client reconnects with Last-Event-ID.
                $delay = min($backoff->after(count($batch)), (int) floor(max(0, $deadline - hrtime(true)) / 1000));
                if ($delay > 0) {
                    usleep($delay);
                }
            }
        };

        return $responses->stream($frames, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'private, no-cache, no-store, no-transform',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'identity',
        ]);
    }

    private function context(Request $request, CallContextResolver $resolver): CallContext
    {
        return $resolver->resolve($request)
            ?? throw new OfficeException(ErrorCode::AuthenticationRequired);
    }

    private function error(OfficeException $exception): JsonResponse
    {
        $code = $exception->errorCode;
        $spec = $this->errors->describe($code);

        return new JsonResponse(['error' => [
            'code' => $code->value,
            'retryable' => $spec['retryable'],
            'next_action' => $spec['next_action']->value,
        ]], $spec['status']);
    }
}
