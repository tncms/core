<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Frontend Authentication foundation (v1.0.0-beta.7.1.14).
 *
 * Owns the frontend identity lifecycle on the shared web guard + users table:
 * registration, login, logout, password changes, session rotation, and the
 * device-fingerprint / session-version defense that invalidates stolen
 * cookies after a device change or password change. Controllers stay thin;
 * all policy lives here.
 *
 * Session keys (namespaced under "cms_auth") back a frontend-specific session
 * lifetime that is enforced by the cms.frontend_session / cms.auth middleware
 * independently of the global Laravel session cookie lifetime.
 *
 * Frontend login never grants admin access: capability is decided entirely by
 * roles/permissions, and admin panel access remains gated by canAccessPanel().
 */
class FrontendAuthManager
{
    private const SESSION_KEY = 'cms_auth';

    public function guard(): \Illuminate\Contracts\Auth\StatefulGuard
    {
        /** @var \Illuminate\Contracts\Auth\StatefulGuard $guard */
        $guard = Auth::guard('web');

        return $guard;
    }

    // ---------------------------------------------------------------------
    // Configuration (DB settings override config defaults)
    // ---------------------------------------------------------------------

    /** Resolve an auth setting: DB "auth.<key>" over config "cms.auth.<key>". */
    public function config(string $key): mixed
    {
        return settings("auth.{$key}", config("cms.auth.{$key}"));
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->config('registration_enabled');
    }

    public function emailVerificationRequired(): bool
    {
        return (bool) $this->config('email_verification_required');
    }

    public function autoLoginAfterRegistration(): bool
    {
        return (bool) $this->config('auto_login_after_registration');
    }

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    /**
     * Create a frontend user, assign the default role, and fire hooks. Input
     * must already be validated by the caller (FormRequest / controller).
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data, ?Request $request = null): User
    {
        $data = (array) apply_filters('cms.auth.registration_data', $data, HookContext::make(['request' => $request]));

        do_action('cms.auth.registering', $data, HookContext::make(['request' => $request]));

        $user = new User;
        $user->name = (string) $data['name'];
        $user->email = (string) $data['email'];
        $user->password = Hash::make((string) $data['password']);
        $user->frontend_session_version = 1;
        $user->save();

        $this->assignDefaultRole($user);

        if ($this->emailVerificationRequired()) {
            $user->sendEmailVerificationNotification();
        }

        do_action('cms.auth.registered', $user, HookContext::make(['request' => $request]));

        return $user;
    }

    /** Resolve the configured default frontend role id (filterable). */
    public function defaultRoleId(): int
    {
        $configured = apply_filters('cms.auth.default_role_id', $this->config('default_role_id'));

        if ($configured !== null && Role::whereKey($configured)->exists()) {
            return (int) $configured;
        }

        return $this->safeFrontendRole()->getKey();
    }

    /** Assign the resolved default role without ever granting admin access. */
    public function assignDefaultRole(User $user): void
    {
        $user->roles()->syncWithoutDetaching([$this->defaultRoleId()]);
    }

