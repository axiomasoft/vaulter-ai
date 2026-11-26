<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Enums\ErrorCode;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Protocol\WireErrorMapper;
use Vaulter\Ai\Core\Workspace\OfficeWorkspaceService;

/**
 * Authenticated host workspace endpoints for inspecting and resuming multi-resource runs.
 */
final readonly class RunWorkspaceController
{
    public function __construct(
        private WireErrorMapper $errorMapper,
    ) {}

    public function show(Request $request, CallContextResolver $resolver, Container $container, string $id): JsonResponse
    {
        $context = $resolver->resolve($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        try {
            $run = $container->make(OfficeWorkspaceService::class)->showRun($context, $id);

            return new JsonResponse(['data' => $run], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    public function resume(Request $request, CallContextResolver $resolver, Container $container, string $id): JsonResponse|RedirectResponse
    {
        $context = $resolver->resolve($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        $rawOverrides = $request->input('arguments_by_resource');
        /** @var array<string, array<string, mixed>> $argumentsByResource */
        $argumentsByResource = [];
        if (is_array($rawOverrides)) {
            foreach ($rawOverrides as $resId => $args) {
                if (is_string($resId) && $resId !== '' && is_array($args)) {
                    $argumentsByResource[$resId] = array_filter($args, is_string(...), ARRAY_FILTER_USE_KEY);
                }
            }
        }
        $rereadOnly = $request->boolean('reread_only', false);

        try {
            $run = $container->make(OfficeWorkspaceService::class)->resumeRun($context, $id, $argumentsByResource, $rereadOnly);

            if (! $request->expectsJson() && ! $request->isJson()) {
                return LocalRedirect::after($request);
            }

            return new JsonResponse(['data' => $run], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    private function errorJson(ErrorCode $code): JsonResponse
    {
        $spec = $this->errorMapper->describe($code, OfficeOperation::Status);

        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'retryable' => $spec['retryable'],
                'next_action' => $spec['next_action']->value,
            ],
        ], $spec['status']);
    }
}
