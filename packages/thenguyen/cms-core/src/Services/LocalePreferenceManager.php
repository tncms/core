<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Locale preference resolution & persistence (v1.0.0-beta.7.1.10.1).
 *
 * Resolves THREE fully independent locales, each with its own request signal,
 * resolution chain, session key, and (where applicable) persistence column:
 *
 *   - Admin UI locale     — what language the admin INTERFACE renders in
 *                           (menus, labels, buttons, Filament strings).
 *   - Content editing locale — which content TRANSLATION is being edited
 *                           (post/page/term/menu/widget/settings fields).
 *   - Frontend locale     — what language the public site renders in.
 *
 * The admin UI locale and the content editing locale use SEPARATE query
 * parameters so switching one never moves the other (the beta.7.1.10 bug):
 *
 *   - Admin UI locale signal:        ?lang=xx
 *   - Content editing locale signal: ?locale=xx
 *
 * This is preference resolution only — it never changes routing. The frontend's
 * URL `{locale}` prefix always wins; this layer just fills in what to use when
 * no explicit signal is present, and remembers a logged-in user's choice.
 *
 * Admin UI resolution order:
 *   1. ?lang=xx  (explicit override)
 *   2. user.admin_locale
 *   3. session(cms.admin_locale)
 *   4. default → vi  (defaultCode covers both)
 *
 * Content editing resolution order:
 *   1. ?locale=xx  (explicit override)
 *   2. user.editing_locale
 *   3. session(cms.editing_locale)
 *   4. default → vi  (defaultCode covers both)
 *
 * Frontend resolution order:
 *   1. route {locale}  (authoritative — never overridden)
 *   2. ?locale=xx
 *   3. user.frontend_locale
 *   4. session(cms.frontend_locale)
 *   5. default
 *
 * Only ACTIVE language codes are ever accepted; anything else is ignored so a
 * stale/disabled preference quietly falls through to the next source.
 */
class LocalePreferenceManager
{
    public const ADMIN_SESSION_KEY = 'cms.admin_locale';

    public const EDITING_SESSION_KEY = 'cms.editing_locale';

    public const FRONTEND_SESSION_KEY = 'cms.frontend_locale';

    public function __construct(private readonly LanguageManager $languages) {}

    /**
     * The locale the admin INTERFACE should render in. Driven by ?lang, NOT by
     * the content editing ?locale, so editing a translation never changes the
     * UI language.
     */
    public function resolveAdminLocale(Request $request, ?Authenticatable $user = null): string
    {
        if (($code = $this->validQuery($request, 'lang')) !== null) {
            return $code;
        }

        if (($code = $this->validAttribute($user, 'admin_locale')) !== null) {
            return $code;
        }

        if (($code = $this->validSession($request, self::ADMIN_SESSION_KEY)) !== null) {
            return $code;
        }

        return $this->languages->defaultCode();
    }

    /**
     * Persist an explicit admin-UI-locale choice from the request's ?lang param.
     * No-op when ?lang is absent. The admin is auth-gated, so persistence is a
     * no-op for guests (no preference is stored before login).
     */
    public function persistAdminLocaleFromRequest(Request $request, ?Authenticatable $user): void
    {
        $requested = $request->query('lang');

        if (is_string($requested) && $requested !== '') {
            $this->persistAdminLocale($request, $user, $requested);
        }
    }

