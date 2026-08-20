<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * P5H.1B — permanent redirect from a canonical base segment to its localized projection.
 *
 * When a non-default `{locale}`-prefixed request uses the CANONICAL base segment (e.g.
 * `/vi/products/watch`) but the Route Segment Dictionary projects that base to a localized
 * segment for the locale (`products → san-pham`), this issues a single 301 to the dictionary URL
 * (`/vi/san-pham/watch`). The localized segment itself never re-projects (no dictionary key), so
 * there is no redirect loop or chain. Non-locale first segments, the default locale, and bases
 * without a localized projection pass straight through.
 *
 * Registered as the `cms.localized-base` alias; attached to the localized route groups. It reads
 * only the frozen dictionary + LanguageManager — it owns no routing or URL logic.
 */
final class RedirectToLocalizedBaseSegment
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly RouteSegmentDictionary $dictionary,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Method safety: only redirect safe, cacheable methods (GET/HEAD). A non-GET request is
        // never a public-content navigation, so it passes through unredirected.
        if (! $request->isMethodCacheable()) {
            return $next($request);
        }

        $segments = $request->segments();

        if (count($segments) < 2) {
            return $next($request);
        }

        $locale = strtolower($segments[0]);
        $normalized = $this->languages->normalizeCode($locale);

        // Only a non-default, active locale prefix participates.
        if ($normalized === $this->languages->defaultCode() || ! $this->languages->isActive($normalized)) {
            return $next($request);
        }

        $base = $segments[1];
        $projected = $this->dictionary->projectBase($base, $normalized);

        // Canonical base with a localized projection → 301 to the dictionary URL. A base that is
        // already localized (or has no mapping) projects to itself → pass through (no loop).
        if ($projected === $base) {
            return $next($request);
        }

        $segments[1] = $projected;
        $target = '/'.implode('/', $segments);

        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $target .= '?'.$query;
        }

        return new RedirectResponse($target, 301);
    }
}
