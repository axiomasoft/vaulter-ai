<?php

declare(strict_types=1);

namespace Vaulter\Ai\Core\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Builds the 303 response for workspace form posts (proposal decisions, run resumes).
 *
 * The caller-supplied redirect_to must stay on this host: only absolute paths are
 * accepted, and network-path references ("//host", "/\host", which browsers treat
 * as "//host") and control characters are rejected, so the endpoint cannot be used
 * as an open redirect. Anything else falls back to the previous page or the
 * proposals list under the configured workspace prefix.
 */
final class LocalRedirect
{
    public static function after(Request $request): RedirectResponse
    {
        $candidate = $request->input('redirect_to');

        return new RedirectResponse(
            self::isLocalPath($candidate) ? $candidate : url()->previous(route('vaulter-ai.workspace.proposals.index')),
            303,
        );
    }

    /**
     * @phpstan-assert-if-true string $candidate
     */
    public static function isLocalPath(mixed $candidate): bool
    {
        return is_string($candidate)
            && str_starts_with($candidate, '/')
            && ! str_starts_with($candidate, '//')
            && ! str_starts_with($candidate, '/\\')
            && preg_match('/[\x00-\x1F\x7F]/', $candidate) === 0;
    }
}
