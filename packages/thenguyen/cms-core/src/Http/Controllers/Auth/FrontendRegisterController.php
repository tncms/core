<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * Frontend registration (v1.0.0-beta.7.1.14). Honours the registration_enabled
 * setting, assigns the configured default role (never admin), and optionally
 * auto-logs-in unless email verification is required.
 */
class FrontendRegisterController
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function show(): View|RedirectResponse
    {
        if (! $this->auth->registrationEnabled()) {
            return redirect()->route('cms.auth.login')
                ->withErrors(['email' => __('Registration is currently disabled.')]);
        }

        return view('cms::auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        if (! $this->auth->registrationEnabled()) {
            abort(403, 'Registration is disabled.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $this->auth->register($data, $request);

        // Auto-login only when verification is not gating access.
        if ($this->auth->autoLoginAfterRegistration() && ! $this->auth->emailVerificationRequired()) {
            $this->auth->login($user, false, $request);

            return redirect()->intended($this->defaultRedirect());
        }

        if ($this->auth->emailVerificationRequired()) {
            return redirect()->route('cms.auth.login')
                ->with('status', __('Please check your email to verify your account.'));
        }

        return redirect()->route('cms.auth.login')
            ->with('status', __('Registration complete. You can now sign in.'));
    }

    private function defaultRedirect(): string
    {
        return \Illuminate\Support\Facades\Route::has('cms.account') ? route('cms.account') : '/';
    }
}
