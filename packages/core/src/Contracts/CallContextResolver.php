<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Contracts;

use Illuminate\Http\Request;
use Vaulter\Ai\Core\Data\CallContext;

/**
 * Resolves a trusted server CallContext from an inbound HTTP request.
 */
interface CallContextResolver
{
    public function resolve(Request $request): ?CallContext;
}
