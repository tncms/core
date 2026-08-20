<?php

declare(strict_types=1);

namespace App\Search\Contracts;

/**
 * Declares WHAT a single searchable entity type is, and HOW to present a hit for
 * it — one definition per entity type (Phase 3.1.6N-B foundation).
 *
 * A definition is metadata only. It never runs a query, renders a view, or holds
 * request state; the owning {@see SearchProvider} performs retrieval and the
 * existing entity repository / render pipeline turns a {@see \App\Search\SearchResult}
 * reference back into a page. This mirrors the Knowledge Library `SearchableRegistry`
 * spec ({type, model, title, canonical, status}) but as an explicit, plugin-agnostic
 * contract so Products, Posts, Pages, Books, Knowledge and Documentation can all
 * register through the same shared platform.
 */
interface SearchableEntityDefinition
{
    /**
     * Stable, globally-unique type key (lower-kebab/snake, e.g. "product",
     * "post", "book"). Must match `^[a-z][a-z0-9_-]*$`. Two providers may not
     * claim the same type — the registry rejects collisions.
     */
    public function type(): string;

    /** Human-facing label for the search-scope dropdown (e.g. "Product"). */
    public function label(): string;

    /**
     * The Eloquent model class this type resolves to (the "model resolver"). The
     * provider loads and the render pipeline hydrates through this class; results
     * carry references, never the model itself.
     *
     * @return class-string
     */
    public function modelClass(): string;

    /**
     * Text columns eligible for keyword matching. Localized/editorial fields only
     * (name, title, excerpt, description, seo_*). Commercial or internal columns
     * (sku, price, cost, stock, ids) must NEVER appear here.
     *
     * @return list<string>
     */
    public function searchableFields(): array;

    /**
     * True when this type has per-locale content and the provider honours the
     * query locale (canonical + published translations). False for entities that
     * are single-locale today (e.g. brands, categories in Phase 3.1.6).
     */
    public function supportsLocales(): bool;

    /**
     * Declarative visibility guarantees the owning provider enforces at query
     * time (e.g. "published", "public", "non-deleted"). The registry requires a
     * non-empty set so every searchable type states, in the contract itself, that
     * it never leaks drafts, private data, or unpublished translations.
     *
     * @return list<string>
     */
    public function visibilityRules(): array;

    /**
     * Localized display title for a hit. Already-localized so views render it
     * directly without re-reading the model or the translation overlay.
     */
    public function resolveTitle(object $entity, string $locale): string;

    /** Canonical public URL for a hit in the given locale, or null when none. */
    public function resolveUrl(object $entity, string $locale): ?string;
}
