<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * CORE-L10N.1B — the concrete {@see LanguageConfigurationContract}, a thin read-only facade
 * over the existing {@see LanguageManager} and the `cms_settings` `language.*` keys.
 *
 * It caches NOTHING that could drift: every accessor reads live from the language authority,
 * so activating a language, changing the default, or flipping the prefix policy is reflected
 * immediately. It never reads plugin configuration.
 */
final class LanguageConfiguration implements LanguageConfigurationContract
{
    private const STRATEGY_SETTING = 'language.routing_strategy';

    private const DEFAULT_STRATEGY = 'prefix';

    public function __construct(private readonly LanguageManager $languages) {}

    public function multilingualEnabled(): bool
    {
        return count($this->enabledLocales()) > 1;
    }

    public function enabledLocales(): array
    {
        return $this->languages->getPublicLocales();
    }

    public function defaultLocale(): string
    {
        return $this->languages->defaultCode();
    }

    public function fallbackLocale(): string
    {
        // TN CMS uses the default locale as the deterministic fallback floor; there is no
        // separate fallback authority to read.
        return $this->languages->defaultCode();
    }

    public function isEnabled(string $code): bool
    {
        return $this->languages->isActive($code);
    }

    public function normalize(?string $code): string
    {
        return $this->languages->normalizeCode($code);
    }

    public function metadata(string $code): ?LocaleMetadata
    {
        $language = $this->languages->find($this->languages->normalizeCode($code));

        return $language !== null ? $this->toMetadata($language) : null;
    }

    public function allMetadata(): array
    {
        return $this->languages->active()
            ->map(fn (Language $language): LocaleMetadata => $this->toMetadata($language))
            ->all();
    }

    public function prefixFor(string $code): string
    {
        $code = $this->languages->normalizeCode($code);

        if ($code === $this->defaultLocale() && ! $this->prefixesDefaultLocale()) {
            return '';
        }

        return '/'.$code;
    }

    public function prefixesDefaultLocale(): bool
    {
        return $this->languages->shouldPrefixDefaultLocale();
    }

    public function routingStrategy(): string
    {
        $strategy = settings(self::STRATEGY_SETTING, self::DEFAULT_STRATEGY);
        $strategy = is_string($strategy) ? strtolower(trim($strategy)) : '';

        return $strategy !== '' ? $strategy : self::DEFAULT_STRATEGY;
    }

    private function toMetadata(Language $language): LocaleMetadata
    {
        return new LocaleMetadata(
            code: $language->code,
            name: (string) $language->name,
            nativeName: $language->displayName(),
            // Preserve the raw direction/flag verbatim so switcher output is byte-identical.
            direction: in_array($language->direction, ['ltr', 'rtl'], true) ? $language->direction : 'ltr',
            flag: $language->flag,
            isDefault: (bool) $language->is_default,
        );
    }
}
