<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * cms.permission:some.permission — require the logged-in user to hold every
 * listed permission (v1.0.0-beta.7.1.14). Guests are sent to login; an
 * authenticated user missing a permission gets a 403.
 */
class RequirePermission
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('cms.auth.login'));
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
