<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin session continuation (CORE-H-Z1-REMEDIATION-R3, ER-9, WCAG 2.2.1). Called by the Core
 * session-timeout script only when the user asks for it: "Stay signed in" before the idle limit, or
 * "Continue" after signing in again in another tab. It is a Filament authenticated route, so it
 * inherits the admin panel's auth middleware; an expired or signed-out session gets 401 and no token.
 *
 * The request names the account the open page was rendered for (`for`). The current CSRF token is
 * returned only when the signed-in account is that same account, so a page left open by one account
 * can never be continued - and its unsaved form submitted - under another account signed in later in
 * the same browser. Being a request, it also renews the idle period, exactly like any other admin
 * request; nothing else (session lifetime, authentication, CSRF validation) changes.
 */
class SessionStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store, private'];
        $user = $request->user();

        if ($user === null) {
            return response()->json(['signedIn' => false], 401, $headers);
        }

        $expected = $request->query('for');

        if (! is_string($expected) || $expected === '' || ! hash_equals((string) $user->getAuthIdentifier(), $expected)) {
            return response()->json(['signedIn' => true, 'sameAccount' => false], 409, $headers);
        }

        return response()->json([
            'signedIn' => true,
            'sameAccount' => true,
            'token' => csrf_token(),
            'lifetime' => max(1, (int) config('session.lifetime')) * 60,
        ], 200, $headers);
    }
}
