<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.auth — require a logged-in frontend user AND a valid frontend session
 * (v1.0.0-beta.7.1.14). Enforcing the session policy here means the
 * version/expiry/idle checks can never be forgotten on a protected route: a
 * stolen cookie whose session version no longer matches is rejected.
 */
class FrontendAuthenticate
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->auth->guard()->check() || ! $this->auth->enforceSessionPolicy($request)) {
            if ($request->expectsJson()) {
                abort(401, 'Unauthenticated.');
            }

            return redirect()->guest(route('cms.auth.login'));
        }

        return $next($request);
    }
}
