<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use TheNguyen\CMS\Services\AccountManager;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Account profile page (v1.0.0-beta.7.1.15). Thin: validation + delegation to
 * AccountManager, which owns the write + hooks.
 */
class AccountProfileController
{
    public function __construct(private readonly AccountManager $account) {}

    public function edit(): View
    {
        return view('cms::account.profile');
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'nullable', 'string', 'alpha_dash', 'max:50',
                Rule::unique('users', 'username')->ignore($user->getKey()),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'avatar' => ['nullable', 'string', 'url', 'max:2048'],
        ]);

        $this->account->updateProfile($user, $data, $request);

        return $this->redirect($request, __('Profile updated.'), 'cms.account.profile');
    }

    private function redirect(Request $request, string $status, string $route): RedirectResponse
    {
        $target = apply_filters(
            'cms.account.redirect_after_update',
            route($route),
            HookContext::make(['request' => $request, 'section' => 'profile']),
        );

        return redirect()->to($target)->with('status', $status);
    }
}
