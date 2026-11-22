<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Vaulter\Ai\Core\Data\CallContext;
use Vaulter\Ai\Core\Data\OfficeRequest;
use Vaulter\Ai\Core\Data\OfficeResponse;

/**
 * Primary PHP entry point shared identically by background jobs and HTTP endpoints.
 */
interface Office
{
    public function handle(CallContext $context, OfficeRequest $request): OfficeResponse;
}
