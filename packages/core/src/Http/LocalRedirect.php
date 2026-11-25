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
 * as an open redirect. Anything else falls back to the previous page when it is on
 * this application's origin (the Referer header is client-controlled, so it gets the
 * same treatment), otherwise to the proposals list under the configured workspace prefix.
 */
final class LocalRedirect
{
    public static function after(Request $request): RedirectResponse
    {
        $candidate = $request->input('redirect_to');

        return new RedirectResponse(
            self::isLocalPath($candidate) ? $candidate : self::sameOriginPrevious(),
            303,
        );
    }

    private static function sameOriginPrevious(): string
    {
        $fallback = route('vaulter-ai.workspace.proposals.index');
        $previous = url()->previous($fallback);
        $root = url()->to('/');

        return $previous === $root || str_starts_with($previous, $root.'/') ? $previous : $fallback;
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
