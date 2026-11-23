<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Persistence;

use Vaulter\Ai\Core\Contracts\ProposalApprovalPolicy;
use Vaulter\Ai\Core\Contracts\ResourceDirectory;
use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Data\StoredProposal;

/**
 * Default policy verifying current view permission to inspect diffs and edit or explicit approval
 * capability to approve/reject proposals within the same tenant and project scope.
 */
final readonly class DefaultProposalApprovalPolicy implements ProposalApprovalPolicy
{
    public function __construct(
        private ResourceDirectory $directory,
    ) {}

    public function canView(CallContext $viewer, ResourceEntry $resource, StoredProposal $proposal): bool
    {
        if ($proposal->tenantId !== null && ! hash_equals($proposal->tenantId, $viewer->tenantId)) {
            return false;
        }
        if ($proposal->projectScope !== null && ! hash_equals($proposal->projectScope, $viewer->projectScope)) {
            return false;
        }

        return $this->directory->findAccessible($viewer, $resource->resourceId, 'view') !== null;
    }

    public function canApprove(CallContext $approver, ResourceEntry $resource, StoredProposal $proposal): bool
    {
        if (! $this->canView($approver, $resource, $proposal)) {
            return false;
        }

        $explicit = $approver->attributes['can_approve'] ?? null;
        if ($explicit === false) {
            return false;
        }
        if ($explicit === true) {
            return true;
        }

        return $this->directory->findAccessible($approver, $resource->resourceId, 'edit') !== null;
    }

    public function isApprovalStillValidForCommit(CallContext $initiator, ResourceEntry $resource, StoredProposal $proposal): bool
    {
        return true;
    }
}
