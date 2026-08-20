<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Contracts;

use TheNguyen\CMS\Models\TermTranslation;

/**
 * The write-adapter contract for taxonomy term translations (Phase 9.2B).
 *
 * A taxonomy write adapter persists ONE already-normalized translation row through
 * the taxonomy driver, JOINING the caller's open transaction. It replaces ONLY the
 * persistence tail of `TaxonomyManager::upsertTermTranslation` — the taxonomy analog
 * of the Posts/Pages write adapters.
 *
 * WRITE AUTHORITY BOUNDARY (invariant — see TAXONOMY-TRANSLATION-CONTRACT.md §Write):
 * `TaxonomyManager` (`cms.taxonomy`) remains the SOLE write authority. The payload
 * this adapter receives has ALREADY been normalized, HTML-sanitized, slug-generated
 * and uniqueness-checked by `TaxonomyManager`. The adapter therefore owns NOTHING but
 * translation-row persistence:
 *   - ❌ NO slug generation / reservation / cms_slugs (TaxonomyManager + SlugManager),
 *   - ❌ NO HTML sanitization (TaxonomyManager, before it is called),
 *   - ❌ NO cache invalidation, hooks/events (TaxonomyManager fires cms.term.*),
 *   - ❌ NO business validation / locale detection.
 *
 * TRANSACTION BOUNDARY: the adapter is invoked from inside `TaxonomyManager`'s
 * existing `DB::transaction` via a joining write context; it NEVER opens its own
 * transaction and MUST re-throw driver errors so the enclosing transaction rolls
 * back (no partial writes, no orphan rows). It re-reads and returns the persisted
 * `TermTranslation` so `TaxonomyManager`'s slug synchronization works unchanged.
 *
 * STABLE SDK contract.
 *
 * @since 1.0
 *
 * @stable
 */
interface TaxonomyTranslationWriteContract
{
    /** Active write mode: 'legacy' | 'adapter'. */
    public function mode(): string;

    /** Whether taxonomy translation writes should route through this adapter. */
    public function isActive(): bool;

    /**
     * Persist one already-normalized term translation (create or update) through the
     * driver, joining the caller's transaction, and return the hydrated row.
     *
     * @param  array<string, mixed>  $payload  the normalized+sanitized+slug-resolved fields
     * @param  TermTranslation|null  $existing  the current row for this locale, or null to create
     */
    public function persist(
        TaxonomyTranslationEntityInterface $entity,
        string $locale,
        array $payload,
        ?TermTranslation $existing
    ): TermTranslation;

    /** Delete a term's translation rows through the driver (one locale, or all). */
    public function delete(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): int;

    /**
     * Non-throwing diagnostics: module, mode, driver, entity type, joins_transaction,
     * counters, last_error.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array;
}
