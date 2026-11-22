<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Illuminate\Http\Request;
use Vaulter\Ai\Core\Contracts\CallContextResolver;
use Vaulter\Ai\Core\Data\CallContext;

/**
 * Default fail-closed resolver requiring the host application to bind authentication context resolution.
 */
final readonly class NullCallContextResolver implements CallContextResolver
{
    public function resolve(Request $request): ?CallContext
    {
        return null;
    }
}
