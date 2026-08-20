<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use TheNguyen\CMS\Services\AccountManager;

/**
 * Account preferences page (v1.0.0-beta.7.1.15): the three independent locale
 * preferences (frontend/admin/editing) plus timezone. Each is validated and
 * saved independently.
 */
class AccountPreferenceController
{
    public function __construct(private readonly AccountManager $account) {}

    public function edit(): View
    {
        return view('cms::account.preferences');
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $activeLocales = $this->activeLocaleCodes();

        $validated = $request->validate([
            'frontend_locale' => ['nullable', 'string', Rule::in($activeLocales)],
            'admin_locale' => ['nullable', 'string', Rule::in($activeLocales)],
            'editing_locale' => ['nullable', 'string', Rule::in($activeLocales)],
            'timezone' => ['nullable', 'timezone'],
        ]);

        // Pass only submitted keys so each preference stays independent.
        $data = array_intersect_key(
            $validated,
            array_flip($request->keys()),
        );

        $this->account->updatePreferences($user, $data, $request);

        return redirect()->route('cms.account.preferences')->with('status', __('Preferences updated.'));
    }

    /**
     * Active locale codes a preference may point at. Falls back to the app
     * locale when no languages are configured, so the form never dead-ends.
     *
     * @return array<int, string>
     */
    private function activeLocaleCodes(): array
    {
        try {
            $codes = app('cms.language')->active()->pluck('code')->all();
        } catch (\Throwable) {
            $codes = [];
        }

        return $codes !== [] ? $codes : [app()->getLocale()];
    }
}
