<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Strategies;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\LocalizedUrlGenerator;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * CORE-L10N.1B — the Core-owned session compatibility strategy.
 *
 * The public locale comes from Core's {@see LocalePreferenceManager} (the `cms.frontend_locale`
 * session / `users.frontend_locale`), NOT a plugin store. Localized URLs keep the SAME path —
 * switching persists a locale and reloads the current route rather than changing the URL — so a
 * plugin's non-prefixed storefront routes are preserved. Switching is a POST to the Core locale
 * switch action (registered by the platform), CSRF-protected and open-redirect-safe. This is a
 * compatibility strategy; it is Core-owned, and no plugin knows session mechanics.
 */
final class SessionLocalizationStrategy implements LocalizationStrategyContract
{
    public const KEY = 'session';

    /** The Core-owned switch endpoint route name (registered by the platform). */
    public const SWITCH_ROUTE = 'cms.locale.switch';

    public function __construct(
        private readonly LanguageConfigurationContract $config,
        private readonly LocalizedUrlGenerator $generator,
        private readonly LocalePreferenceManager $preferences,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function resolveLocale(Request $request): string
    {
        // Session strategy has no URL locale segment; Core persistence + default decide.
        return $this->preferences->resolveFrontendLocale($request, null, $request->user());
    }

    public function url(RouteDescriptor $descriptor, string $locale, Request $request): string
    {
        // Session mode never changes the URL: the switch target is the current page
        // (root-relative, matching the switcher's link semantics).
        return $this->currentPath($request);
    }

    public function switchMethod(): string
    {
        return 'post';
    }

    public function switchAction(string $locale, string $targetUrl, Request $request): ?array
    {
        return [
            'action' => Route::has(self::SWITCH_ROUTE) ? route(self::SWITCH_ROUTE) : null,
            'fields' => [
                'locale' => $this->config->normalize($locale),
                'redirect' => $this->currentPath($request),
            ],
        ];
    }

    private function currentPath(Request $request): string
    {
        $uri = $request->getRequestUri();

        return $uri !== '' ? $uri : '/';
    }
}
