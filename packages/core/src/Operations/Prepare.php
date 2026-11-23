<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use stdClass;
use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Persistence\Proposals;
use Vaulter\Ai\Core\Persistence\ReadTokens;
use Vaulter\Ai\Core\Protocol\ProposalDigest;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

final readonly class Prepare
{
    public function __construct(
        private OfficeRegistry $registry,
        private ResourceDirectory $directory,
        private HostPolicy $policy,
        private ReadTokens $tokens,
        private Proposals $proposals,
        private ?CommandWriteCapability $writeCapability = null,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $name = $input->command ?? null;
        $resourceId = $input->resource_id ?? null;
        $rawToken = $input->read_token ?? null;
        $arguments = $input->arguments ?? null;
        if (! is_string($name) || ! is_string($resourceId) || ! is_string($rawToken) || ! $arguments instanceof stdClass) {
            throw OfficeException::invalidInput();
        }

        $command = $this->registry->command($name) ?? throw OfficeException::resourceUnavailable();
        $descriptor = $command->descriptor();
        $resource = $this->directory->findAccessible($context, $resourceId, $descriptor->requiredPermission);
        if ($resource === null || $resource->resourceType !== $descriptor->resourceType
            || ! $this->policy->allowsCommand($context, $resource, $name)) {
            throw OfficeException::resourceUnavailable();
        }
        if ($this->writeCapability !== null && ! $this->writeCapability->canWrite($context, $resource, $descriptor)) {
            throw OfficeException::unsupportedContent();
        }

        $token = $this->tokens->verify($context, $rawToken, $resourceId);
        if (! $token->completeScope) {
            throw OfficeException::preconditionFailed('A complete read is required.');
        }

        $draft = $command->prepare($context, $resource, $token, $arguments);
        if ($this->registry->owner($draft->ownerKey) === null) {
            throw OfficeException::temporarilyUnavailable();
        }
        $result = (object) [
            'proposal_id' => $draft->proposalId,
            'base' => $draft->base->toStdClass(),
            'approval' => $draft->approval->value,
            'summary' => $draft->summary,
            'targets' => $draft->targets,
            'expires_at' => $draft->expiresAt,
        ];
        $result->digest = ProposalDigest::digest($input, $result);
        $plan = new PreparedPlan(
            $draft->proposalId, $draft->command, $draft->resourceId, $draft->base, $draft->approval,
            $draft->summary, $draft->targets, $draft->expiresAt, $result->digest, $draft->ownerKey, $draft->preconditions,
        );
        $this->proposals->store($context, $token, $plan, $this->policy->profile($context)->revision);

        return OfficeResponse::success(OfficeOperation::Prepare, $result);
    }
}
