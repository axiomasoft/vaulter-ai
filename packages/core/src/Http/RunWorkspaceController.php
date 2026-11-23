<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;
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
        private Container $container,
        private WireErrorMapper $errorMapper,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        try {
            $run = $this->workspace()->showRun($context, $id);

            return new JsonResponse(['data' => $run], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    public function resume(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $context = $this->resolveContext($request);
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
            $run = $this->workspace()->resumeRun($context, $id, $argumentsByResource, $rereadOnly);

            if (! $request->expectsJson() && ! $request->isJson()) {
                $redirectTo = is_string($request->input('redirect_to')) && str_starts_with($request->input('redirect_to'), '/')
                    ? $request->input('redirect_to')
                    : url()->previous('/office/proposals');

                return new RedirectResponse($redirectTo, 303);
            }

            return new JsonResponse(['data' => $run], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    private function resolveContext(Request $request): ?CallContext
    {
        return $this->container->make(CallContextResolver::class)->resolve($request);
    }

    private function workspace(): OfficeWorkspaceService
    {
        return $this->container->make(OfficeWorkspaceService::class);
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
