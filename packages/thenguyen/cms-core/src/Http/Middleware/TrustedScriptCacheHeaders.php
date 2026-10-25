<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheNguyen\CMS\Services\TrustedExternalScriptRegistry;

/**
 * Keeps pages that carry a trusted external script out of every cache
 * (CORE-EXTENSION-TRUST S1, ADR D-5).
 *
 * Pushed onto the `web` group, so it wraps the frontend route middleware
 * (including {@see OptimizeResponseHeaders}) and runs LAST on the way out. It
 * acts only when the {@see TrustedExternalScriptRegistry} actually emitted a
 * trusted script into this response; every other response is untouched, so no
 * header changes globally. A response that already says `no-store` is left as
 * is; otherwise Cache-Control becomes `no-store, private`, so revoking or
 * deactivating a script can never be undone by a browser-cached page.
 */
class TrustedScriptCacheHeaders
{
    public function __construct(private readonly TrustedExternalScriptRegistry $trustedScripts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
                return $response;
            }

            if (! $this->trustedScripts->renderedThisRequest()) {
                return $response;
            }

            if ($response->headers->hasCacheControlDirective('no-store')) {
                return $response;
            }

            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->remove('Expires');
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }
}
