<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Support\Account\AccountNavItem;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Account Foundation service (v1.0.0-beta.7.1.15).
 *
 * Owns the business logic behind the logged-in frontend account area so the
 * controllers stay thin: profile/email/password/preference updates, session
 * rotation on sensitive changes, and the extensible account navigation.
 *
 * Core deliberately knows ONLY user identity, security, and preferences. It
 * never touches customer, order, address, invoice, wishlist, or membership
 * data — those are plugin domains, injected through the account hooks below.
 */
class AccountManager
{
    /** Locale preference columns, each independently settable. */
    private const LOCALE_FIELDS = ['frontend_locale', 'admin_locale', 'editing_locale'];

    public function __construct(private readonly FrontendAuthManager $auth) {}

    // ---------------------------------------------------------------------
    // Profile
    // ---------------------------------------------------------------------

    /**
     * Persist profile basics. Only whitelisted identity fields are written; the
     * cms.account.profile_data filter lets plugins adjust the payload first.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data, ?Request $request = null): User
    {
        do_action('cms.account.profile.updating', $user, $this->context($request, ['data' => $data]));

        $data = apply_filters('cms.account.profile_data', $data, $this->context($request, ['user' => $user]));

        $attributes = ['name' => $data['name']];
        foreach (['username', 'phone', 'bio', 'avatar'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] !== '' ? $data[$field] : null;
            }
        }

        $user->forceFill($attributes)->save();

        do_action('cms.account.profile.updated', $user, $this->context($request));

        return $user;
    }

    // ---------------------------------------------------------------------
    // Email
    // ---------------------------------------------------------------------

    /**
     * Change the account email. Sensitive: the caller MUST have already
     * validated the current password. Resets verification state when email
     * verification is enabled, then rotates every other session (the email is
     * an auth identifier) while keeping the current session signed in.
     */
    public function updateEmail(User $user, string $newEmail, ?Request $request = null): User
    {
        do_action('cms.account.email.updating', $user, $this->context($request, ['email' => $newEmail]));

        $user->email = $newEmail;

        if ($this->auth->emailVerificationRequired()) {
            $user->email_verified_at = null;
        }

        $user->save();

        // The email identifies the account: invalidate other sessions.
        $this->auth->rotateSessionsExceptCurrent($user, $request);

        if ($this->auth->emailVerificationRequired() && $user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }

        do_action('cms.account.email.updated', $user, $this->context($request));

        return $user;
    }

    // ---------------------------------------------------------------------
    // Password
    // ---------------------------------------------------------------------

    /**
     * Change the account password. Sensitive: the caller MUST have already
     * validated the current password. Delegates the version bump + remember
     * rotation + current-session re-seed to FrontendAuthManager, then hardens
     * the current session against fixation.
     */
    public function updatePassword(User $user, string $newPassword, ?Request $request = null): User
    {
        do_action('cms.account.password.updating', $user, $this->context($request));

        $this->auth->changePassword($user, $newPassword, $request);

        if ($request !== null && $request->hasSession()) {
            // Migrate the (re-seeded) session to a fresh id + CSRF token so a
            // captured session id cannot be replayed after the change.
            $request->session()->regenerate();
            $request->session()->regenerateToken();
        }

        do_action('cms.account.password.updated', $user, $this->context($request));

        return $user;
    }

    /** Verify a plaintext password against the stored hash for sensitive actions. */
    public function validateCurrentPassword(User $user, ?string $plain): bool
    {
        return $plain !== null && $plain !== '' && Hash::check($plain, (string) $user->password);
    }

    // ---------------------------------------------------------------------
    // Preferences
    // ---------------------------------------------------------------------

    /**
     * Persist locale/timezone preferences. Each key is written only when
     * present, so saving one locale never disturbs the others.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePreferences(User $user, array $data, ?Request $request = null): User
    {
        do_action('cms.account.preferences.updating', $user, $this->context($request, ['data' => $data]));

        $data = apply_filters('cms.account.preferences_data', $data, $this->context($request, ['user' => $user]));

        foreach ([...self::LOCALE_FIELDS, 'timezone'] as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field] !== '' ? $data[$field] : null;
            }
        }

        $user->save();

        do_action('cms.account.preferences.updated', $user, $this->context($request));

        return $user;
    }

    // ---------------------------------------------------------------------
    // Sessions
    // ---------------------------------------------------------------------

    /**
     * Log out every other session for the user, keeping the current request
     * signed in. Fires cms.account.sessions.invalidated.
     */
    public function invalidateOtherSessions(User $user, ?Request $request = null): void
    {
        $this->auth->rotateSessionsExceptCurrent($user, $request);

        do_action('cms.account.sessions.invalidated', $user, $this->context($request));
    }

