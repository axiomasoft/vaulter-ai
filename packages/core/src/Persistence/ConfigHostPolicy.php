<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Vaulter\Ai\Core\Contracts\HostPolicy;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\HostProfile;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Enums\ApprovalMode;

/**
 * Config-backed HostPolicy evaluating allowed commands and approval mode per CallContext profile.
 */
final readonly class ConfigHostPolicy implements HostPolicy
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function profile(CallContext $context): HostProfile
    {
        $profiles = $this->config->get('vaulter-ai.profiles', []);
        $raw = is_array($profiles) && isset($profiles[$context->profile]) && is_array($profiles[$context->profile])
            ? $profiles[$context->profile]
            : [];

        $executor = is_string($raw['executor'] ?? null) && $raw['executor'] !== '' ? $raw['executor'] : 'laravel-ai';
        $connection = is_string($raw['provider_connection'] ?? null) && $raw['provider_connection'] !== ''
            ? $raw['provider_connection']
            : null;

        $commands = [];
        if (is_array($raw['commands'] ?? null)) {
            foreach ($raw['commands'] as $cmd) {
                if (is_string($cmd) && $cmd !== '') {
                    $commands[] = $cmd;
                }
            }
        }

        $rawApproval = is_string($raw['approval'] ?? null) ? $raw['approval'] : 'human';
        $approval = match ($rawApproval) {
            'automatic' => ApprovalMode::Automatic,
            default => ApprovalMode::Human,
        };

        $revision = is_string($raw['revision'] ?? null) && $raw['revision'] !== ''
            ? $raw['revision']
            : $context->policyRevision;

        return new HostProfile(
            name: $context->profile,
            executor: $executor,
            providerConnection: $connection,
            commands: array_values(array_unique($commands)),
            approval: $approval,
            revision: $revision,
        );
    }

    /** @return list<string> */
    public function allowedCommands(CallContext $context, ResourceEntry $resource): array
    {
        return $this->profile($context)->commands;
    }

    public function allowsCommand(CallContext $context, ResourceEntry $resource, string $command): bool
    {
        return in_array($command, $this->allowedCommands($context, $resource), true);
    }
}
