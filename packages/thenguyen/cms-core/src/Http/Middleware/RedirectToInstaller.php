<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Friendly first-run routing (CORE-INSTALLER-2, §24).
 *
 * On a fresh, uninstalled extract the frontend catch-all would try to resolve a
 * page from a database that does not exist yet — a raw error for the operator.
 * This middleware is prepended to the "web" group ONLY while the CMS is not
 * installed and leads every non-installer request to the wizard, so a fresh site
 * always opens on /install instead of failing or forcing the operator to guess
 * the installer path.
 *
 * The installer itself (/install…) and the framework health check (/up) pass
 * through untouched. It is never registered once the site is installed, so the
 * normal frontend is unaffected.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        $installer = app('cms.installer');
        $path = trim($request->path(), '/');

        // Only hijack when the site genuinely cannot serve yet: NOT installed AND
        // no committed .env — a fresh extract. "No committed .env" (not a usable-
        // looking DB config default) is the reliable signal: config/database.php
        // defaults DB_DATABASE/DB_USERNAME to non-empty values, so a keyless fresh
        // extract still looks DB-configured. A configured site — the test harness
        // with its dev .env, or a CLI-installed site — has a committed .env and is
        // never redirected. The installer itself and the health check pass through.
        if (! $installer->isInstalled()
            && ! $installer->hasCommittedEnv()
            && $path !== 'up'
            && ! str_starts_with($path, 'install')) {
            return redirect()->route('cms.install.welcome');
        }

        return $next($request);
    }
}
