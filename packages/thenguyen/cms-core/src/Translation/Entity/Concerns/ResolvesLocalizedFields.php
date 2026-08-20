<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Concerns;

use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityResolverInterface;

/**
 * Read/resolve helpers for a localized entity (Phase 8.2).
 *
 * The complement to {@see HasLocalizedFields}: resolves a field for a locale
 * through the engine (fallback chain + cache aware). The using class must be a
 * {@see \TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface}
 * (which {@see HasLocalizedFields} provides), so `$this` is a valid entity.
 */
trait ResolvesLocalizedFields
{
    /** The resolved string for a field in $locale (null → current locale). */
    public function localizedValue(string $field, ?string $locale = null): ?string
    {
        return $this->localizedEntityResolver()->value($this, $field, $locale);
    }

    /** The full resolution result for a field (value + locale + stage + cache). */
    public function resolveLocalized(string $field, ?TranslationContext $context = null): TranslationResult
    {
        return $this->localizedEntityResolver()->resolve($this, $field, $context);
    }

    protected function localizedEntityResolver(): LocalizedEntityResolverInterface
    {
        return app('cms.translation.entity.resolver');
    }
}
