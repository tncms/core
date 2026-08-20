<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;

class SlugManager
{
    /**
     * Generate a slug from arbitrary text.
     *
     * Vietnamese: accents removed, đ/Đ → d/D, then Str::slug.
     * Latin: Str::slug default behavior.
     * CJK / Arabic / Thai etc.: characters preserved, whitespace → '-',
     *   unsafe URL punctuation stripped.
     */
    public function generate(string $text, string $locale = 'vi'): string
    {
        $text = trim($text);

        if ($text === '') {
            return $this->fallback('content');
        }

        if ($this->isUnicodeScriptLocale($locale, $text)) {
            return $this->generateUnicodeSafeSlug($text);
        }

        if ($this->shouldTransliterateVietnamese($locale, $text)) {
            $text = strtr($text, $this->vietnameseMap());
        }

        $slug = Str::slug($text, '-');

        return $slug !== '' ? $slug : $this->generateUnicodeSafeSlug($text);
    }

    public function uniqueContentSlug(
        string $baseSlug,
        string $locale,
        ?int $ignoreTranslationId = null,
    ): string {
        $base = $baseSlug !== '' ? $baseSlug : $this->fallback('content');
        $candidate = $base;
        $i = 2;

        while ($this->contentSlugExists($candidate, $locale, $ignoreTranslationId)) {
            $candidate = $base . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    public function uniqueTermSlug(
        string $baseSlug,
        string $locale,
        ?int $ignoreTranslationId = null,
    ): string {
        $base = $baseSlug !== '' ? $baseSlug : $this->fallback('term');
        $candidate = $base;
        $i = 2;

        while ($this->termSlugExists($candidate, $locale, $ignoreTranslationId)) {
            $candidate = $base . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    /**
     * Reserve a globally-unique public slug for a record (WordPress-style).
     *
     * Within a locale a public slug must be unique across ALL public records
     * (pages, posts, categories, tags) — the cms_slugs table is the source of
     * truth. Reserved prefixes (admin, livewire, …) are never returned as a
     * bare slug. A conflicting or reserved slug is auto-incremented (slug-2,
     * slug-3, …), so manually entering a taken slug silently adjusts.
     *
     * @param  string       $referenceType  'content' | 'term' (the owning kind)
     * @param  int|null     $referenceId    the owning record id, ignored when matching
     */
    public function uniquePublicSlug(
        string $slug,
        string $locale,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): string {
        $base = $this->generate($slug, $locale);

        if ($base === '') {
            $base = $this->fallback('content');
        }

        // Before the cms_slugs table exists (fresh install / mid-migration) we
        // cannot check global uniqueness — return the normalised base.
        if (! Schema::hasTable('cms_slugs')) {
            return $this->isReservedSlug($base) ? $base . '-2' : $base;
        }

        $candidate = $base;
        $i = 2;

        while ($this->publicSlugTaken($candidate, $locale, $referenceType, $referenceId)
            || $this->isReservedSlug($candidate)) {
            $candidate = $base . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    /**
     * Resolve a public path segment to its canonical cms_slugs row for the
     * given locale, or null. Matches on full_path so prefixed records (e.g.
     * blog/post-slug) are never reached by a bare single-segment lookup.
     * Safe before the table exists.
     */
    public function findPublic(string $slug, string $locale): ?Slug
    {
        if (! Schema::hasTable('cms_slugs')) {
            return null;
        }

        $path = ltrim(trim($slug), '/');

        return Slug::query()
            ->where('locale', $locale)
            ->where('full_path', $path)
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * Recompute every cms_slugs prefix + full_path from the current permalink
     * bases. Called when a base setting changes so existing content resolves at
     * the new URLs without re-saving each record. The bare `slug` column is
     * untouched (slug uniqueness is unaffected by base changes). Returns the
     * number of rows updated. Safe before the tables exist.
     */
    public function rebuildPublicSlugs(): int
    {
        if (! Schema::hasTable('cms_slugs')) {
            return 0;
        }

        $permalink = app('cms.permalink');
        $updated = 0;

        Slug::query()->where('reference_type', 'content')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($permalink, &$updated): void {
                foreach ($rows as $row) {
                    $content = Content::query()->find($row->reference_id);

                    if ($content === null) {
                        continue;
                    }

                    $updated += $this->applyPrefix($row, $permalink->contentBase($content->type));
                }
            });

        Slug::query()->where('reference_type', 'term')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($permalink, &$updated): void {
                foreach ($rows as $row) {
                    $term = Term::query()->with('taxonomy')->find($row->reference_id);

                    if ($term === null) {
                        continue;
                    }

                    $type = $term->taxonomy?->type;
                    $prefix = match ($type) {
                        'category', 'tag' => $permalink->termBase($type),
                        default => $term->taxonomy?->slug,
                    };

                    $updated += $this->applyPrefix($row, $prefix);
                }
            });

        return $updated;
    }

    /**
     * Rebuild EVERY public slug row in cms_slugs from the translation tables —
     * cms_content_translations.slug and cms_term_translations.slug, the per-locale
     * source of truth — recomputing the prefix/full_path from the current
     * permalink bases. One cms_slugs row per (reference, locale) is upserted; the
     * bare translation slug is preserved verbatim (no uniqueness re-resolution),
     * so this is idempotent and never rewrites a translation's slug.
     *
     * This is the repair/backfill entry point behind `tncms:slugs:rebuild`: it
     * recreates missing or stale public-URL rows without re-saving each record.
     * Safe before the tables exist. Returns the number of rows written.
     */
    public function rebuildAllPublicSlugs(): int
    {
        if (! Schema::hasTable('cms_slugs')
            || ! Schema::hasTable('cms_content_translations')
            || ! Schema::hasTable('cms_term_translations')) {
            return 0;
        }

        $permalink = app('cms.permalink');
        $written = 0;

        // Iterate the parent records (not the translations): ContentTranslation
        // has a `content` COLUMN (the body) that shadows any `content` relation,
        // so the parent must be reached from the Content side.
        Content::query()
            ->with('translations')
            ->orderBy('id')
            ->chunkById(200, function ($contents) use ($permalink, &$written): void {
                foreach ($contents as $content) {
                    $prefix = $permalink->contentBase($content->type);

                    foreach ($content->translations as $translation) {
                        if ($translation->slug === null || $translation->slug === '') {
                            continue;
                        }

                        $written += $this->writePublicSlug(
                            'content',
                            (int) $content->id,
                            (string) $translation->locale,
                            (string) $translation->slug,
                            $prefix,
                        );
                    }
                }
            });

        Term::query()
            ->with(['taxonomy', 'translations'])
            ->orderBy('id')
            ->chunkById(200, function ($terms) use ($permalink, &$written): void {
                foreach ($terms as $term) {
                    $type = $term->taxonomy?->type;
                    $prefix = match ($type) {
                        'category', 'tag' => $permalink->termBase($type),
                        default => $term->taxonomy?->slug,
                    };

                    foreach ($term->translations as $translation) {
                        if ($translation->slug === null || $translation->slug === '') {
                            continue;
                        }

                        $written += $this->writePublicSlug(
                            'term',
                            (int) $term->id,
                            (string) $translation->locale,
                            (string) $translation->slug,
                            $prefix,
                        );
                    }
                }
            });

        return $written;
    }

    /**
     * Register (upsert) ONE public cms_slugs row for an arbitrary reference —
     * the public seam a plugin entity uses so its localized slug participates in
     * global uniqueness (uniquePublicSlug) and public-URL resolution (findPublic)
     * through the single slug authority, instead of writing the Slug model
     * directly. Core content/terms reach this via their managers; a plugin calls
     * this from its own write authority. Idempotent + collision-safe; returns 1
     * on success, 0 on failure or before the table exists.
     */
    public function registerPublicSlug(
        string $referenceType,
        int $referenceId,
        string $locale,
        string $slug,
        ?string $prefix = null,
    ): int {
        if (! Schema::hasTable('cms_slugs')) {
            return 0;
        }

        return $this->writePublicSlug($referenceType, $referenceId, $locale, $slug, $prefix);
    }

    /**
     * Remove stale public cms_slugs rows for a reference — used when a plugin
     * deletes a translation (one locale) or an entire entity (all locales, pass
     * $locale = null). Keeps the global slug registry free of dangling mappings.
     * Safe before the table exists; returns the number of rows removed.
     */
    public function forgetPublicSlug(string $referenceType, int $referenceId, ?string $locale = null): int
    {
        if (! Schema::hasTable('cms_slugs')) {
            return 0;
        }

        try {
            $query = Slug::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId);

            if ($locale !== null) {
                $query->where('locale', $locale);
            }

            return $query->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Upsert a single cms_slugs row for a (reference, locale), recomputing
     * full_path from $prefix. Returns 1 on success, 0 on failure (collision-safe).
     */
    private function writePublicSlug(string $referenceType, int $referenceId, string $locale, string $slug, ?string $prefix): int
    {
        try {
            Slug::query()->updateOrCreate(
                [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'locale' => $locale,
                ],
                [
                    'slug' => $slug,
                    'prefix' => $prefix,
                    'full_path' => $this->makeFullPath($slug, $prefix),
                    'is_primary' => true,
                ],
            );

            return 1;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function makeFullPath(string $slug, ?string $prefix = null): string
    {
        $slug = ltrim($slug, '/');
        $prefix = $prefix !== null ? trim($prefix, '/') : null;

        if ($prefix === null || $prefix === '') {
            return $slug;
        }

        return $prefix . '/' . $slug;
    }

    /**
     * Is the bare slug already owned by a DIFFERENT public record in this
     * locale? Matches the cms_slugs.slug column across all reference types.
     */
    private function publicSlugTaken(
        string $slug,
        string $locale,
        ?string $referenceType,
        ?int $referenceId,
    ): bool {
        $query = Slug::query()
            ->where('locale', $locale)
            ->where('slug', $slug);

        if ($referenceType !== null && $referenceId !== null) {
            $query->whereNot(function ($q) use ($referenceType, $referenceId): void {
                $q->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId);
            });
        }

        return $query->exists();
    }

    private function isReservedSlug(string $slug): bool
    {
        return in_array($slug, \TheNguyen\CMS\Services\PermalinkManager::RESERVED, true);
    }

    /**
     * Persist a recomputed prefix/full_path on a slug row. Returns 1 when the
     * row actually changed, else 0. Unique-collision safe (skips on failure).
     */
    private function applyPrefix(Slug $row, ?string $prefix): int
    {
        $fullPath = $this->makeFullPath($row->slug, $prefix);

        if ($row->prefix === $prefix && $row->full_path === $fullPath) {
            return 0;
        }

        $row->prefix = $prefix;
        $row->full_path = $fullPath;

        try {
            $row->save();
        } catch (\Throwable) {
            return 0;
        }

        return 1;
    }

    private function contentSlugExists(string $slug, string $locale, ?int $ignoreTranslationId): bool
    {
        $query = ContentTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug);

        if ($ignoreTranslationId !== null) {
            $query->where('id', '!=', $ignoreTranslationId);
        }

        return $query->exists();
    }

    private function termSlugExists(string $slug, string $locale, ?int $ignoreTranslationId): bool
    {
        $query = TermTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug);

        if ($ignoreTranslationId !== null) {
            $query->where('id', '!=', $ignoreTranslationId);
        }

        return $query->exists();
    }

    /**
     * True when the locale or text uses a script that we should not
     * romanise (CJK, Arabic, Thai, Hebrew, Cyrillic, etc.).
     */
    private function isUnicodeScriptLocale(string $locale, string $text): bool
    {
        $locale = strtolower($locale);

        $unicodeLocales = [
            'zh', 'zh-cn', 'zh-tw', 'ja', 'ko',
            'ar', 'he', 'fa', 'ur',
            'th', 'lo', 'km', 'my',
            'ru', 'uk', 'bg', 'sr',
            'el',
            'hi', 'bn', 'ta', 'te',
        ];

        foreach ($unicodeLocales as $prefix) {
            if ($locale === $prefix || str_starts_with($locale, $prefix . '-')) {
                return true;
            }
        }

        // Heuristic: if the text contains any non-Latin-extended script
        // (Hiragana/Katakana/Han/Hangul/Arabic/Hebrew/Thai/Devanagari),
        // treat as a unicode-script locale regardless of locale tag.
        return (bool) preg_match(
            '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{0600}-\x{06FF}\x{0590}-\x{05FF}\x{0E00}-\x{0E7F}\x{0900}-\x{097F}]/u',
            $text,
        );
    }

    private function shouldTransliterateVietnamese(string $locale, string $text): bool
    {
        if (strtolower($locale) === 'vi') {
            return true;
        }

        return (bool) preg_match('/[ăâêôơưđĂÂÊÔƠƯĐ]/u', $text);
    }

    private function generateUnicodeSafeSlug(string $text): string
    {
        $text = preg_replace('/\s+/u', '-', $text) ?? '';

        // Strip control chars and ASCII URL-unsafe punctuation, keep
        // letters, digits, hyphens, and non-Latin scripts.
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[\/\\\\?#\[\]@!$&\'()*+,;=:"<>{}|^`%]/u', '', $text) ?? '';
        $text = preg_replace('/-+/', '-', $text) ?? '';
        $text = trim($text, '-');

        return $text !== '' ? mb_strtolower($text, 'UTF-8') : $this->fallback('content');
    }

    private function fallback(string $kind): string
    {
        return $kind . '-' . time();
    }

    /**
     * @return array<string, string>
     */
    private function vietnameseMap(): array
    {
        return [
            // a
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'À' => 'A', 'Á' => 'A', 'Ả' => 'A', 'Ã' => 'A', 'Ạ' => 'A',
            'Ă' => 'A', 'Ằ' => 'A', 'Ắ' => 'A', 'Ẳ' => 'A', 'Ẵ' => 'A', 'Ặ' => 'A',
            'Â' => 'A', 'Ầ' => 'A', 'Ấ' => 'A', 'Ẩ' => 'A', 'Ẫ' => 'A', 'Ậ' => 'A',
            // e
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'È' => 'E', 'É' => 'E', 'Ẻ' => 'E', 'Ẽ' => 'E', 'Ẹ' => 'E',
            'Ê' => 'E', 'Ề' => 'E', 'Ế' => 'E', 'Ể' => 'E', 'Ễ' => 'E', 'Ệ' => 'E',
            // i
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'Ì' => 'I', 'Í' => 'I', 'Ỉ' => 'I', 'Ĩ' => 'I', 'Ị' => 'I',
            // o
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'Ò' => 'O', 'Ó' => 'O', 'Ỏ' => 'O', 'Õ' => 'O', 'Ọ' => 'O',
            'Ô' => 'O', 'Ồ' => 'O', 'Ố' => 'O', 'Ổ' => 'O', 'Ỗ' => 'O', 'Ộ' => 'O',
            'Ơ' => 'O', 'Ờ' => 'O', 'Ớ' => 'O', 'Ở' => 'O', 'Ỡ' => 'O', 'Ợ' => 'O',
            // u
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'Ù' => 'U', 'Ú' => 'U', 'Ủ' => 'U', 'Ũ' => 'U', 'Ụ' => 'U',
            'Ư' => 'U', 'Ừ' => 'U', 'Ứ' => 'U', 'Ử' => 'U', 'Ữ' => 'U', 'Ự' => 'U',
            // y
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'Ỳ' => 'Y', 'Ý' => 'Y', 'Ỷ' => 'Y', 'Ỹ' => 'Y', 'Ỵ' => 'Y',
            // d
            'đ' => 'd', 'Đ' => 'D',
        ];
    }
}
