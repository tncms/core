<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * Canonical default-locale routing (core policy).
 *
 * The default locale must NOT carry a URL prefix — "/" and "/blog/x" are
 * canonical, while "/{default}/" and "/{default}/blog/x" are duplicate URLs.
 * This middleware inspects the first path segment and, when it equals the
 * default locale, issues a 301 redirect to the same path with that segment
 * stripped (query string preserved). Non-default locales and non-locale first
 * segments pass straight through, so localized routes keep working.
 *
 * It is fully generic: no language code is hard-coded — the default is read
 * from {@see LanguageManager::defaultCode()}, and when the site is configured
 * to prefix the default locale too ({@see LanguageManager::shouldPrefixDefaultLocale()})
 * the prefixed URL is canonical and no redirect happens.
 *
 * Registered as the "cms.canonical-locale" alias so plugins can attach it to
 * their own localized route groups (see CmsServiceProvider::loadRoutes()).
 */
class RedirectDefaultLocalePrefix
{
    public function __construct(private readonly LanguageManager $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        // When the default locale is intentionally prefixed, the prefixed URL
        // IS the canonical one — never redirect.
        if ($this->languages->shouldPrefixDefaultLocale()) {
            return $next($request);
        }

        $segments = $request->segments();
        $first = $segments[0] ?? null;

        // Only a leading default-locale segment is non-canonical. Non-default
        // locales (e.g. /en/...) and non-locale paths (e.g. /blog/...) pass
        // through untouched.
        if ($first === null || strtolower($first) !== $this->languages->defaultCode()) {
            return $next($request);
        }

        // Segments are DECODED: percent-encode backslashes, control characters and
        // "%" so "/vi/%5Cevil.com" or "/vi/%09/evil.com" can never become a browser
        // protocol-relative (off-site) Location. Other characters stay byte-identical
        // (CORE-PLATFORM-SEAMS-R2 security HIGH-1).
        $canonical = '/'.implode('/', array_map(
            static fn (string $segment): string => (string) preg_replace_callback(
                '/[\\\\%\x00-\x1F\x7F]/',
                static fn (array $m): string => rawurlencode($m[0]),
                $segment,
            ),
            array_slice($segments, 1),
        ));

        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $canonical .= '?'.$query;
        }

        // CORE-PLATFORM-SEAMS-R2: a default-locale base spelled with its dictionary
        // projection (/vi/tim-kiem) goes straight to the canonical key path (/search)
        // in one hop, instead of to an unrouted /tim-kiem.
        $canonical = app(\TheNguyen\CMS\Localization\DefaultLocaleCanonicalPath::class)
            ->forSegments(array_values(array_slice($segments, 1)), $query) ?? $canonical;

        // Emit a ROOT-RELATIVE Location (e.g. "/docs?x=1"), built straight from
        // the request path — NOT through the URL generator / request root /
        // APP_URL, which may carry a "/public" segment that must never appear in
        // a public canonical URL. Browsers resolve the relative target against
        // the current host, so the canonical path stays clean and domain-agnostic.
        return new RedirectResponse($canonical, 301);
    }
}
