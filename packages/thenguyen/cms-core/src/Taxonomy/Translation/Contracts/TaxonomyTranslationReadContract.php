<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Contracts;

/**
 * The read-adapter contract for taxonomy term translations (Phase 9.2B).
 *
 * A taxonomy read adapter reproduces the entity's EXISTING legacy read semantics
 * (today: `Term::displayName/translatedSlug/localeSlug/localeName/translatedDescription`)
 * through the taxonomy driver, so activating it changes nothing observable. It is
 * the taxonomy analog of the Posts/Pages read adapters, field-mapped to the term
 * columns (name/slug/description/SEO).
 *
 * Fallback strategy per field (mirrors the legacy Term accessors — MUST be preserved):
 *   - name / slug / description → first-available (requested locale, else the first
 *     stored row); name uses the legacy `Term #id` placeholder, slug the '' default.
 *   - localeName / localeSlug      → strict requested locale, null when absent
 *     (identity / URL use).
 *   - seoTitle / seoDescription    → strict requested locale, nullable.
 *
 * The adapter owns NO write path, NO slug generation, NO sanitization, NO cache
 * invalidation, and NO locale detection beyond the same LanguageManager authority
 * legacy uses. It is engaged only when the taxonomy read flag leaves 'legacy'.
 *
 * STABLE SDK contract.
 *
 * @since 1.0
 *
 * @stable
 */
interface TaxonomyTranslationReadContract
{
    /** Active read mode: 'legacy' | 'read' | 'adapter' (any non-legacy = active). */
    public function mode(): string;

    /** Whether taxonomy reads should route through this adapter. */
    public function isActive(): bool;

    /** First-available localized name, with the legacy `Term #id` placeholder. */
    public function name(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): string;

    /** First-available localized slug, with the legacy '' default. */
    public function slug(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): string;

    /** Strict-locale name (no fallback); null when that locale has no row. */
    public function localeName(TaxonomyTranslationEntityInterface $entity, string $locale): ?string;

    /** Strict-locale slug (no fallback); null when that locale has no row/slug. */
    public function localeSlug(TaxonomyTranslationEntityInterface $entity, string $locale): ?string;

    /** First-available localized description (nullable). */
    public function description(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string;

    /** Strict-locale SEO title (meta_title, nullable). */
    public function seoTitle(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string;

    /** Strict-locale SEO description (meta_description, nullable). */
    public function seoDescription(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): ?string;

    /** Whether the entity has a row for the exact locale. */
    public function hasTranslation(TaxonomyTranslationEntityInterface $entity, string $locale): bool;

    /**
     * Non-throwing diagnostics: module, entity type, mode, active, driver, and the
     * per-field fallback strategy map.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array;
}
