<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * Frontend email verification (v1.0.0-beta.7.1.14). Uses Laravel's signed
 * verification URLs; only blocks protected routes (via cms.verified), never
 * registration itself.
 */
class FrontendEmailVerificationController
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function notice(): View|RedirectResponse
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->to('/');
        }

        return view('cms::auth.verify-email');
    }

    /** Verify via the signed /email/verify/{id}/{hash} link. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        /** @var User|null $user */
        $user = User::find($id);

        if ($user === null || ! hash_equals($hash, sha1((string) $user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('cms.auth.login')->with('status', __('Email already verified.'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('cms.auth.login')->with('status', __('Your email has been verified.'));
    }

    /** Resend the verification email to the authenticated user. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('Verification link sent.'));
    }
}
