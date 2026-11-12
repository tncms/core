<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.role:role1,role2 — require the logged-in user to hold at least one of the
 * listed role slugs (v1.0.0-beta.7.1.14). Guests are sent to login; an
 * authenticated user without a matching role gets a 403.
 */
class RequireRole
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return app(\TheNguyen\CMS\Http\Support\AuthUrlPolicy::class)->redirectToLogin($request);
        }

        if ($roles !== [] && ! $user->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
