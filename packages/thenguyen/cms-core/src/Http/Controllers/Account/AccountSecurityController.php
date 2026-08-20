<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use TheNguyen\CMS\Services\AccountManager;

/**
 * Account security page (v1.0.0-beta.7.1.15): password + email change. Both are
 * sensitive and require the current password before AccountManager runs the
 * change and rotates sessions.
 */
class AccountSecurityController
{
    public function __construct(private readonly AccountManager $account) {}

    public function edit(): View
    {
        return view('cms::account.security');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->assertCurrentPassword($user, $validated['current_password']);

        $this->account->updatePassword($user, $validated['password'], $request);

        return redirect()->route('cms.account.security')->with('status', __('Password updated.'));
    }

    public function updateEmail(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->getKey()),
            ],
        ]);

        $this->assertCurrentPassword($user, $validated['current_password']);

        $this->account->updateEmail($user, $validated['email'], $request);

        return redirect()->route('cms.account.security')->with('status', __('Email updated.'));
    }

    /** Fail closed if the confirming password is wrong. */
    private function assertCurrentPassword(User $user, ?string $current): void
    {
        if (! $this->account->validateCurrentPassword($user, $current)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password is incorrect.'),
            ]);
        }
    }
}
