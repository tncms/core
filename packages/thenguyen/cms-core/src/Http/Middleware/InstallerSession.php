<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web Installer Core (v1.0.0-beta.6).
 *
 * Forces a FILE session driver for installer requests. On a fresh install the
 * configured driver may be "database" with no database yet — that would break
 * CSRF/session on the installer forms. This runs before StartSession (which
 * reads session.driver at handle time), so the installer always has a working,
 * DB-free session.
 *
 * It ALSO pins a stable session cookie name. The default cookie name derives from
 * APP_NAME (config/session.php: Str::slug(APP_NAME).'-session'), and the database
 * step writes the operator's APP_NAME to .env — so without pinning, the very next
 * wizard request looks for a renamed cookie, orphans the session that carries the
 * verified DB config, and bounces the operator back a step (CORE-DIST-1-H2). A
 * fixed installer cookie keeps the multi-step flow on one session.
 */
class InstallerSession
{
    /** Stable cookie name for the whole wizard, independent of APP_NAME. */
    public const COOKIE = 'tncms_installer_session';

    public function handle(Request $request, Closure $next): Response
    {
        config([
            'session.driver' => 'file',
            'session.connection' => null,
            'session.cookie' => self::COOKIE,
        ]);

        return $next($request);
    }
}
