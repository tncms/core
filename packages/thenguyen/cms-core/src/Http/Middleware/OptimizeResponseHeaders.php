<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * Safe HTTP response header optimization for the public frontend (CORE-OPTIMIZE-3
 * §6). Attached to the frontend content route group only, so it never runs on
 * Admin, installer, upgrade, Livewire or plugin routes.
 *
 * Behaviour is gated behind {@see CmsOptimizationPolicy::responseOptimizationEnabled()}
 * — when OFF (the default) this is a strict no-op and responses keep their
 * pre-CORE-OPTIMIZE-3 headers (upgrade-safe).
 *
 * When ON, it distinguishes two request classes using the EXISTING cacheability
 * authority ({@see PublicContentCacheManager::shouldCache()}), never a new one:
 *
 *   - Anonymous, cacheable public HTML (guest GET, no preview/draft) →
 *     `Cache-Control: private[, max-age=N]`. Always `private`: browser-only, never
 *     a shared/CDN cache, so no cross-user reuse is possible. The URL already
 *     carries the locale, so caching is keyed per-locale and no `Vary` header is
 *     needed (§11). N defaults to 0 (revalidate) — the operator opts into a
 *     positive lifetime.
 *   - Everything else in the group (authenticated, non-GET, preview/draft) →
 *     `Cache-Control: no-store, private`. This is the safety guarantee: an
 *     authenticated or personalised response can never be stored by any cache.
 *
 * It only ever touches a plain 200 `text/html` response, and never overrides a
 * response that already carries an explicit caching directive (a controller/plugin
 * choice). Failure is safe: any error leaves the response exactly as rendered.
 */
class OptimizeResponseHeaders
{
    /**
     * Explicit Cache-Control directives that mark a response as already
     * deliberately managed upstream. Symfony's *computed* default (`no-cache,
     * private`) carries none of these, so a normally-rendered frontend page is
     * still managed here, while an intentional controller/plugin header is left
     * untouched.
     */
    private const EXPLICIT_DIRECTIVES = ['no-store', 'max-age', 's-maxage', 'public', 'immutable'];

    public function __construct(
        private readonly CmsOptimizationPolicy $policy,
        private readonly PublicContentCacheManager $publicCache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (! $this->policy->responseOptimizationEnabled()) {
                return $response;
            }

            if (! $this->manageable($response)) {
                return $response;
            }

            $this->applyHeaders($request, $response);
        } catch (\Throwable $e) {
            // Optimization is optional (§16): never fail a rendered response.
            report($e);
        }

        return $response;
    }

    /**
     * Whether this response is a plain 200 HTML body we may safely annotate.
     * Streamed/binary responses, non-200s (redirects, 404, 503), non-HTML bodies
     * and responses already carrying an explicit caching directive are left alone.
     */
    private function manageable(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        if (! str_contains($contentType, 'text/html')) {
            return false;
        }

        foreach (self::EXPLICIT_DIRECTIVES as $directive) {
            if ($response->headers->hasCacheControlDirective($directive)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Set the scoped Cache-Control. Anonymous cacheable HTML gets a private
     * browser cache (revalidate by default, positive max-age when configured);
     * every other request in the group gets an unconditional no-store.
     */
    private function applyHeaders(Request $request, Response $response): void
    {
        // Browser cache headers depend on the REQUEST class (guest GET, no
        // preview), not the server-side cache toggle — hence isCacheableRequest(),
        // not shouldCache(). An operator can run browser response caching with the
        // server resolution cache off, and vice versa.
        if ($this->publicCache->isCacheableRequest($request)) {
            $ttl = $this->policy->publicHtmlTtl();

            $value = $ttl > 0
                ? sprintf('private, max-age=%d', $ttl)
                : 'private, no-cache';

            $response->headers->set('Cache-Control', $value);

            return;
        }

        $response->headers->set('Cache-Control', 'no-store, private');
    }
}
