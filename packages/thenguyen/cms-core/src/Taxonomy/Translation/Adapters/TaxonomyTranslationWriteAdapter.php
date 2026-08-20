<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Adapters;

use TheNguyen\CMS\Models\TermTranslation;
use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationEntityInterface;
use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationWriteContract;
use TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields;
use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationRecord;
use TheNguyen\CMS\Translation\DTOs\WriteContext;
use TheNguyen\CMS\Translation\Exceptions\TranslationWriteException;
use Throwable;

/**
 * WRITE compatibility adapter for taxonomy term translations (Phase 9.2E) — the
 * taxonomy analog of the Posts (9.0D) / Pages (9.1D) write adapters.
 *
 * It performs exactly ONE thing: persist a term's translation row (create or
 * update) through the Phase 9.2C `term_relational` driver, JOINING the caller's
 * open transaction. It is invoked from inside `TaxonomyManager`'s existing
 * `DB::transaction`, replacing ONLY the persistence tail of
 * `TaxonomyManager::upsertTermTranslation` — the payload it receives has ALREADY
 * been normalized, HTML-sanitized, slug-generated and uniqueness-checked by
 * `TaxonomyManager`.
 *
 * ONE ADAPTER, EVERY TAXONOMY. It operates purely over
 * {@see TaxonomyTranslationEntityInterface} and the shared `cms_term_translations`
 * store, so ONE instance serves Category, Tag, Brand, Genre, Knowledge Category,
 * Product Category and every plugin taxonomy. Per the phase Core Rule it NEVER
 * branches on a concrete taxonomy type — it keys everything on the term's
 * translation key.
 *
 * WRITE AUTHORITY BOUNDARY (invariant): `TaxonomyManager` (`cms.taxonomy`) remains
 * the SOLE write authority. This adapter owns NOTHING but translation-row
 * persistence:
 *   - ❌ NO slug generation / reservation / cms_slugs (TaxonomyManager + SlugManager),
 *   - ❌ NO cms_terms / parent_id / hierarchy / ordering / count (TaxonomyManager),
 *   - ❌ NO HTML sanitization (TaxonomyManager, before it is called),
 *   - ❌ NO cache invalidation, hooks/events, business validation, locale detection.
 *
 * Engaged only when `translation.modules.terms.write_driver = adapter`; with the
 * default `legacy` it is dormant and TaxonomyManager keeps its Eloquent write.
 */
final class TaxonomyTranslationWriteAdapter implements TaxonomyTranslationWriteContract
{
    /** The per-module adoption flag namespace (shared with the read adapter). */
    public const MODULE = 'terms';

    private int $creates = 0;

    private int $updates = 0;

    private int $deletes = 0;

    private ?string $lastError = null;

    public function __construct(
        private readonly RelationalTranslationDriverInterface $driver,
    ) {}

    // ── mode / activation ────────────────────────────────────────────────────────

    /** Active write mode from the per-module flag: 'legacy' | 'adapter'. */
    public function mode(): string
    {
        $mode = (string) config('translation.modules.'.self::MODULE.'.write_driver', 'legacy');

        return in_array($mode, ['legacy', 'adapter'], true) ? $mode : 'legacy';
    }

    /** Whether taxonomy translation writes should route through this adapter. */
    public function isActive(): bool
    {
        return $this->mode() === 'adapter';
    }

    // ── persistence (create / update) ────────────────────────────────────────────

    /**
     * Persist one already-normalized term translation through the driver, joining
     * the caller's transaction, and return the hydrated TermTranslation so the
     * caller's slug synchronization (`upsertTermSlug`) works unchanged.
     *
     * @param  array<string, mixed>  $payload  TaxonomyManager's normalized+sanitized+slug-resolved payload
     */
    public function persist(
        TaxonomyTranslationEntityInterface $entity,
        string $locale,
        array $payload,
        ?TermTranslation $existing
    ): TermTranslation {
        $key = $entity->getTranslationKey();
        $record = new TranslationRecord($key, $locale, $this->fieldsFrom($payload));
        $context = WriteContext::joining();

        try {
            if ($existing !== null) {
                $this->updates++;
                $this->driver->update($record, $context);
            } else {
                $this->creates++;
                $this->driver->create($record, $context);
            }
        } catch (Throwable $e) {
            // Never swallow — let the enclosing TaxonomyManager transaction roll back.
            $this->lastError = $e->getMessage();
            throw $e;
        }

        // Re-read within the same transaction so the caller gets a DB-accurate
        // model (id + persisted slug/locale) for upsertTermSlug — exactly what the
        // legacy Eloquent create/save returned. Keyed on the shared store, never
        // on a concrete taxonomy relation.
        $model = TermTranslation::query()
            ->where('term_id', $key)
            ->where('locale', $locale)
            ->first();

        if ($model === null) {
            throw new TranslationWriteException(
                'Term translation was not persisted for locale "'.$locale.'".'
            );
        }

        return $model;
    }

    /**
     * Delete a term's translation rows through the driver (one locale, or all).
     *
     * NOT wired into TaxonomyManager::deleteTerm in Phase 9.2E — see the delete-flow
     * decision in the report (term deletion stays on the legacy soft-delete path,
     * mirroring Posts 9.0D / Pages 9.1D: translation rows survive the soft delete
     * and slugs are removed by TaxonomyManager). Provided and tested for
     * completeness and future use. Joins the caller's transaction; returns rows
     * removed.
     */
    public function delete(TaxonomyTranslationEntityInterface $entity, ?string $locale = null): int
    {
        try {
            $this->deletes++;

            return $this->driver->delete($entity->getTranslationKey(), $locale, WriteContext::joining());
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    // ── diagnostics (never throws) ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $driver = 'unknown';
        $driverVersion = 'unknown';
        try {
            $driver = $this->driver->name();
            $driverVersion = $this->driver->version();
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
        }

        return [
            'module' => self::MODULE,
            'mode' => $this->mode(),
            'active' => $this->isActive(),
            'driver' => $driver,
            'driver_version' => $driverVersion,
            'entity' => 'term',
            'joins_transaction' => true,
            'counters' => [
                'creates' => $this->creates,
                'updates' => $this->updates,
                'deletes' => $this->deletes,
            ],
            'last_error' => $this->lastError,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────────

    /**
     * Project the caller payload onto the canonical taxonomy driver field set
     * (name/slug/description/meta_title/meta_description). The `locale` key and any
     * non-translatable extras are dropped — the driver forwards ONLY declared
     * columns and an absent field is left untouched (never nulled).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fieldsFrom(array $payload): array
    {
        $fields = [];
        foreach (TaxonomyTranslationFields::driverFields() as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[$field] = $payload[$field];
            }
        }

        return $fields;
    }
}
