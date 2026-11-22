<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;

/** Lets a text command owner supply expected check values from the committed receipt evidence. */
interface TextVerificationEvidence
{
    /**
     * @return array{text_exact: string, marks: list<string>}
     */
    public function expectedTextChecks(CallContext $context, string $resourceId, string $receiptId, Marker $marker): array;
}
