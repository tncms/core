<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\Events\LocaleChanged;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * CORE-L10N A2 — the ONE canonical locale transition runtime.
 *
 * It is the single authority for changing the public locale. Given a request it
 * validates the requested locale (fail-closed: empty/inactive is rejected with
 * no state change), resolves a safe redirect target, persists the preference
 * through the ONE {@see LocalePreferenceManager}, refreshes the current-locale
 * authority, emits the canonical {@see LocaleChanged} platform event, and
 * reports a diagnostics {@see LocaleTransitionResult}. It performs the redirect
 * nowhere itself (the controller does) and knows no plugin, storage, SEO, or
 * translation detail — a plugin only *requests* a change and never implements
 * transition logic.
 */
final class LocaleTransition
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly LocalePreferenceManager $preferences,
        private readonly SafeInternalRedirect $redirect,
        private readonly Dispatcher $events,
    ) {}

    public function switch(Request $request): LocaleTransitionResult
    {
        $previous = $this->languages->currentCode();
        $requested = (string) $request->input('locale', '');
        $redirectTarget = $this->redirect->resolve($request->input('redirect'));

        $normalized = $this->languages->normalizeCode($requested);

        // Fail closed: an empty / inactive / unsupported locale changes nothing.
        if ($requested === '' || ! $this->languages->isActive($normalized)) {
            return LocaleTransitionResult::rejected($previous, $requested, $redirectTarget);
        }

        // A valid locale equal to the current one is persisted (idempotent) but
        // emits no change event.
        if ($normalized === $previous) {
            $this->preferences->persistFrontendLocale($request, $request->user(), $normalized);

            return LocaleTransitionResult::unchanged($previous, $requested, $normalized, $redirectTarget);
        }

        // A valid, different locale: persist → refresh current authority → emit.
        $this->preferences->persistFrontendLocale($request, $request->user(), $normalized);
        $this->languages->setCurrent($normalized);
        $this->events->dispatch(new LocaleChanged($previous, $normalized));

        return LocaleTransitionResult::changed($previous, $requested, $normalized, $redirectTarget);
    }
}
