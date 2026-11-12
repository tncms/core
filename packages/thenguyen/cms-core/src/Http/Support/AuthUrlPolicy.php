<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\LocalizedRouteUrl;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * CORE-P14-1 — the ONE Core policy for sending a refused frontend request to an
 * auth page.
 *
 * Locale: the auth page is built in the locale of the request being refused.
 * The request's own active `{locale}` route segment wins — it is bound when the
 * route matches, so the order a route lists `cms.auth` and `cms.locale` cannot
 * change it, and on a Livewire update the request handed to the middleware is
 * the original page request rebuilt from the checksummed snapshot. Without a
 * segment the locale applied by `cms.locale` (or the default) is used.
 *
 * Intended destination: captured only from a navigable page request, through
 * {@see FrontendIntendedUrl::capture()}. A POST, a JSON request or a Referer is
 * never stored. This replaces `redirect()->guest()`, which falls back to the
 * untrusted Referer for any non-GET request.
 *
 * Origin: absolute auth-page URLs come from the router (the request root), as
 * every Laravel redirect does. Mailed links keep their own configured-origin
 * authority ({@see \TheNguyen\CMS\Security\TrustedOrigin}).
 */
final class AuthUrlPolicy
{
    public function __construct(
        private readonly FrontendIntendedUrl $intended,
        private readonly LanguageManager $languages,
        private readonly LocalizedRouteUrl $urls,
    ) {}

    /** The locale an auth page for this request is built in. */
    public function locale(Request $request): string
    {
        $segment = $request->route('locale');

        if (is_string($segment) && $segment !== '') {
            $code = $this->languages->normalizeCode($segment);

            if ($this->languages->isActive($code)) {
                return $code;
            }
        }

        return $this->languages->currentCode();
    }

    /**
     * URL of a Core auth/account route in this request's locale.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function route(Request $request, string $name, array $parameters = []): string
    {
        return $this->urls->route($name, $parameters, $this->locale($request));
    }

    /** Redirect a refused request to the login page, remembering it only when safe. */
    public function redirectToLogin(Request $request): RedirectResponse
    {
        $this->intended->capture($request);

        return redirect()->to($this->route($request, 'cms.auth.login'));
    }
}
