<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * /upgrade access gate (CORE-UPGRADE-1, §9/§10).
 *
 * Hard lifecycle separation + authorization for the manual Core upgrade
 * workflow, using existing canonical authorities only (no bespoke password or
 * token, §10):
 *
 *   1. The site MUST be installed — an uninstalled site sends the admin to the
 *      /install wizard, never exposes /upgrade as a public recovery endpoint.
 *   2. The request MUST be an authenticated Super Admin on the default (admin
 *      panel) guard — anonymous users are sent to the admin login; an
 *      authenticated non-Super-Admin gets a 403.
 */
class EnsureUpgradeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1) Installed-state gate.
        if (! app('cms.installer')->isInstalled()) {
            return Route::has('cms.install.welcome')
                ? redirect()->route('cms.install.welcome')
                : abort(404);
        }

        // 2) Authentication (default = admin panel web guard).
        $user = Auth::user();
        if ($user === null) {
            $login = Route::has('filament.admin.auth.login')
                ? route('filament.admin.auth.login')
                : (Route::has('cms.auth.login') ? route('cms.auth.login') : url('/'));

            return redirect()->guest($login);
        }

        // 3) Super Admin only.
        if (! app('cms.permission')->isSuperAdmin($user)) {
            abort(403);
        }

        return $next($request);
    }
}
