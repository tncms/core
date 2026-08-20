<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N A2 — the ONE redirect authority for locale transitions.
 *
 * Resolves an untrusted `redirect` input to a safe, root-relative internal path,
 * or a caller-supplied fallback. It rejects absolute URLs, protocol-relative
 * ("//") and back-slash ("/\") variants, any scheme ("://"), and control
 * characters (CR/LF) — preventing open redirects, cross-domain redirects, and
 * header injection. No consumer may re-implement redirect resolution.
 */
final class SafeInternalRedirect
{
    public function resolve(mixed $target, string $fallback = '/'): string
    {
        if (! is_string($target) || $target === '' || $target[0] !== '/') {
            return $fallback;
        }

        // Reject protocol-relative "//host" and "/\host".
        if (isset($target[1]) && ($target[1] === '/' || $target[1] === '\\')) {
            return $fallback;
        }

        if (str_contains($target, '://')) {
            return $fallback;
        }

        // Reject control characters (CR/LF/NUL/DEL) — header-injection safe.
        if (preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return $fallback;
        }

        return $target;
    }
}
