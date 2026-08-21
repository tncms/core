<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\InstallerBootstrapKey;

/**
 * Pre-install key bootstrap (CORE-INSTALLER-2, supersedes CORE-DIST-1-H2).
 *
 * A fresh shared-hosting extract has no .env and an empty APP_KEY, so cookie /
 * session encryption throws MissingAppKeyException on the very first request —
 * before the /install wizard can render. This middleware is prepended to the
 * "web" group ONLY while the CMS is not installed, so it runs ahead of
 * EncryptCookies and guarantees a usable key exists for the pre-install runtime.
 *
 * Unlike the old behavior it NEVER writes .env. Instead it uses the ephemeral
 * InstallerBootstrapKey (storage/framework/tncms-installer.key) — a key that is
 * scoped strictly to the pre-install runtime and never persisted as the site's
 * permanent APP_KEY.
 *
 * Two states:
 *   1. No permanent key yet (fresh): mint/load the ephemeral key and apply it to
 *      this request so encryption works.
 *   2. Permanent key present but install unfinished (CONFIG_COMMITTED_NOT_INSTALLED):
 *      the current session cookie may still be encrypted with the ephemeral key,
 *      so register that key as a previous key. This lets the wizard session
 *      survive the env commit → retry without a re-login (§17, §43). It is a
 *      no-op once the ephemeral key file is gone (i.e. after a finished install).
 */
class EnsureInstallerAppKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $boot = app(InstallerBootstrapKey::class);

        if ((string) config('app.key') === '') {
            config(['app.key' => $boot->resolve()]);
        } elseif ($boot->exists()) {
            config(['app.previous_keys' => array_values(array_unique(array_filter(
                array_merge((array) config('app.previous_keys', []), [$boot->peek()]),
            )))]);
        }

        return $next($request);
    }
}