    // ---------------------------------------------------------------------
    // Navigation
    // ---------------------------------------------------------------------

    /** Keys of the Core items: never removed, replaced or taken by an extension. */
    private const CORE_NAV_KEYS = ['dashboard', 'profile', 'security', 'sessions', 'preferences'];

    /** @var array<string, true> legacy navigation problems already logged by this process */
    private array $navWarnings = [];

    /**
     * Build the account navigation: Core items, then the plugin entries of the
     * Account Extension Registry (`cms.account.extensions`, CORE-P12-2/3), then
     * items added through the legacy `cms.account.navigation_items` filter.
     *
     * The legacy filter still receives the Core and registry items, but its
     * output is untrusted: it can only ADD items. Core and registry items are
     * kept as Core built them whatever the filter returns, an item reusing their
     * key (or an earlier legacy key) is dropped, and a malformed item or an item
     * with an unsafe URL is dropped instead of breaking the page.
     *
     * Result is permission-filtered and sorted by priority (ties: Core, then
     * registry entries by id, then legacy items in filter order). Core item URLs
     * follow the request locale (cms_localized_route()), so an /en/ page links
     * to /en/account/...
     *
     * @return array<int, AccountNavItem>
     */
    public function navigationItems(?User $user = null, ?Request $request = null): array
    {
        $core = [
            new AccountNavItem('dashboard', __('Dashboard'), cms_localized_route('cms.account'), 'home', 10),
            new AccountNavItem('profile', __('Profile'), cms_localized_route('cms.account.profile'), 'user', 20),
            new AccountNavItem('security', __('Security'), cms_localized_route('cms.account.security'), 'shield', 30),
            new AccountNavItem('sessions', __('Sessions'), cms_localized_route('cms.account.sessions'), 'device', 40),
            new AccountNavItem('preferences', __('Preferences'), cms_localized_route('cms.account.preferences'), 'sliders', 50),
        ];

        /** @var \TheNguyen\CMS\Account\AccountExtensionRegistry $registry */
        $registry = app('cms.account.extensions');

        try {
            $registered = $registry->resolveNavigation($user, $request);
        } catch (\Throwable) {
            $this->navWarning('the account extension registry could not be resolved; plugin entries ignored');
            $registered = [];
        }

        $filtered = apply_filters(
            'cms.account.navigation_items',
            [...$core, ...$registered],
            $this->context($request, ['user' => $user]),
        );

        $legacy = $this->legacyNavigationItems($filtered, [...self::CORE_NAV_KEYS, ...$registry->reservedKeys()]);

        try {
            $current = url()->current();
        } catch (\Throwable) {
            $current = '';
        }

        $ranked = [];
        foreach ([0 => $core, 1 => $registered, 2 => $legacy] as $rank => $group) {
            foreach ($group as $index => $item) {
                // Registry entries arrive permission-checked and active-marked (by route name).
                if ($rank !== 1) {
                    if ($item->permission !== null && ! cms_can($item->permission, $user)) {
                        continue;
                    }

                    // url() absolutises a root-relative item URL (plugins may build /en/account/…)
                    // and leaves an absolute one unchanged, so both forms compare alike.
                    $item = $item->withActive($item->url !== '' && rtrim($current, '/') === rtrim(url($item->url), '/'));
                }

                $ranked[] = [$item->priority, $rank, $index, $item];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $row): AccountNavItem => $row[3], $ranked);
    }

    /**
     * The items a legacy filter ADDED, validated. Reserved keys (Core, registry)
     * and repeated keys are skipped; the first legacy item with a key wins.
     *
     * @param  list<string>  $reserved
     * @return list<AccountNavItem>
     */
    private function legacyNavigationItems(mixed $filtered, array $reserved): array
    {
        if (! is_array($filtered)) {
            $this->navWarning('the cms.account.navigation_items filter returned a non-list; plugin items ignored');

            return [];
        }

        $seen = array_fill_keys($reserved, true);
        $items = [];

        foreach ($filtered as $raw) {
            $item = $this->legacyNavigationItem($raw);

            if ($item === null) {
                $this->navWarning('a malformed or unsafe account navigation item was dropped');

                continue;
            }

            if (isset($seen[$item->key])) {
                continue;
            }

            $seen[$item->key] = true;
            $items[] = $item;
        }

        return $items;
    }

