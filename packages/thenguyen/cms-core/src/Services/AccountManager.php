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

    /**
     * Build the account navigation. Core items first, then the
     * cms.account.navigation_items filter lets plugins add/remove/reorder.
     * Result is permission-filtered, priority-sorted, and active-marked.
     *
     * @return array<int, AccountNavItem>
     */
    public function navigationItems(?User $user = null, ?Request $request = null): array
    {
        $items = [
            new AccountNavItem('dashboard', __('Dashboard'), route('cms.account'), 'home', 10),
            new AccountNavItem('profile', __('Profile'), route('cms.account.profile'), 'user', 20),
            new AccountNavItem('security', __('Security'), route('cms.account.security'), 'shield', 30),
            new AccountNavItem('sessions', __('Sessions'), route('cms.account.sessions'), 'device', 40),
            new AccountNavItem('preferences', __('Preferences'), route('cms.account.preferences'), 'sliders', 50),
        ];

        /** @var array<int, AccountNavItem|array<string, mixed>> $filtered */
        $filtered = apply_filters(
            'cms.account.navigation_items',
            $items,
            $this->context($request, ['user' => $user]),
        );

        try {
            $current = url()->current();
        } catch (\Throwable) {
            $current = '';
        }

        $normalised = [];
        foreach ($filtered as $item) {
            $item = $item instanceof AccountNavItem ? $item : AccountNavItem::fromArray((array) $item);

            if ($item->permission !== null && ! cms_can($item->permission, $user)) {
                continue;
            }

            $normalised[] = $item->withActive($item->url !== '' && rtrim($current, '/') === rtrim($item->url, '/'));
        }

        usort($normalised, static fn (AccountNavItem $a, AccountNavItem $b): int => $a->priority <=> $b->priority);

        return $normalised;
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
