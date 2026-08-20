<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Admin\Actions\ClearLocaleValue;
use TheNguyen\CMS\Translation\Admin\Actions\CopyFromDefaultLocale;

/**
 * The single entry point for the Localized Admin layer (Phase 8.3).
 *
 * Aggregates the locale resolver, state hydrator, status/fallback resolvers, slug
 * generator, validation rules and the copy/clear operations, so the Filament
 * components (and modules) depend on ONE service rather than wiring each piece.
 * Holds no state itself; every collaborator is stateless and reusable.
 */
final class LocalizedAdminManager
{
    public function __construct(
        public readonly LocaleOptionsResolver $localeResolver,
        public readonly LocalizedStateHydrator $hydrator,
        public readonly TranslationStatusResolver $status,
        public readonly FallbackPreviewResolver $fallback,
        public readonly LocalizedSlugGenerator $slugs,
        public readonly LocalizedValidationRules $validation,
        public readonly CopyFromDefaultLocale $copy,
        public readonly ClearLocaleValue $clear,
    ) {
    }

    /** The current enabled admin locales (default first). */
    public function locales(): LocaleOptions
    {
        return $this->localeResolver->resolve();
    }

    /** The locale count above which the UI uses the overflow presentation. */
    public function overflowThreshold(): int
    {
        return $this->localeResolver->overflowThreshold();
    }
}
