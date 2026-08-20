<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drive the Filament admin INTERFACE locale from the logged-in user's
 * preference (v1.0.0-beta.7.1.10.1; originally beta.5.1).
 *
 * TN CMS interface strings resolve through tn_trans()/current_locale(), and
 * Filament's OWN built-in strings (Create / Save / Delete / pagination) follow
 * Laravel's app locale. This middleware pins BOTH to the admin UI locale the
 * user has chosen — resolved (and persisted) by
 * {@see \TheNguyen\CMS\Services\LocalePreferenceManager}.
 *
 * Two query parameters are kept strictly separate (the beta.7.1.10 coupling
 * fix):
 *
 *   - `?lang=xx`   → admin UI locale. Drives app()->setLocale() / setCurrent()
 *                    here, and is persisted to session + user.admin_locale.
 *   - `?locale=xx` → content editing locale. Remembered here in the session
 *                    (cms.editing_locale) and the user.editing_locale column;
 *                    it must NOT change the UI language, admin_locale or
 *                    frontend_locale.
 *
 * So editing a Vietnamese translation (`?locale=vi`) leaves an English admin UI
 * untouched, and switching the UI (`?lang=vi`) leaves the edited translation
 * untouched. Guests (login screen) simply get the default language — nothing is
 * persisted before login. Fully guarded: locale alignment is best-effort and
 * never breaks a request.
 */
class SetCmsAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $prefs = app('cms.locale_preference');
            $user = $request->user();

            // ?lang= is the admin UI locale switch; ?locale= is the content
            // editing locale switch. Each persists to its own session key and
            // user column (logged-in users only) — never the other's.
            $prefs->persistAdminLocaleFromRequest($request, $user);
            $prefs->persistEditingLocaleFromRequest($request, $user);

            // setCurrent() pins the CMS current locale AND app()->setLocale(),
            // which is what tn_trans() and Filament's __() lookups read. This is
            // the admin UI locale ONLY — content editing reads its locale via
            // LocalePreferenceManager::resolveEditingLocale().
            app('cms.language')->setCurrent($prefs->resolveAdminLocale($request, $user));
        } catch (\Throwable) {
            // Best-effort: a missing languages/users table must not 500 the panel.
        }

        return $next($request);
    }
}
