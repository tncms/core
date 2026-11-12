<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * Frontend email verification (v1.0.0-beta.7.1.14). Uses Laravel's signed
 * verification URLs; only blocks protected routes (via cms.verified), never
 * registration itself.
 */
class FrontendEmailVerificationController
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function notice(): Response|RedirectResponse
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof User && $user->hasVerifiedEmail()) {
            return redirect()->to('/');
        }

        // Theme-first (theme::auth.verify-email) → Core fallback, noindex.
        return app('cms.system_view')->renderNoindexResponse('verify-email');
    }

    /** Verify via the signed /email/verify/{id}/{hash} link. */
    public function verify(Request $request): RedirectResponse
    {
        // Read by name: under the /{locale} twin the first positional route
        // parameter is the locale.
        $id = (string) $request->route('id');
        $hash = (string) $request->route('hash');

        /** @var User|null $user */
        $user = User::find($id);

        if ($user === null || ! hash_equals($hash, sha1((string) $user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->to(cms_localized_route('cms.auth.login'))->with('status', __('Email already verified.'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->to(cms_localized_route('cms.auth.login'))->with('status', __('Your email has been verified.'));
    }

    /** Resend the verification email to the authenticated user. */
    public function resend(Request $request): RedirectResponse
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        // CORE-P14-1: back to this locale's notice, never the (untrusted) Referer.
        return redirect()->to(cms_localized_route('cms.auth.verification.notice'))->with('status', __('Verification link sent.'));
    }
}
