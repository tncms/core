<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;
use TheNguyen\CMS\Services\FrontendAuthManager;
use TheNguyen\CMS\Validation\Rules\SafeEmailAddress;
use TheNguyen\CMS\View\SystemViewResolver;

/**
 * Frontend password reset (v1.0.0-beta.7.1.14). Uses the Laravel password
 * broker; after a successful reset FrontendAuthManager bumps the session
 * version and rotates the remember token so old sessions/cookies stop working.
 */
class FrontendPasswordResetController
{
    public function __construct(
        private readonly FrontendAuthManager $auth,
        private readonly SystemViewResolver $systemView,
    ) {}

    public function showForgot(): Response
    {
        // Theme-first (theme::auth.forgot-password) → Core fallback, noindex.
        return $this->systemView->renderNoindexResponse('forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', new SafeEmailAddress]]);

        // Whatever the broker reports (sent, unknown user, broker throttle), the
        // response is identical, so the form never reveals whether an account exists.
        //
        // CORE-RELEASE-BLOCKERS-R1 (P14-R2): the account-dependent work (token hash
        // and insert, mail transport) runs after the response is produced, so neither
        // its duration nor its failure can reveal an account. Under PHP-FPM / LiteSpeed
        // the response is flushed before it runs; elsewhere the broker's own timebox
        // still applies. A failure is reported, never shown.
        $credentials = ['email' => (string) $request->input('email')];
        defer(static function () use ($credentials): void {
            try {
                Password::sendResetLink($credentials);
            } catch (Throwable $e) {
                report($e);
            }
        });

        // CORE-P14-1: back to this locale's own form, never the (untrusted) Referer.
        return redirect()->to(cms_localized_route('password.request'))
            ->with('status', __('If an account exists for that email address, a password reset link has been sent.'));
    }

    public function showReset(Request $request): Response
    {
        // Read by name: under the /{locale} twin the first positional route
        // parameter is the locale, not the token.
        $token = (string) $request->route('token');

        // Theme-first (theme::auth.reset-password) → Core fallback, noindex.
        return $this->systemView->renderNoindexResponse('reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', new SafeEmailAddress],
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
            // CORE-P14-1: an unknown address reports the same error as a bad token,
            // so the reset form is not an account-existence oracle.
            $status = $status === Password::INVALID_USER ? Password::INVALID_TOKEN : $status;

            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->to(cms_localized_route('cms.auth.login'))->with('status', __($status));
    }
}
