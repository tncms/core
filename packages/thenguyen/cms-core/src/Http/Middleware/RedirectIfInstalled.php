<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web Installer Core (v1.0.0-beta.6).
 *
 * Blocks the installer once TN CMS is locked (marker file or env flag). Applied
 * to every installer step except /install/finish, so a locked site can never
 * re-run requirements, rewrite .env, re-run migrations, or create another
 * super-admin. Locked requests are redirected to the homepage.
 */
class RedirectIfInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app('cms.installer')->isInstalled()) {
            return redirect('/');
        }

        return $next($request);
    }
}
