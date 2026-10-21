<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use TheNguyen\CMS\Services\AccountManager;
use TheNguyen\CMS\View\SystemViewResolver;

/**
 * Account sessions page (v1.0.0-beta.7.1.15). Foundation-level only: shows the
 * current session's last-login facts and offers "log out other sessions". No
 * full device registry.
 */
class AccountSessionController
{
    public function __construct(
        private readonly AccountManager $account,
        private readonly SystemViewResolver $systemView,
    ) {}

    public function index(): Response
    {
        // Theme-first (theme::account.sessions) → Core fallback (cms::account.sessions),
        // noindex enforced at the HTTP layer (CORE-ACCOUNT-SESSIONS-VIEW-SEAM, P2).
        // Presentation-only seam: logoutOthers and all session security are unchanged.
        return $this->systemView->renderNoindexResponse('sessions');
    }

    public function logoutOthers(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Confirming the current password is recommended for this sensitive action.
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! $this->account->validateCurrentPassword($user, $validated['current_password'])) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password is incorrect.'),
            ]);
        }

        $this->account->invalidateOtherSessions($user, $request);

        return redirect()->route('cms.account.sessions')
            ->with('status', __('Other sessions have been logged out.'));
    }
}
