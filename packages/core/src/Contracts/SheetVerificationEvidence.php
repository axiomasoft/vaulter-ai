<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;

/** Lets a spreadsheet command owner supply expected check values from committed receipt evidence and live calculation. */
interface SheetVerificationEvidence
{
    /**
     * @return array{
     *     row_ids: list<string>,
     *     formulas: list<string>,
     *     calculated_values: list<int|float>,
     *     calculated_at: array{generation: int, position: int},
     *     sum: int|float,
     * }
     */
    public function expectedSheetChecks(
        CallContext $context,
        string $resourceId,
        string $receiptId,
        Marker $receiptMarker,
        Marker $verifyMarker,
        string $mode,
    ): array;
}
