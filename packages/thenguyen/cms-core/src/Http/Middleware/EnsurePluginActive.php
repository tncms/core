<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `cms.plugin-active:{slug}` — attached by Core to every plugin route group
 * (CORE-PLATFORM-SEAMS-R2). Answers 404 when the plugin is no longer active in
 * the persisted (shared-cache) activation state, so a route table that outlived
 * a deactivation — a cached route file, or an unsupported long-running worker —
 * never keeps serving a deactivated plugin. One cached settings read; no-op for
 * active plugins.
 */
final class EnsurePluginActive
{
    public function handle(Request $request, Closure $next, string $slug = ''): Response
    {
        if ($slug === '' || ! app('cms.extension')->isPluginActive($slug)) {
            abort(404);
        }

        return $next($request);
    }
}
