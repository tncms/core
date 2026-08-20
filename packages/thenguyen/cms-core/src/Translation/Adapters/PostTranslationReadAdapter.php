<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Adapters;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationRecord;

/**
 * Read-only compatibility adapter that resolves a Post's localized fields through
 * the Translation Platform (the Phase 9.0B `content_relational` driver) while
 * reproducing the legacy read semantics **exactly** (Phase 9.0C).
 *
 * It is the module-specific compatibility layer of the driver stack (8.5D): it
 * knows Posts (Content type=post) and mirrors, field for field, how the CMS reads
 * them today —
 *
 *   - `title` / `slug`     → row-level first-available (requested locale, else the
 *                            first stored row), with the legacy `Content #id` /
 *                            empty-string defaults (mirrors Content::translatedTitle/
 *                            translatedSlug).
 *   - `localeSlug`         → strict requested locale, null when absent (mirrors
 *                            Content::localeSlug — identity/URL use).
 *   - `excerpt` / `content`
 *     / `seoTitle` / `seoDescription` → strict requested-locale row, nullable
 *                            (mirrors ContentManager::getTranslation + SeoManager).
 *
 * Eager loading is preserved: when a Content has its `translations` relation
 * loaded, the adapter reads that in-memory collection (zero extra queries, exactly
 * as legacy) and only falls back to the driver when it is not loaded.
 *
 * The adapter owns NO write path, NO slug generation, NO HTML sanitizing, NO cache
 * invalidation, and NO locale detection beyond the same LanguageManager authority
 * legacy uses. It is engaged only when `translation.modules.posts.driver` is
 * `read` (or `adapter`); with the default `legacy` it is dormant.
 */
final class PostTranslationReadAdapter
{
    private const MODULE = 'posts';

    /** The columns the adapter reads, mirroring cms_content_translations. */
    private const FIELDS = [
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    public function __construct(
        private readonly RelationalTranslationDriverInterface $driver,
        private readonly LanguageManager $language,
    ) {}

    // ── mode / activation ────────────────────────────────────────────────────────

    /** Active read mode from the per-module flag: 'legacy' | 'read' | 'adapter'. */
    public function mode(): string
    {
        // `read_driver` is canonical (Phase 9.0D); `driver` is accepted as a
        // back-compat alias (Phase 9.0C) when read_driver is not set.
        $mode = config('translation.modules.'.self::MODULE.'.read_driver')
            ?? config('translation.modules.'.self::MODULE.'.driver', 'legacy');

        $mode = (string) $mode;

        return in_array($mode, ['legacy', 'read', 'adapter'], true) ? $mode : 'legacy';
    }

    /** Whether Posts should be read through this adapter (flag is not 'legacy'). */
    public function isActive(): bool
    {
        return $this->mode() !== 'legacy';
    }

    // ── field reads (byte-identical to the legacy Content read methods) ──────────

    /** Row-level first-available title with the legacy `Content #id` placeholder. */
    public function title(Content $post, ?string $locale = null): string
    {
        $record = $this->firstAvailableRecord($post, $this->locale($locale));
        $title = $record?->field('title');

        return ($title !== null && $title !== '')
            ? (string) $title
            : 'Content #'.$post->getKey();
    }

    /** Row-level first-available slug with the legacy empty-string default. */
    public function slug(Content $post, ?string $locale = null): string
    {
        $record = $this->firstAvailableRecord($post, $this->locale($locale));

        return (string) ($record?->field('slug') ?? '');
    }

    /** Strict-locale slug (no fallback); null when that locale has no row/slug. */
    public function localeSlug(Content $post, string $locale): ?string
    {
        $record = $this->strictRecord($post, $locale);
        $slug = $record?->field('slug');

        return ($slug !== null && $slug !== '') ? (string) $slug : null;
    }

    /** Whether the post has a row for the exact locale (mirrors Content::hasTranslation). */
    public function hasTranslation(Content $post, string $locale): bool
    {
        if ($post->relationLoaded('translations')) {
            return $post->translations->contains('locale', $locale);
        }

        return $this->driver->exists($post->getKey(), $locale);
    }

    /** Strict-locale excerpt (nullable) — mirrors ContentManager::getTranslation. */
    public function excerpt(Content $post, ?string $locale = null): ?string
    {
        return $this->strictField($post, $this->locale($locale), 'excerpt');
    }

    /** Strict-locale content body (nullable). */
    public function content(Content $post, ?string $locale = null): ?string
    {
        return $this->strictField($post, $this->locale($locale), 'content');
    }

    /** Strict-locale SEO title (meta_title, nullable). */
    public function seoTitle(Content $post, ?string $locale = null): ?string
    {
        return $this->strictField($post, $this->locale($locale), 'meta_title');
    }

    /** Strict-locale SEO description (meta_description, nullable). */
    public function seoDescription(Content $post, ?string $locale = null): ?string
    {
        return $this->strictField($post, $this->locale($locale), 'meta_description');
    }

    /** All six deliverable fields resolved for one post + locale. */
    public function fields(Content $post, ?string $locale = null): PostLocalizedFields
    {
        return new PostLocalizedFields(
            $this->title($post, $locale),
            $this->slug($post, $locale),
            $this->excerpt($post, $locale),
            $this->content($post, $locale),
            $this->seoTitle($post, $locale),
            $this->seoDescription($post, $locale),
        );
    }

    // ── batch (no N+1) ───────────────────────────────────────────────────────────

    /**
     * Resolve fields for many post ids in ONE driver query — for callers holding
     * ids without loaded models. (The model-wired path already avoids N+1 by
     * reading the eager-loaded `translations` relation.)
     *
     * @param  array<int, int>  $postIds
     * @return array<int, PostLocalizedFields>
     */
    public function batchFields(array $postIds, ?string $locale = null): array
    {
        $loc = $this->locale($locale);
        $byId = $this->driver->batchRead($postIds); // one query

        $out = [];
        foreach ($postIds as $id) {
            $out[$id] = $this->composeFromMap((int) $id, $loc, $byId[$id] ?? []);
        }

        return $out;
    }

    // ── diagnostics ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $driverDiagnostics = $this->driver->diagnostics();

        return [
            'module' => self::MODULE,
            'mode' => $this->mode(),
            'active' => $this->isActive(),
            'driver' => $this->driver->name(),
            'adapter' => self::class,
            'fallback' => [
                'title' => 'first_available',
                'slug' => 'first_available',
                'locale_slug' => 'strict',
                'excerpt' => 'strict',
                'content' => 'strict',
                'seo_title' => 'strict',
                'seo_description' => 'strict',
            ],
            'query_count' => $driverDiagnostics['metrics']['queries'] ?? 0,
            'driver_diagnostics' => $driverDiagnostics,
        ];
    }

