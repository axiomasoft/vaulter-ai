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
 * Authenticated host workspace endpoints for listing, inspecting, and deciding Office proposals (P/Q).
 * Separate from LLM/tool operations so model calls never assume approval authority.
 */
final readonly class ProposalWorkspaceController
{
    public function __construct(
        private Container $container,
        private WireErrorMapper $errorMapper,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        $resourceId = is_string($request->query('resource_id')) ? $request->query('resource_id') : null;
        $decision = is_string($request->query('decision')) ? $request->query('decision') : null;

        try {
            $items = $this->workspace()->listProposals($context, $resourceId, $decision);

            return new JsonResponse(['data' => $items], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->resolveContext($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        try {
            $proposal = $this->workspace()->showProposal($context, $id);

            return new JsonResponse(['data' => $proposal], 200);
        } catch (OfficeException $e) {
            return $this->errorJson($e->errorCode);
        }
    }

    public function decide(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $context = $this->resolveContext($request);
        if ($context === null) {
            return $this->errorJson(ErrorCode::AuthenticationRequired);
        }

        $digest = $request->input('digest');
        $rawApproved = $request->input('approved');
        if ($rawApproved === null && is_string($request->input('decision'))) {
            $rawApproved = match ($request->input('decision')) {
                'approved', 'approve' => true,
                'rejected', 'reject' => false,
                default => null,
            };
        }

        $approved = $this->parseBool($rawApproved);
        $preferredCommandId = is_string($request->input('command_id')) ? $request->input('command_id') : null;

        try {
            if (! is_string($digest) || $digest === '' || $approved === null) {
                throw OfficeException::invalidInput('digest and boolean approved are required.');
            }

            $result = $this->workspace()->decideProposal(
                approver: $context,
                proposalId: $id,
                digest: $digest,
                approved: $approved,
                preferredCommandId: $preferredCommandId,
            );

            if (! $request->expectsJson() && ! $request->isJson()) {
                $redirectTo = is_string($request->input('redirect_to')) && str_starts_with($request->input('redirect_to'), '/')
                    ? $request->input('redirect_to')
                    : url()->previous('/office/proposals');

                return new RedirectResponse($redirectTo, 303);
            }

            return new JsonResponse(['data' => $result], 200);
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
        $spec = $this->errorMapper->describe($code, OfficeOperation::Commit);

        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'retryable' => $spec['retryable'],
                'next_action' => $spec['next_action']->value,
            ],
        ], $spec['status']);
    }

    private function parseBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }
        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => null,
            };
        }

        return null;
    }
}
