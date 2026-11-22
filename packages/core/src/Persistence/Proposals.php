<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Vaulter\Ai\Core\Contracts\Clock;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ReadTokenRecord;
use Vaulter\Ai\Core\Data\StoredProposal;
use Vaulter\Ai\Core\Enums\ApprovalMode;

final readonly class Proposals
{
    public function __construct(
        private OfficeDatabase $db,
        private PayloadCipher $cipher,
        private Clock $clock,
    ) {}

    public function store(CallContext $context, ReadTokenRecord $token, PreparedPlan $plan, string $policyRevision): void
    {
        $scope = $this->scope($context, $plan->resourceId);
        $now = $this->clock->now();
        $expires = new DateTimeImmutable($plan->expiresAt);
        $digest = hex2bin($plan->digest);
        $readToken = hex2bin($token->tokenHashHex);
        if ($digest === false || $readToken === false) {
            throw new RuntimeException('Invalid prepared proposal hashes.');
        }
        $payload = $this->cipher->encrypt([
            'summary' => $plan->summary,
            'targets' => $plan->targets,
            'owner_key' => $plan->ownerKey,
            'preconditions' => $plan->preconditions,
        ], $this->aad($scope, $plan->proposalId, $plan->digest));

        $this->db->transaction(function () use ($scope, $plan, $policyRevision, $token, $readToken, $digest, $now, $expires, $payload): void {
            $this->db->query('ai_proposals')->insert($scope + [
                'proposal_id' => $plan->proposalId,
                'command_name' => $plan->command,
                'read_token_hash' => $this->db->binaryLiteral($readToken),
                'base_generation' => $plan->base->generation,
                'base_position' => $plan->base->position,
                'policy_revision' => $policyRevision,
                'schema_revision' => $token->schemaRevision,
                'digest' => $this->db->binaryLiteral($digest),
                'approval_mode' => $plan->approval->value,
                'decision' => $plan->approval === ApprovalMode::Automatic ? 'approved' : 'pending',
                'created_at' => $this->db->utcTimestamp($now),
                'expires_at' => $this->db->utcTimestamp($expires),
            ]);
            $this->db->query('ai_proposal_payloads')->insert([
                'proposal_id' => $plan->proposalId,
                'key_id' => $payload['key_id'],
                'encrypted_plan' => $this->db->binaryLiteral($payload['ciphertext']),
            ]);
        }, $context->tenantId);
    }

    public function find(CallContext $context, string $resourceId, string $proposalId): ?StoredProposal
    {
        return $this->db->transaction(fn (): ?StoredProposal => $this->findInTransaction($context, $resourceId, $proposalId), $context->tenantId);
    }

    /** Use the caller's CORE transaction. Lock only the decision row, not its nullable payload. */
    public function findInTransaction(CallContext $context, string $resourceId, string $proposalId, bool $lock = false): ?StoredProposal
    {
        if (! Str::isUuid($proposalId) || $proposalId !== strtolower($proposalId)) {
            return null;
        }
        $scope = $this->scope($context, $resourceId);
        $query = $this->db->query('ai_proposals')->where($scope)->where('proposal_id', $proposalId);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($row === null) {
            return null;
        }
        $digest = bin2hex($this->db->decodeBinary(QueryRecord::raw($row, 'digest')));
        $payload = $this->db->query('ai_proposal_payloads')->where('proposal_id', $proposalId)->first();
        $decoded = [];
        if ($payload !== null) {
            try {
                $decoded = $this->cipher->decrypt(
                    QueryRecord::str($payload, 'key_id'),
                    $this->db->decodeBinary(QueryRecord::raw($payload, 'encrypted_plan')),
                    $this->aad($scope, $proposalId, $digest),
                );
            } catch (RuntimeException) {
                // A recorded outcome can still be recovered after the payload or its key is gone.
            }
        }
        $expires = $this->db->parseUtcTimestamp(QueryRecord::raw($row, 'expires_at'))
            ?? throw new RuntimeException('Invalid proposal expiration.');
        $targets = $decoded['targets'] ?? [];
        $preconditions = $decoded['preconditions'] ?? [];

        return new StoredProposal(
            new PreparedPlan(
                $proposalId,
                QueryRecord::str($row, 'command_name'),
                $resourceId,
                new Marker(QueryRecord::int($row, 'base_generation'), QueryRecord::int($row, 'base_position')),
                ApprovalMode::from(QueryRecord::str($row, 'approval_mode')),
                is_string($decoded['summary'] ?? null) ? $decoded['summary'] : '',
                is_array($targets) ? array_values(array_filter($targets, is_string(...))) : [],
                $expires->format('Y-m-d\TH:i:s\Z'),
                $digest,
                is_string($decoded['owner_key'] ?? null) ? $decoded['owner_key'] : '',
                is_array($preconditions) ? array_filter($preconditions, is_string(...), ARRAY_FILTER_USE_KEY) : [],
            ),
            QueryRecord::str($row, 'decision'),
            QueryRecord::str($row, 'policy_revision'),
            $decoded !== [],
        );
    }

    public function decide(CallContext $context, string $resourceId, string $proposalId, string $approver, bool $approved): bool
    {
        return $this->db->query('ai_proposals')
            ->where($this->scope($context, $resourceId))
            ->where('proposal_id', $proposalId)
            ->where('decision', 'pending')
            ->where('expires_at', '>', $this->db->utcTimestamp($this->clock->now()))
            ->update([
                'decision' => $approved ? 'approved' : 'rejected',
                'approver_subject' => $approver,
                'decided_at' => $this->db->utcTimestamp($this->clock->now()),
            ]) === 1;
    }

    /** @return array{tenant_id: string, project_scope: string, effective_subject: string, resource_id: string} */
    private function scope(CallContext $context, string $resourceId): array
    {
        return [
            'tenant_id' => $context->tenantId,
            'project_scope' => $context->projectScope,
            'effective_subject' => $context->effectiveSubject,
            'resource_id' => $resourceId,
        ];
    }

    /**
     * @param  array<string, string>  $scope
     * @return list<string>
     */
    private function aad(array $scope, string $proposalId, string $digest): array
    {
        return [$this->db->table('ai_proposal_payloads'), ...array_values($scope), $proposalId, $digest];
    }
}
