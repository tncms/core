<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.verified — require a verified email, but only when
 * auth.email_verification_required is enabled (v1.0.0-beta.7.1.14). Unverified
 * users are sent to the verification notice; guests to login.
 */
class EnsureFrontendEmailVerified
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->auth->guard()->user();

        if ($user === null) {
            return redirect()->guest(route('cms.auth.login'));
        }

        if (! $this->auth->emailVerificationRequired()) {
            return $next($request);
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                abort(403, 'Your email address is not verified.');
            }

            return redirect()->route('cms.auth.verification.notice');
        }

        return $next($request);
    }
}