    /**
     * The safe fallback frontend role: the configured slug (default
     * "subscriber"), created on demand with NO permissions if missing.
     */
    public function safeFrontendRole(): Role
    {
        $slug = (string) ($this->config('default_role_slug') ?: 'subscriber');

        /** @var Role $role */
        $role = Role::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'description' => 'Default frontend role (no admin access).'],
        );

        return $role;
    }

    // ---------------------------------------------------------------------
    // Login / logout
    // ---------------------------------------------------------------------

    /**
     * Attempt to authenticate with credentials. Fires attempt/success/failed
     * hooks; on success runs the full session/device rotation. Returns success.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    public function attempt(array $credentials, bool $remember, Request $request): bool
    {
        do_action('cms.auth.login.attempt', ['email' => $credentials['email'] ?? null], HookContext::make(['request' => $request]));

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'] ?? null)->first();

        if ($user === null || ! Hash::check((string) ($credentials['password'] ?? ''), (string) $user->password)) {
            do_action('cms.auth.login.failed', ['email' => $credentials['email'] ?? null], HookContext::make(['request' => $request]));

            return false;
        }

        $this->login($user, $remember, $request);

        return true;
    }

    /**
     * Log a user in and establish a hardened frontend session: rotate on device
     * change, regenerate the session id + CSRF token, and seed the version and
     * absolute-expiry keys used by the session middleware.
     */
    public function login(User $user, bool $remember, Request $request): void
    {
        $newHash = $this->userAgentHash($request);
        $previousHash = $user->frontend_last_user_agent_hash;

        $deviceChanged = $this->config('rotate_session_on_device_change')
            && $previousHash !== null
            && ! hash_equals((string) $previousHash, $newHash);

        // Invalidate other/stolen sessions before issuing the new one.
        if ($deviceChanged || $this->config('single_session_per_user')) {
            $this->bumpSessionVersion($user);
            $this->rotateRememberToken($user);
        }

        $user->forceFill([
            'frontend_last_login_at' => now(),
            'frontend_last_login_ip' => $request->ip(),
            'frontend_last_user_agent_hash' => $newHash,
            // Normalise so a freshly-registered user always has a concrete
            // version to compare against (never relies on a NULL/default read).
            'frontend_session_version' => (int) ($user->frontend_session_version ?? 1),
        ])->save();

        $this->guard()->login($user, $remember);

        // Session fixation + CSRF hardening.
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $this->seedSession($request, $user, $remember, $newHash);

        do_action('cms.auth.login.success', $user, HookContext::make(['request' => $request, 'remember' => $remember]));
    }

    /** Log out the current frontend user and clear session auth state. */
    public function logout(Request $request): void
    {
        $user = $this->guard()->user();

        $this->rotateRememberToken($user instanceof User ? $user : null);

        $this->guard()->logout();

        $request->session()->forget(self::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        do_action('cms.auth.logout', $user, HookContext::make(['request' => $request]));
    }

    // ---------------------------------------------------------------------
    // Password changes
    // ---------------------------------------------------------------------

    /**
     * Persist a new password and, per policy, invalidate every other session by
     * bumping the version and rotating the remember token. Fires the reset hook.
     */
    public function changePassword(User $user, string $newPassword, ?Request $request = null): void
    {
        $user->password = Hash::make($newPassword);

        if ($this->config('force_logout_on_password_change')) {
            $this->bumpSessionVersion($user);
            $this->rotateRememberToken($user);
        }

        $user->save();

        // Keep the CURRENT session valid by re-seeding it with the new version.
        if ($request !== null && $this->guard()->check() && $this->guard()->id() === $user->getKey()) {
            $this->seedSession($request, $user, (bool) $this->sessionGet($request, 'remember', false), $this->userAgentHash($request));
        }

        do_action('cms.auth.password_reset', $user, HookContext::make(['request' => $request]));
    }

    /**
     * Invalidate every OTHER session for the user while keeping the CURRENT
     * request signed in (v1.0.0-beta.7.1.15). Bumps the session version and
     * rotates the remember token — so any other browser/device is logged out on
     * its next request — then re-seeds the current session with the new version.
     * Reused by the Account Foundation for email change and "log out other
     * sessions".
     */
    public function rotateSessionsExceptCurrent(User $user, ?Request $request = null): void
    {
        $this->bumpSessionVersion($user);
        $this->rotateRememberToken($user); // persists the user, incl. the bumped version

        if ($request !== null && $this->guard()->check() && $this->guard()->id() === $user->getKey()) {
            $this->seedSession(
                $request,
                $user,
                (bool) $this->sessionGet($request, 'remember', false),
                $this->userAgentHash($request),
            );
        }
    }

    // ---------------------------------------------------------------------
    // Session security primitives
    // ---------------------------------------------------------------------

    /** SHA-256 of the User-Agent (never store the raw agent). */
    public function userAgentHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }

    /** Increment the per-user session version (invalidates other sessions). */
    public function bumpSessionVersion(User $user): void
    {
        $user->frontend_session_version = ((int) ($user->frontend_session_version ?? 1)) + 1;
    }

    /** Issue a fresh remember token, invalidating any stolen remember cookie. */
    public function rotateRememberToken(?User $user): void
    {
        if ($user === null) {
            return;
        }

        $user->setRememberToken(\Illuminate\Support\Str::random(60));
        $user->remember_token_rotated_at = now();
        $user->save();
    }

    /**
     * Enforce the session policy for the current authenticated request:
     * version match, absolute expiry, and idle timeout. Returns true when the
     * session may proceed; false when it was invalidated (caller redirects).
     */
    public function enforceSessionPolicy(Request $request): bool
    {
        $user = $this->guard()->user();

        if (! $user instanceof User) {
            return true; // Not our concern; cms.auth handles the guest case.
        }

        // Re-authenticated via a remember cookie, or an admin-initiated session
        // visiting the frontend for the first time: seed keys, do not reject.
        if (! $this->sessionHas($request, 'version')) {
            $this->seedSession($request, $user, $this->guard()->viaRemember(), $this->userAgentHash($request));

            return true;
        }

        if ((int) $this->sessionGet($request, 'version') !== (int) $user->frontend_session_version) {
            return $this->invalidate($request, $user, 'version_mismatch');
        }

        if ($this->isExpired($request)) {
            return $this->invalidate($request, $user, 'expired');
        }

        if ($this->isIdleTimedOut($request)) {
            return $this->invalidate($request, $user, 'idle_timeout');
        }

        $this->touchActivity($request);

        return true;
    }

    public function isExpired(Request $request): bool
    {
        $expiresAt = (int) $this->sessionGet($request, 'expires_at', 0);

        return $expiresAt > 0 && now()->getTimestamp() > $expiresAt;
    }

    public function isIdleTimedOut(Request $request): bool
    {
        $idle = $this->config('idle_timeout_minutes');

        if ($idle === null || (int) $idle <= 0) {
            return false;
        }

        $last = (int) $this->sessionGet($request, 'last_activity_at', 0);

        return $last > 0 && (now()->getTimestamp() - $last) > ((int) $idle * 60);
    }

    public function touchActivity(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY.'.last_activity_at', now()->getTimestamp());
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Seed the namespaced session keys that back the frontend session policy. */
    private function seedSession(Request $request, User $user, bool $remember, string $uaHash): void
    {
        $minutes = (int) $this->config($remember ? 'frontend_remember_lifetime_minutes' : 'frontend_session_lifetime_minutes');

        $request->session()->put(self::SESSION_KEY, [
            'version' => (int) $user->frontend_session_version,
            'remember' => $remember,
            'ua_hash' => $uaHash,
            'expires_at' => now()->addMinutes(max(1, $minutes))->getTimestamp(),
            'last_activity_at' => now()->getTimestamp(),
        ]);
    }

    private function invalidate(Request $request, User $user, string $reason): bool
    {
        do_action('cms.auth.session_invalidated', $user, HookContext::make(['request' => $request, 'reason' => $reason]));

        $this->guard()->logout();
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return false;
    }

    private function sessionHas(Request $request, string $key): bool
    {
        return $request->session()->has(self::SESSION_KEY.'.'.$key);
    }

    private function sessionGet(Request $request, string $key, mixed $default = null): mixed
    {
        return $request->session()->get(self::SESSION_KEY.'.'.$key, $default);
    }

    // ---------------------------------------------------------------------
    // Health
    // ---------------------------------------------------------------------

    /**
     * Count-free readiness snapshot for /cms-health. No emails, ids, or tokens.
     *
     * @return array{
     *     frontend_auth_ready: bool,
     *     frontend_registration_enabled: bool,
     *     frontend_default_role_configured: bool,
     *     frontend_session_policy_ready: bool,
     *     frontend_email_verification_available: bool
     * }
     */
    public function healthSnapshot(): array
    {
        $defaultRoleConfigured = false;

        try {
            $defaultRoleConfigured = Role::query()->exists();
        } catch (\Throwable) {
            $defaultRoleConfigured = false;
        }

        return [
            'frontend_auth_ready' => true,
            'frontend_registration_enabled' => $this->registrationEnabled(),
            'frontend_default_role_configured' => $defaultRoleConfigured,
            'frontend_session_policy_ready' => $this->config('rotate_session_on_device_change') !== null,
            'frontend_email_verification_available' => in_array(
                \Illuminate\Contracts\Auth\MustVerifyEmail::class,
                class_implements(User::class) ?: [],
                true,
            ),
        ];
    }
}
