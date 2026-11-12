<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — the ONE URL contract for Core's named
 * auth/account routes and their locale-prefixed twins.
 *
 * Core registers each auth/account route twice: the canonical unprefixed name
 * (e.g. `cms.account.profile`, `/account/profile`) and a `{locale}`-prefixed twin
 * suffixed `.localized` (`/en/account/profile`). This service picks the right one
 * for a locale from Core's own policy — never a hardcoded prefix:
 *
 *   - prefix strategy, non-default locale            → the `.localized` twin
 *   - prefix strategy, default locale                → unprefixed (canonical),
 *     unless `language.prefix_default` makes the prefixed form canonical
 *   - session strategy                               → always unprefixed (the
 *     session strategy never encodes the locale in the URL)
 *   - twin not registered (route cache, plugin name) → unprefixed
 *
 * The locale defaults to the request's current public locale (set by the
 * `cms.locale` middleware), so links, redirects and notification URLs built
 * during a `/en/...` request keep `/en/`.
 */
final class LocalizedRouteUrl
{
    public const SUFFIX = '.localized';

    public function __construct(private readonly LanguageManager $languages) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $name, array $parameters = [], ?string $locale = null, bool $absolute = true, ?UrlGenerator $urls = null): string
    {
        $code = $this->languages->normalizeCode($locale ?? $this->languages->currentCode());
        $twin = $name.self::SUFFIX;
        $urls ??= app('url');

        if ($this->prefixes($code) && Route::has($twin)) {
            return $urls->route($twin, ['locale' => $code] + $parameters, $absolute);
        }

        return $urls->route($name, $parameters, $absolute);
    }

    /**
     * Temporary signed URL of a Core route (or its `.localized` twin), e.g. the
     * email-verification link. The signature covers the localized path, so a
     * /en/ link stays valid and tampering (path, locale, expiry) is rejected.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function temporarySigned(string $name, \DateTimeInterface|\DateInterval|int $expiration, array $parameters = [], ?string $locale = null, ?UrlGenerator $urls = null): string
    {
        $code = $this->languages->normalizeCode($locale ?? $this->languages->currentCode());
        $twin = $name.self::SUFFIX;
        $urls ??= app('url');

        if ($this->prefixes($code) && Route::has($twin)) {
            return $urls->temporarySignedRoute($twin, $expiration, ['locale' => $code] + $parameters);
        }

        return $urls->temporarySignedRoute($name, $expiration, $parameters);
    }

    /** Whether a URL for this locale carries the locale segment under Core's policy. */
    public function prefixes(string $code): bool
    {
        if (! $this->languages->isActive($code) || ! $this->prefixStrategy()) {
            return false;
        }

        return $code !== $this->languages->defaultCode() || $this->languages->shouldPrefixDefaultLocale();
    }

    private function prefixStrategy(): bool
    {
        try {
            return app(LocalizationStrategyContract::class)->key() === Strategies\PrefixLocalizationStrategy::KEY;
        } catch (\Throwable) {
            // A misconfigured strategy fails closed to the canonical unprefixed URL.
            return false;
        }
    }
}
