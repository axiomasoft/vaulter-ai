<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Operations;

use Vaulter\Ai\Core\Contracts\CommandWriteCapability;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;
use Vaulter\Ai\Core\Enums\OfficeOperation;
use Vaulter\Ai\Core\Exceptions\OfficeException;
use Vaulter\Ai\Core\Registry\OfficeRegistry;

/**
 * Implements office.describe: returns schema descriptor only when resource is accessible
 * AND the host policy publishes the requested command to the current caller.
 */
final readonly class Describe
{
    public function __construct(
        private ResourceDirectory $directory,
        private HostPolicy $policy,
        private OfficeRegistry $registry,
        private ?CommandWriteCapability $writeCapability = null,
    ) {}

    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse
    {
        $input = $request->input;
        $resourceId = $input->resource_id ?? null;
        $commandName = $input->command ?? null;

        if (! is_string($resourceId) || $resourceId === '' || ! is_string($commandName) || $commandName === '') {
            throw OfficeException::invalidInput('describe.resource_id and describe.command must be non-empty strings.');
        }

        $command = $this->registry->command($commandName);
        if ($command === null) {
            throw OfficeException::resourceUnavailable();
        }

        $descriptor = $command->descriptor();
        $requiredPermission = $descriptor->requiredPermission !== '' ? $descriptor->requiredPermission : 'view';

        $entry = $this->directory->findAccessible($context, $resourceId, $requiredPermission);
        if ($entry === null) {
            throw OfficeException::resourceUnavailable();
        }

        if ($descriptor->resourceType !== $entry->resourceType || ! $this->policy->allowsCommand($context, $entry, $commandName)) {
            throw OfficeException::resourceUnavailable();
        }
        if ($this->writeCapability !== null && ! $this->writeCapability->canWrite($context, $entry, $descriptor)) {
            throw OfficeException::unsupportedContent();
        }

        $result = (object) [
            'command' => $descriptor->command,
            'effect' => $descriptor->effect,
            'input_schema' => $descriptor->inputSchema,
            'verification' => $descriptor->verification,
        ];

        return OfficeResponse::success(OfficeOperation::Describe, $result);
    }
}
