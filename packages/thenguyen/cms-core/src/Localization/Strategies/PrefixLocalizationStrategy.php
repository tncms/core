<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Strategies;

use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\LocalizedUrlGenerator;
use TheNguyen\CMS\Localization\RouteDescriptor;

/**
 * CORE-L10N.1B — the URL-prefix routing strategy.
 *
 * The public locale comes from the `{locale}` route segment (default locale served unprefixed
 * unless `language.prefix_default` is set). Localized URLs prepend the Core-configured prefix
 * to the descriptor's canonical path. Switching is a plain GET link. Stateless: prefixes and
 * the default come exclusively from {@see LanguageConfigurationContract}; no locale is
 * hardcoded.
 */
final class PrefixLocalizationStrategy implements LocalizationStrategyContract
{
    public const KEY = 'prefix';

    public function __construct(
        private readonly LanguageConfigurationContract $config,
        private readonly LocalizedUrlGenerator $generator,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function resolveLocale(Request $request): string
    {
        $segment = $request->route('locale');

        if (is_string($segment) && $segment !== '' && $this->config->isEnabled($segment)) {
            return $this->config->normalize($segment);
        }

        return $this->config->defaultLocale();
    }

    public function url(RouteDescriptor $descriptor, string $locale, Request $request): string
    {
        // Root-relative localized path: the Core prefix policy applied to the canonical path.
        // Byte-identical to the legacy localizedUrl() output. Consumers that need an absolute
        // URL (e.g. hreflang) absolutize via the generator.
        return $this->generator->localized($this->generator->path($descriptor), $this->config->normalize($locale));
    }

    public function switchMethod(): string
    {
        return 'get';
    }

    public function switchAction(string $locale, string $targetUrl, Request $request): ?array
    {
        // Prefix mode is a plain GET link; there is no persisted switch action.
        return null;
    }
}
