<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zero-CLI install support (CORE-DIST-1-H2).
 *
 * A fresh shared-hosting extract has no .env and an empty APP_KEY. Cookie/session
 * encryption then throws MissingAppKeyException on the very first request — before
 * the /install wizard can render or generate a key at its database step. This
 * middleware is prepended to the "web" group ONLY while the CMS is not installed,
 * so it runs ahead of EncryptCookies and guarantees a stable key exists (creating
 * .env from .env.example on first hit). It is a no-op the moment a key is present,
 * and is never registered once the site is installed.
 */
class EnsureInstallerAppKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) config('app.key') === '') {
            app('cms.installer')->ensureRuntimeAppKey();
        }

        return $next($request);
    }
}
