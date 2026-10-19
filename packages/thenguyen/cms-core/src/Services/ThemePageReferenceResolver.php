<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Content;

/**
 * CORE-THEME-PAGE-REFERENCE-1 — the public, theme-agnostic resolver for a Theme
 * Options `page` field.
 *
 * A `page` option stores a stable canonical page id (never a slug or URL). This
 * service turns that stored id into a public, locale-aware absolute URL **at read
 * time**, so the link always follows the page's current slug and locale routing.
 *
 * Safety contract (every branch returns null, never a broken/preview/admin URL):
 *   - unset/empty, non-numeric/malformed id            → null
 *   - draft / pending / private                        → null (status gate)
 *   - soft-deleted / hard-deleted / missing            → null
 *   - wrong type (a post, not a page)                  → null
 *   - missing translation in locale / disabled locale  → null (via the URL service)
 *
 * Only a currently published, non-soft-deleted `type=page` record with a
 * publicly resolvable localized route produces a URL. Publication status is
 * gated here; the URL string itself comes from the ONE canonical resource→URL
 * authority ({@see LocalizedContentUrlService}). The returned value is a plain
 * URL string — HTML escaping is the theme rendering layer's responsibility.
 */
final class ThemePageReferenceResolver
{
    public function __construct(private readonly LocalizedContentUrlService $urls) {}

    /**
     * The public absolute URL for the page referenced by a theme-option key, or
     * null when there is no safe public link. Locale defaults to the current
     * request locale.
     */
    public function url(string $key, ?string $locale = null): ?string
    {
        $id = $this->optionId($key);

        if ($id === null) {
            return null;
        }

        $locale = (is_string($locale) && $locale !== '') ? $locale : current_locale();

        return $this->urlForId($id, $locale);
    }

    /**
     * The public absolute URL for an explicit page id + locale, or null.
     */
    public function urlForId(int $id, string $locale): ?string
    {
        $page = $this->publicPage($id);

        return $page === null ? null : $this->urls->absoluteForResource($page, $locale);
    }

    /**
     * The currently published, non-soft-deleted `type=page` record for this id,
     * or null. A single indexed primary-key lookup — never a full-table scan.
     */
    public function publicPage(int $id): ?Content
    {
        if ($id <= 0) {
            return null;
        }

        return Content::query()
            ->whereKey($id)
            ->where('type', 'page')
            ->where('status', 'published')
            ->first();
    }

    /**
     * The page record for admin display regardless of status, INCLUDING
     * soft-deleted, or null when hard-deleted/missing or not a page. Used to show
     * (and preserve) a stale selection without silently dropping it.
     */
    public function anyPage(int $id): ?Content
    {
        if ($id <= 0) {
            return null;
        }

        return Content::withTrashed()
            ->whereKey($id)
            ->where('type', 'page')
            ->first();
    }

    /**
     * The normalized positive page id stored under a theme-option key, or null.
     */
    public function optionId(string $key): ?int
    {
        return $this->normalizeId(theme_option($key));
    }

    /**
     * Coerce a persisted/submitted option value into a positive int id, or null
     * for empty/non-numeric/malformed input. Never throws.
     */
    public function normalizeId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /**
     * Whether a submitted value may be persisted for a `page` option:
     *
     *   - empty/null                       → true  (unset the reference)
     *   - malformed / non-numeric          → false (reject)
     *   - resolves to an existing non-page → false (reject wrong type, e.g. a post)
     *   - a page (any status, incl trashed) or a now-missing numeric id → true
     *     (preserve a stale/unpublished/hard-deleted reference; read-time resolution is safe)
     */
    public function isPersistable(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $id = $this->normalizeId($value);

        if ($id === null) {
            return false;
        }

        $content = Content::withTrashed()->whereKey($id)->first(['id', 'type']);

        if ($content === null) {
            return true;
        }

        return $content->type === 'page';
    }
}
