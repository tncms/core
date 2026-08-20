<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * CORE-L10N A1 — the ONE Core locale-apply runtime.
 *
 * Establishes the public locale for the current request so every reader
 * ({@see \TheNguyen\CMS\Localization\PublicLocaleContext}, SeoManager,
 * language_switcher, resolvers) sees a single, Core-owned current locale — for
 * ANY route group, Core or plugin. A plugin attaches the "cms.locale" alias to
 * its route group instead of writing locale bootstrap logic; Core is the sole
 * locale runtime.
 *
 * Resolution delegates to the ACTIVE strategy (Core owns the strategy choice):
 * the prefix strategy reads the {locale} segment; the session strategy reads
 * Core's LocalePreferenceManager; both fall back to the default locale. An
 * explicit {locale} route parameter that is not an active language 404s —
 * matching the Core frontend's behaviour and guarding plugin routes that do not
 * constrain the segment themselves. It generates no URL, chooses no strategy,
 * and knows no plugin.
 */
final class ApplyPublicLocale
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly LocalePreferenceManager $preferences,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $explicit = $this->routeLocale($request);

        // An explicit, inactive locale segment is a 404 (never a silent fallback).
        if ($explicit !== null && ! $this->languages->isActive($this->languages->normalizeCode($explicit))) {
            abort(404);
        }

        $code = $this->languages->normalizeCode(
            app(LocalizationStrategyContract::class)->resolveLocale($request),
        );

        $this->languages->setCurrent($code);

        // Persist a reader's route-driven explicit choice; best-effort — a
        // preference write must never break the request.
        if ($explicit !== null) {
            try {
                $this->preferences->persistFrontendLocale($request, $request->user(), $code);
            } catch (\Throwable) {
                // Non-fatal.
            }
        }

        return $next($request);
    }

    /** The {locale} route parameter when present and non-empty, else null. */
    private function routeLocale(Request $request): ?string
    {
        $value = $request->route('locale');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
