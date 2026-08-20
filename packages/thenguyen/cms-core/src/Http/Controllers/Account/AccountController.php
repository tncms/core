<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use Illuminate\View\View;

/**
 * Account dashboard (v1.0.0-beta.7.1.15). Thin: renders the shell + a simple
 * overview. Plugins extend it via the cms.account.dashboard.* hooks.
 */
class AccountController
{
    public function dashboard(): View
    {
        return view('cms::account.dashboard');
    }
}
