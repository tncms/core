<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\LocaleTransition;

/**
 * CORE-L10N A2 — the canonical public locale-switch endpoint (cms.locale.switch).
 *
 * A thin transport adapter over the ONE {@see LocaleTransition} runtime: it holds
 * no validation, persistence, or redirect logic of its own — it delegates the
 * whole transition and redirects to the runtime's safe target. Plugins and themes
 * POST a locale change here (never implementing switching themselves).
 */
final class LocaleSwitchController
{
    public function __construct(private readonly LocaleTransition $transition) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $result = $this->transition->switch($request);

        return redirect()->to($result->redirectTarget);
    }
}