    private function legacyNavigationItem(mixed $raw): ?AccountNavItem
    {
        if (is_array($raw)) {
            foreach (['key', 'label', 'url', 'icon', 'permission'] as $field) {
                if (isset($raw[$field]) && ! is_string($raw[$field])) {
                    return null;
                }
            }

            $priority = $raw['priority'] ?? 100;
            $badge = $raw['badge'] ?? null;

            if (! is_int($priority) && ! (is_string($priority) && preg_match('/^-?\d{1,6}$/', $priority))) {
                return null;
            }

            if ($badge !== null && ! is_string($badge) && ! is_int($badge)) {
                return null;
            }

            $raw = new AccountNavItem(
                key: $raw['key'] ?? '',
                label: $raw['label'] ?? '',
                url: $raw['url'] ?? '',
                icon: $raw['icon'] ?? null,
                priority: max(0, min(10000, (int) $priority)),
                permission: $raw['permission'] ?? null,
                badge: $badge === null ? null : (string) $badge,
            );
        }

        if (! $raw instanceof AccountNavItem
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $raw->key)
            || trim($raw->label) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $raw->label)
            || ! self::isSafeNavigationUrl($raw->url)
            || ! $this->isOwnSiteUrl($raw->url)) {
            return null;
        }

        return $raw->priority < 0
            ? new AccountNavItem($raw->key, $raw->label, $raw->url, $raw->icon, 0, false, $raw->permission, $raw->badge)
            : $raw;
    }

    /**
     * A root-relative path (`/account/orders`) or an absolute http(s) URL (the
     * host is checked separately for legacy items). Everything else — `javascript:`, `data:`, `//host`, backslashes,
     * whitespace or control characters, relative paths, fragments — is refused.
     */
    public static function isSafeNavigationUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts) && ($parts['host'] ?? '') !== '' && ! isset($parts['user']) && ! isset($parts['pass']);
    }

    /**
     * Root-relative, or absolute on this site's host (the configured APP_URL host
     * or the current request's, which url() uses to build plugin URLs). A legacy
     * item never links off-site, so it cannot send a user to another host from
     * the account shell.
     */
    private function isOwnSiteUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $own = [strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))];

        try {
            $own[] = strtolower(request()->getHost());
        } catch (\Throwable) {
            // No request (console): the configured host only.
        }

        return $host !== '' && in_array($host, $own, true);
    }

    private function navWarning(string $message): void
    {
        if (isset($this->navWarnings[$message])) {
            return;
        }

        $this->navWarnings[$message] = true;

        try {
            \Illuminate\Support\Facades\Log::notice('TN CMS account navigation: '.$message);
        } catch (\Throwable) {
            // Logging must never break the account page.
        }
    }

    // ---------------------------------------------------------------------
    // Health
    // ---------------------------------------------------------------------

    /**
     * Readiness snapshot for /cms-health. Booleans only — no user data.
     *
     * @return array{
     *     account_foundation_ready: bool,
     *     account_routes_ready: bool,
     *     account_hooks_ready: bool,
     *     account_profile_fields_ready: bool
     * }
     */
    public function healthSnapshot(): array
    {
        $routesReady = Route::has('cms.account')
            && Route::has('cms.account.profile')
            && Route::has('cms.account.security')
            && Route::has('cms.account.sessions')
            && Route::has('cms.account.preferences');

        $hooksReady = false;
        try {
            foreach (array_keys(app('cms.hooks')->definitions()) as $name) {
                if (str_starts_with((string) $name, 'cms.account.')) {
                    $hooksReady = true;
                    break;
                }
            }
        } catch (\Throwable) {
            $hooksReady = false;
        }

        $fieldsReady = false;
        try {
            $fieldsReady = Schema::hasColumn('users', 'username')
                && Schema::hasColumn('users', 'phone')
                && Schema::hasColumn('users', 'bio')
                && Schema::hasColumn('users', 'timezone');
        } catch (\Throwable) {
            $fieldsReady = false;
        }

        return [
            'account_foundation_ready' => true,
            'account_routes_ready' => $routesReady,
            'account_hooks_ready' => $hooksReady,
            'account_profile_fields_ready' => $fieldsReady,
        ];
    }

    /**
     * Build a HookContext, always carrying the request when known.
     *
     * @param  array<string, mixed>  $extra
     */
    private function context(?Request $request, array $extra = []): HookContext
    {
        return HookContext::make(['request' => $request, ...$extra]);
    }
}
