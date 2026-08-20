<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.guest — redirect already-authenticated users away from guest-only pages
 * such as login/register (v1.0.0-beta.7.1.14).
 */
class RedirectIfFrontendAuthenticated
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guard()->check()) {
            return redirect()->intended($this->home());
        }

        return $next($request);
    }

    private function home(): string
    {
        return \Illuminate\Support\Facades\Route::has('cms.account') ? route('cms.account') : '/';
    }
}
