<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * Frontend password reset (v1.0.0-beta.7.1.14). Uses the Laravel password
 * broker; after a successful reset FrontendAuthManager bumps the session
 * version and rotates the remember token so old sessions/cookies stop working.
 */
class FrontendPasswordResetController
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function showForgot(): View
    {
        return view('cms::auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return back()->with('status', __($status));
    }

    public function showReset(Request $request, string $token): View
    {
        return view('cms::auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                // Persist + invalidate other sessions/remember cookies.
                $this->auth->changePassword($user, $password, request());
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('cms.auth.login')->with('status', __($status));
    }
}
