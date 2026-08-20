<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Adapters;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationRecord;
use TheNguyen\CMS\Translation\DTOs\WriteContext;
use TheNguyen\CMS\Translation\Exceptions\TranslationWriteException;
use Throwable;

/**
 * WRITE compatibility adapter for Pages (Phase 9.1D) — the Pages analog of
 * {@see PostTranslationWriteAdapter}.
 *
 * It performs exactly ONE thing: persist a page's translation row (create or
 * update) through the Phase 9.0B `content_relational` driver, JOINING the
 * caller's open transaction. It is invoked from inside `ContentManager`'s
 * existing `DB::transaction`, replacing ONLY the persistence tail of
 * `upsertTranslation` — the payload it receives has ALREADY been slug-generated,
 * uniqueness-checked, HTML-sanitized and field-normalized by ContentManager.
 *
 * Pages are the same Content model (type=page), the same `cms_content_translations`
 * store and the same `content_relational` driver as Posts; they differ only by
 * `content.type` and by their own feature flag. This adapter therefore owns NO
 * business behaviour:
 *   - no slug generation / uniqueness / cms_slugs mirror (ContentManager),
 *   - no HTML sanitization (ContentManager, before it is called),
 *   - no cache invalidation (the parent Content save + existing listeners),
 *   - no business events / hooks (ContentManager fires cms.content.*),
 *   - no fallback / locale detection.
 *
 * Engaged only when `translation.modules.pages.write_driver = adapter`; with the
 * default `legacy` it is dormant and ContentManager keeps its Eloquent write.
 */
final class PageTranslationWriteAdapter
{
    private const MODULE = 'pages';

    /** The translatable columns the adapter forwards (mirrors cms_content_translations). */
    private const FIELDS = [
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

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

    /** Whether Page translation writes should route through this adapter. */
    public function isActive(): bool
    {
        return $this->mode() === 'adapter';
    }

    // ── persistence (create / update) ────────────────────────────────────────────

    /**
     * Persist one already-normalized page translation through the driver, joining
     * the caller's transaction, and return the hydrated ContentTranslation so the
     * caller's slug synchronization works unchanged.
     *
     * @param  array<string, mixed>  $payload  ContentManager's normalized+sanitized payload
     */
    public function persist(Content $content, string $locale, array $payload, ?ContentTranslation $existing): ContentTranslation
    {
        $record = new TranslationRecord($content->getKey(), $locale, $this->fieldsFrom($payload));
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
            // Never swallow — let the enclosing transaction roll back.
            $this->lastError = $e->getMessage();
            throw $e;
        }

        // Re-read within the same transaction so the caller gets a DB-accurate
        // model (id + persisted values) for upsertSlug — exactly what the legacy
        // Eloquent create/save returned.
        $model = $content->translations()->where('locale', $locale)->first();

        if ($model === null) {
            throw new TranslationWriteException(
                'Page translation was not persisted for locale "'.$locale.'".'
            );
        }

        return $model;
    }

    /**
     * Delete a page's translation rows through the driver (one locale, or all).
     *
     * NOT wired into ContentManager::delete in Phase 9.1D — see the delete-flow
     * decision in the report (Page deletion stays on the legacy soft-delete path,
     * mirroring Posts 9.0D). Provided and tested for completeness and future use.
     * Joins the caller's transaction; returns rows removed.
     */
    public function delete(Content $content, ?string $locale = null): int
    {
        try {
            $this->deletes++;

            return $this->driver->delete($content->getKey(), $locale, WriteContext::joining());
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
        return [
            'module' => self::MODULE,
            'mode' => $this->mode(),
            'active' => $this->isActive(),
            'driver' => $this->driver->name(),
            'driver_version' => $this->driver->version(),
            'entity' => 'page',
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fieldsFrom(array $payload): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $fields[$field] = $payload[$field];
            }
        }

        return $fields;
    }
}
