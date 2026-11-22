<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Protocol;

final class RequestFingerprint
{
    /** Raw SHA-256 bytes for storage on both connections. */
    public static function of(string $command, string $proposalId, string $digest): string
    {
        return hash('sha256', ProposalDigest::canonicalize((object) [
            'command' => $command,
            'digest' => $digest,
            'proposal_id' => $proposalId,
        ]), true);
    }
}