    // ── internals: record resolution mirroring the two legacy access patterns ────

    /**
     * The first-available record (requested locale, else the first stored row) —
     * mirrors Content::resolveTranslation. Reads the loaded relation when present.
     */
    private function firstAvailableRecord(Content $post, string $locale): ?TranslationRecord
    {
        if ($post->relationLoaded('translations')) {
            $translation = $post->translations->firstWhere('locale', $locale)
                ?? $post->translations->first();

            return $translation instanceof ContentTranslation ? $this->fromModel($translation) : null;
        }

        $record = $this->driver->read($post->getKey(), $locale);
        if ($record !== null) {
            return $record;
        }

        $all = $this->driver->readAllLocales($post->getKey());

        return $all === [] ? null : reset($all);
    }

    /**
     * The strict requested-locale record (no fallback) — mirrors
     * ContentManager::getTranslation. Reads the loaded relation when present.
     */
    private function strictRecord(Content $post, string $locale): ?TranslationRecord
    {
        if ($post->relationLoaded('translations')) {
            $translation = $post->translations->firstWhere('locale', $locale);

            return $translation instanceof ContentTranslation ? $this->fromModel($translation) : null;
        }

        return $this->driver->read($post->getKey(), $locale);
    }

    private function strictField(Content $post, string $locale, string $field): ?string
    {
        $value = $this->strictRecord($post, $locale)?->field($field);

        return $value === null ? null : (string) $value;
    }

    /**
     * Compose the six fields from a locale→record map (the batch path). First-
     * available for title/slug, strict for the rest — the same split as the
     * single-record methods.
     *
     * @param  array<string, TranslationRecord>  $map
     */
    private function composeFromMap(int $postId, string $locale, array $map): PostLocalizedFields
    {
        $firstAvailable = $map[$locale] ?? (reset($map) ?: null);
        $strict = $map[$locale] ?? null;

        $title = $firstAvailable?->field('title');
        $title = ($title !== null && $title !== '') ? (string) $title : 'Content #'.$postId;

        return new PostLocalizedFields(
            $title,
            (string) ($firstAvailable?->field('slug') ?? ''),
            $this->nullableString($strict?->field('excerpt')),
            $this->nullableString($strict?->field('content')),
            $this->nullableString($strict?->field('meta_title')),
            $this->nullableString($strict?->field('meta_description')),
        );
    }

    private function fromModel(ContentTranslation $translation): TranslationRecord
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $translation->{$field};
        }

        return new TranslationRecord($translation->content_id, $translation->locale, $fields);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function locale(?string $locale): string
    {
        // Mirror Content::resolveTranslation's default: the site default locale.
        return ($locale !== null && $locale !== '') ? $locale : $this->language->defaultCode();
    }
}
