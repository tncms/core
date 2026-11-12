<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Support;

use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\SafeInternalRedirect;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — the post-sign-in "intended" destination for
 * frontend auth, replacing `redirect()->intended()`.
 *
 * The intended URL is pulled (consumed once — a stale value is never reused by a
 * later sign-in) and accepted only when it is on this site's own scheme + host +
 * port and its path passes Core's ONE redirect authority
 * ({@see SafeInternalRedirect}: no "//", "/\", scheme or control characters).
 * A target that is itself an auth page (login, register, logout, password reset,
 * email verification — with or without a locale prefix) is rejected so sign-in
 * can never loop. Anything rejected yields the caller's fallback. Percent-encoded
 * paths stay same-origin: the result is always this site's root plus the path.
 */
final class FrontendIntendedUrl
{
    /** First path segments of the auth pages that must never be an intended target. */
    private const AUTH_SEGMENTS = ['login', 'register', 'logout', 'forgot-password', 'reset-password', 'email'];

    /** Livewire's own endpoints: `/livewire/...` and the hashed `/livewire-<hex>/update` (CORE-P14-1). */
    private const LIVEWIRE_SEGMENT = '/^livewire(-[0-9a-f]+)?$/';

    /** Query keys never carried into a stored or followed destination (CORE-P14-1). */
    private const SENSITIVE_QUERY_KEYS = [
        'token', '_token', 'access_token', 'refresh_token', 'id_token', 'auth', 'auth_token',
        'signature', 'sig', 'password', 'secret', 'api_key', 'apikey', 'api-key', 'key', 'otp', 'code', 'state',
    ];

    public function __construct(
        private readonly SafeInternalRedirect $redirects,
        private readonly LanguageManager $languages,
    ) {}

    public function pull(Request $request, string $fallback): string
    {
        $intended = $request->hasSession() ? $request->session()->pull('url.intended') : null;

        return (is_string($intended) ? $this->accept($request, $intended) : null) ?? $fallback;
    }

    /**
     * CORE-P14-1 — remember the request as the post-sign-in destination, only when
     * it is a navigable page: a GET that is not a JSON request and that passes the
     * same checks as {@see pull()}. Anything else (a POST, a JSON call, a Livewire
     * endpoint) clears the stored value — the Referer is never read. On a Livewire
     * update Core's middleware receives the original page request rebuilt from the
     * checksummed snapshot, so the tab's own page is what gets remembered.
     */
    public function capture(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $url = $request->isMethod('GET') && ! $request->expectsJson() && $request->route() !== null
            ? $this->accept($request, $request->fullUrl())
            : null;

        $url === null
            ? $request->session()->forget('url.intended')
            : $request->session()->put('url.intended', $url);
    }

    /** The absolute same-origin URL to redirect to, or null when the value is refused. */
    private function accept(Request $request, string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $path = $this->sameOriginPath($request, $url);
        $safe = $path === null ? '' : $this->redirects->resolve($this->withoutSensitiveQuery($path), '');

        if ($safe === '' || $this->isExcluded($safe)) {
            return null;
        }

        return $request->getSchemeAndHttpHost().$safe;
    }

    /** Drop credential-like query parameters (tokens, signatures, passwords); keep the rest as sent. */
    private function withoutSensitiveQuery(string $path): string
    {
        [$base, $query] = array_pad(explode('?', $path, 2), 2, null);

        if ($query === null || $query === '') {
            return $base;
        }

        $kept = array_filter(explode('&', $query), static function (string $pair): bool {
            $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            $key = (string) preg_replace('/\[.*$/', '', $key);

            return $pair !== '' && ! in_array($key, self::SENSITIVE_QUERY_KEYS, true);
        });

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    /** The path (+ query) of a same-origin URL, or null for any other origin. */
    private function sameOriginPath(Request $request, string $url): ?string
    {
        // parse_url() silently rewrites control characters to "_", hiding them from
        // the path check: refuse them on the raw value first (CR/LF/NUL/DEL).
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $sameOrigin = strtolower($parts['scheme']) === $request->getScheme()
            && strtolower($parts['host']) === strtolower($request->getHost())
            && (int) $port === (int) $request->getPort();

        if (! $sameOrigin || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        return isset($parts['query']) && $parts['query'] !== '' ? $path.'?'.$parts['query'] : $path;
    }

    /** An auth page (sign-in loop) or a Livewire transport/asset endpoint (not a page). */
    private function isExcluded(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', (string) parse_url($path, PHP_URL_PATH)), static fn (string $s): bool => $s !== ''));
        $first = strtolower(rawurldecode($segments[0] ?? ''));

        if ($first !== '' && preg_match(self::LIVEWIRE_SEGMENT, $first) !== 1 && $this->languages->isActive($this->languages->normalizeCode($first))) {
            $first = strtolower(rawurldecode($segments[1] ?? ''));
        }

        return in_array($first, self::AUTH_SEGMENTS, true) || preg_match(self::LIVEWIRE_SEGMENT, $first) === 1;
    }
}
