<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\CommandOutcome;
use Vaulter\Ai\Core\Data\CommandScope;
use Vaulter\Ai\Core\Data\PreparedPlan;
use Vaulter\Ai\Core\Data\ResourceEntry;
use Vaulter\Ai\Core\Data\StoredProposal;

/**
 * Optional lifecycle port synchronizing AI proposals with host tracked-change suggestions.
 */
interface ProposalSuggestionPort
{
    public function onProposalPrepared(CallContext $context, ResourceEntry $resource, PreparedPlan $plan): void;

    public function onProposalDecided(CallContext $context, StoredProposal $proposal, string $decision): void;

    public function onProposalCommitted(CommandScope $scope, StoredProposal $proposal, CommandOutcome $outcome): void;
}
