<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Front-end maintenance-mode gate (v1.0.0-beta.4).
 *
 * Applied to the public frontend route group only (see routes/frontend.php), so
 * the admin panel, Livewire, cms-health, robots.txt and sitemap.xml — which are
 * NOT in that group — are never blocked. The excluded-path / bypass rules add a
 * second layer of safety for anything that does fall through.
 *
 * The whole check is wrapped so maintenance mode can never break the site: any
 * unexpected error falls through to the normal response.
 */
class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $maintenance = app('cms.maintenance');

            if (! $maintenance->isEnabled()) {
                return $next($request);
            }

            if ($maintenance->isExcludedPath($request->path())) {
                return $next($request);
            }

            if ($maintenance->shouldBypass($request, $request->user())) {
                return $next($request);
            }

            return $maintenance->response($request);
        } catch (\Throwable) {
            // Never take the site down because of the maintenance gate itself.
            return $next($request);
        }
    }
}
