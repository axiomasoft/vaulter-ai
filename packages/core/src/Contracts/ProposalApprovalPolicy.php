<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Data\StoredProposal;

/**
 * Separates human review/decision authorization from initiator execution authorization.
 */
interface ProposalApprovalPolicy
{
    public function canView(CallContext $viewer, ResourceEntry $resource, StoredProposal $proposal): bool;

    public function canApprove(CallContext $approver, ResourceEntry $resource, StoredProposal $proposal): bool;

    public function isApprovalStillValidForCommit(CallContext $initiator, ResourceEntry $resource, StoredProposal $proposal): bool;
}
