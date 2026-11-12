<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — named limiters for the frontend auth POST
 * endpoints (routes/auth.php, unprefixed and localized twins share them).
 *
 * - Every bucket includes the client IP, so there is no global bucket one
 *   client can exhaust for everyone. `Request::ip()` honours Laravel's trusted
 *   proxy configuration, so behind a configured proxy the real client is keyed;
 *   with no trusted proxy the proxy address is (fail-safe, documented).
 * - Identity-bearing endpoints add an identity+IP bucket keyed by a hash of the
 *   normalised email (no address in cache keys). The limit applies identically
 *   whether or not the account exists, so a 429 never reveals an account.
 * - The response is the framework's 429 (Retry-After / X-RateLimit headers),
 *   HTML or JSON per the request. The Filament admin login has its own limiter
 *   and is not routed through these, so admin auth is never double-throttled.
 */
final class FrontendAuthRateLimits
{
    public const LOGIN_PER_IDENTITY = 5;

    public const LOGIN_PER_IP = 20;

    public const REGISTER_PER_IP = 5;

    public const PASSWORD_EMAIL_PER_IDENTITY = 3;

    public const PASSWORD_EMAIL_PER_IP = 10;

    public const PASSWORD_RESET_PER_IDENTITY = 5;

    public const PASSWORD_RESET_PER_IP = 20;

    public const VERIFICATION_SEND_PER_USER = 6;

    public static function register(): void
    {
        RateLimiter::for('cms-auth-login', static fn (Request $request): array => [
            Limit::perMinute(self::LOGIN_PER_IDENTITY)->by('auth-login|id|'.self::identity($request)),
            Limit::perMinute(self::LOGIN_PER_IP)->by('auth-login|ip|'.$request->ip()),
        ]);

        RateLimiter::for('cms-auth-register', static fn (Request $request): Limit => Limit::perMinute(self::REGISTER_PER_IP)
            ->by('auth-register|ip|'.$request->ip()));

        RateLimiter::for('cms-auth-password-email', static fn (Request $request): array => [
            Limit::perMinute(self::PASSWORD_EMAIL_PER_IDENTITY)->by('auth-pw-email|id|'.self::identity($request)),
            Limit::perMinute(self::PASSWORD_EMAIL_PER_IP)->by('auth-pw-email|ip|'.$request->ip()),
        ]);

        RateLimiter::for('cms-auth-password-reset', static fn (Request $request): array => [
            Limit::perMinute(self::PASSWORD_RESET_PER_IDENTITY)->by('auth-pw-reset|id|'.self::identity($request)),
            Limit::perMinute(self::PASSWORD_RESET_PER_IP)->by('auth-pw-reset|ip|'.$request->ip()),
        ]);

        // Signed-in only (cms.auth runs first); keyed by user, IP as a fallback.
        RateLimiter::for('cms-auth-verification-send', static fn (Request $request): Limit => Limit::perMinute(self::VERIFICATION_SEND_PER_USER)
            ->by('auth-verify-send|'.(($id = $request->user()?->getAuthIdentifier()) !== null ? 'u:'.$id : 'ip:'.$request->ip())));
    }

    /** Hash of the normalised submitted email plus the client IP. */
    private static function identity(Request $request): string
    {
        $email = $request->input('email');
        $email = is_string($email) ? Str::lower(trim($email)) : '';

        return hash('sha256', $email).'|'.$request->ip();
    }
}
