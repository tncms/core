<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use TheNguyen\CMS\Services\FrontendAuthManager;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Frontend login / logout (v1.0.0-beta.7.1.14). Thin: all session and device
 * hardening lives in FrontendAuthManager.
 */
class FrontendLoginController
{
    public function __construct(private readonly FrontendAuthManager $auth) {}

    public function show(): View
    {
        return view('cms::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $this->auth->attempt($credentials, $request->boolean('remember'), $request)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $target = apply_filters(
            'cms.auth.redirect_after_login',
            $this->defaultRedirect(),
            HookContext::make(['request' => $request, 'user' => $this->auth->guard()->user()]),
        );

        return redirect()->intended($target);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout($request);

        $target = apply_filters(
            'cms.auth.redirect_after_logout',
            '/',
            HookContext::make(['request' => $request]),
        );

        return redirect()->to($target);
    }

    private function defaultRedirect(): string
    {
        return \Illuminate\Support\Facades\Route::has('cms.account') ? route('cms.account') : '/';
    }
}
