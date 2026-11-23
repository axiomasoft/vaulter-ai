<?php

declare(strict_types=1);

namespace Vaulter\Ai\Documents\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\Marker;
use Vaulter\Ai\Core\Data\ResourceEntry;

interface LiveTextJournal
{
    public function supportsLiveJournal(CallContext $context, ResourceEntry $resource, int $generation, int $version): bool;

    public function receiptMarker(CallContext $context, ResourceEntry $resource, string $receipt): ?Marker;

    public function projected(CallContext $context, ResourceEntry $resource, int $generation, int $version): ?Marker;
}
