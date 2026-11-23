<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Persistence;

use Vaulter\ValueObjects\Access\ActorRef;

final readonly class CommandActor
{
    public function __construct(
        public ActorRef $principal,
        public ?string $tenant,
        public string $project,
        public string $subject,
        public string $driveId,
        public string $resourceId,
    ) {}
}

final readonly class CommandJournal
{
    /** @param array<string, mixed> $intent @return array<string, mixed> */
    public function register(CommandActor $scope, string $key, string $proposal, string $digest, int $generation, int $schema, int $readPosition, array $intent): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function status(CommandActor $scope, string $key): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function evidence(CommandActor $scope, string $receipt): array
    {
        return [];
    }

    /** @return array{generation: int, position: int, text: string, textPassed: bool, marks: list<string>, marksPassed: bool}|null */
    public function verifyCurrent(CommandActor $scope, string $receipt): ?array
    {
        return null;
    }

    /** @return array{generation: int, position: int}|null */
    public function receiptMarker(CommandActor $scope, string $receipt): ?array
    {
        return null;
    }

    /** @return array{generation: int, position: int}|null */
    public function projected(CommandActor $scope, int $generation, int $stateVersion): ?array
    {
        return null;
    }
}