    /**
     * Persist an explicit admin-locale choice. The admin is auth-gated, so this
     * is a no-op for guests (no preference is stored before login).
     */
    public function persistAdminLocale(Request $request, ?Authenticatable $user, string $code): void
    {
        if ($user === null) {
            return;
        }

        $code = $this->languages->normalizeCode($code);

        if (! $this->languages->isActive($code)) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::ADMIN_SESSION_KEY, $code);
        }

        $this->storeUserAttribute($user, 'admin_locale', $code);
    }

    /**
     * The content TRANSLATION locale currently being edited/viewed. Driven by
     * ?locale, NEVER by the admin-UI ?lang. Persisted per user so the admin
     * keeps working in the same content language across logins/pages.
     *
     * Order: ?locale → user.editing_locale → session(cms.editing_locale)
     * → default → vi.
     */
    public function resolveEditingLocale(Request $request, ?Authenticatable $user = null): string
    {
        if (($code = $this->validQuery($request, 'locale')) !== null) {
            return $code;
        }

        if (($code = $this->validAttribute($user, 'editing_locale')) !== null) {
            return $code;
        }

        if (($code = $this->validSession($request, self::EDITING_SESSION_KEY)) !== null) {
            return $code;
        }

        return $this->languages->defaultCode();
    }

    /**
     * Remember an explicit content-editing-locale choice from ?locale in the
     * session, and on the user.editing_locale column when logged in.
     * Deliberately does NOT touch users.admin_locale or users.frontend_locale —
     * the content editing language is independent of the admin UI language and
     * the public reading language. No-op when ?locale is absent or inactive.
     */
    public function persistEditingLocaleFromRequest(Request $request, ?Authenticatable $user = null): void
    {
        $requested = $request->query('locale');

        if (! is_string($requested) || $requested === '') {
            return;
        }

        $code = $this->languages->normalizeCode($requested);

        if (! $this->languages->isActive($code)) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::EDITING_SESSION_KEY, $code);
        }

        if ($user !== null) {
            $this->storeUserAttribute($user, 'editing_locale', $code);
        }
    }

    /**
     * The locale the public site should render in. The route prefix wins.
     */
    public function resolveFrontendLocale(Request $request, ?string $routeLocale, ?Authenticatable $user = null): string
    {
        if ($this->isActive($routeLocale)) {
            return $this->languages->normalizeCode($routeLocale);
        }

        if (($code = $this->validQuery($request)) !== null) {
            return $code;
        }

        if (($code = $this->validAttribute($user, 'frontend_locale')) !== null) {
            return $code;
        }

        if (($code = $this->validSession($request, self::FRONTEND_SESSION_KEY)) !== null) {
            return $code;
        }

        return $this->languages->defaultCode();
    }

    /**
     * Persist a frontend-locale choice. Frontend can be anonymous, so the
     * session is always updated; the user column only when logged in.
     */
    public function persistFrontendLocale(Request $request, ?Authenticatable $user, string $code): void
    {
        $code = $this->languages->normalizeCode($code);

        if (! $this->languages->isActive($code)) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::FRONTEND_SESSION_KEY, $code);
        }

        if ($user !== null) {
            $this->storeUserAttribute($user, 'frontend_locale', $code);
        }
    }

    private function validQuery(Request $request, string $param = 'locale'): ?string
    {
        $value = $request->query($param);

        return $this->isActive(is_string($value) ? $value : null)
            ? $this->languages->normalizeCode($value)
            : null;
    }

    private function validSession(Request $request, string $key): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get($key);

        return $this->isActive(is_string($value) ? $value : null)
            ? $this->languages->normalizeCode($value)
            : null;
    }

    private function validAttribute(?Authenticatable $user, string $attribute): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }

        $value = $user->getAttribute($attribute);

        return $this->isActive(is_string($value) ? $value : null)
            ? $this->languages->normalizeCode($value)
            : null;
    }

    private function isActive(?string $code): bool
    {
        return is_string($code) && $code !== '' && $this->languages->isActive($code);
    }

    /**
     * Write a preference column only when it actually changed, quietly (no
     * model events / timestamps churn). Fully guarded so a missing column or
     * locked row can never break the request that triggered it.
     */
    private function storeUserAttribute(Authenticatable $user, string $attribute, string $code): void
    {
        if (! $user instanceof Model) {
            return;
        }

        try {
            if ($user->getAttribute($attribute) !== $code) {
                $user->forceFill([$attribute => $code])->saveQuietly();
            }
        } catch (\Throwable) {
            // Best-effort: never 500 a page over a preference write.
        }
    }
}
