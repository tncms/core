<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers\Account;

use Illuminate\Http\Response;
use TheNguyen\CMS\View\SystemViewResolver;

/**
 * Account dashboard (v1.0.0-beta.7.1.15). Thin: renders the shell + a simple
 * overview. Plugins extend it via the cms.account.dashboard.* hooks.
 */
class AccountController
{
    public function __construct(private readonly SystemViewResolver $systemView) {}

    public function dashboard(): Response
    {
        // Theme-first (theme::account.index) → Core fallback (cms::account.dashboard),
        // noindex enforced at the HTTP layer.
        return $this->systemView->renderNoindexResponse('account');
    }
}
