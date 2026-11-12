<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

/**
 * Immutable, public-safe view of one taxonomy term for one requested locale. It carries only
 * facts a public visitor can already see (names, slugs, archive paths, hierarchy) — never counts,
 * robots policy, descriptions, translation row ids or table names. Identity is `id`; labels and
 * slugs are display data and must never be persisted in place of the id.
 */
final class TermSummary
{
    public function __construct(
        /** Stable Core term id (cms_terms.id). */
        public readonly int $id,
        /** Taxonomy key: 'category' | 'tag'. */
        public readonly string $taxonomy,
        /** Label for the requested locale, or the documented fallback (see TaxonomyDirectory). Plain text, unescaped. */
        public readonly string $label,
        /** Locale the label came from, or null for the "Term #id" placeholder. */
        public readonly ?string $labelLocale,
        /** True when the label is not from the requested locale. */
        public readonly bool $labelIsFallback,
        /** True when the term has a non-empty name in the requested locale. */
        public readonly bool $hasTranslation,
        /** Slug in the requested locale (no fallback), or null. */
        public readonly ?string $slug,
        /** Root-relative canonical archive path in the requested locale (LocalizedContentUrlService), or null. */
        public readonly ?string $url,
        /** Parent term id for hierarchical taxonomies, else null. */
        public readonly ?int $parentId,
    ) {}

    /** @return array{id: int, taxonomy: string, label: string, labelLocale: ?string, labelIsFallback: bool, hasTranslation: bool, slug: ?string, url: ?string, parentId: ?int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'taxonomy' => $this->taxonomy,
            'label' => $this->label,
            'labelLocale' => $this->labelLocale,
            'labelIsFallback' => $this->labelIsFallback,
            'hasTranslation' => $this->hasTranslation,
            'slug' => $this->slug,
            'url' => $this->url,
            'parentId' => $this->parentId,
        ];
    }
}
