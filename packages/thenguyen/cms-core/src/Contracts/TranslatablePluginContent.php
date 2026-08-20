<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

/**
 * Generic translatable plugin content (Phase 1C).
 *
 * A reusable contract for plugin models whose rows are per-locale variants
 * linked together by a shared "translation group" key. This deliberately mirrors
 * the conventions of the core Content/Term translation system (one logical piece
 * of content, many localized variants, resolved via the LanguageManager) without
 * forcing plugins into the core's parent + child-table schema: a plugin only adds
 * a nullable `locale` and `translation_group` column and uses HasPluginTranslations.
 *
 * @see \TheNguyen\CMS\Translation\HasPluginTranslations
 */
interface TranslatablePluginContent
{
    /** The column holding this record's locale code (default: "locale"). */
    public function getLocaleColumn(): string;

    /** The column linking sibling translations together (default: "translation_group"). */
    public function getTranslationGroupColumn(): string;

    /** This record's locale code, or null when it is locale-agnostic. */
    public function getLocale(): ?string;

    /** This record's translation group key, or null when it is not linked yet. */
    public function translationGroup(): ?string;

    /** The sibling translation for the given locale, or null when none exists. */
    public function translationFor(string $locale): ?self;

    /** Whether a sibling translation exists for the given locale. */
    public function hasTranslation(string $locale): bool;

    /**
     * The locale codes available within this record's translation group.
     *
     * @return array<int, string>
     */
    public function availableTranslationLocales(): array;

    /**
     * Create (and persist) a sibling translation for $locale in the same group,
     * returning the new record. Existing siblings are not duplicated by callers.
     */
    public function createTranslationFor(string $locale): self;
}
