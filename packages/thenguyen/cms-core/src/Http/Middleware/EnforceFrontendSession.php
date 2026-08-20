<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.frontend_session — enforce the frontend session/device policy (version
 * match, absolute expiry, idle timeout) for an already-authenticated request
 * (v1.0.0-beta.7.1.14). Standalone counterpart to cms.auth, which also runs it.
 * A guest simply passes through; policy failure logs the user out and redirects.
 */
class EnforceFrontendSession
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guard()->check() && ! $this->auth->enforceSessionPolicy($request)) {
            if ($request->expectsJson()) {
                abort(401, 'Session invalidated.');
            }

            return redirect()->guest(route('cms.auth.login'));
        }

        return $next($request);
    }
}
