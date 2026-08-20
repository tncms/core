<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * Permalink base settings (v0.9.6).
 *
 * Owns the configurable URL bases for posts, categories and tags. A base may be
 * left empty: an empty base means "no prefix", and the record resolves through
 * the generic /{slug} route against the cms_slugs table (WordPress-style global
 * unique public slugs). When a base is set it is used as the URL prefix and the
 * dedicated /{base}/{slug} route is registered.
 *
 * Effective getters (postBase/categoryBase/tagBase) return the normalised
 * stored value, which may be an empty string. contentBase()/termBase() map that
 * to a route/URL prefix (null = no prefix). See CMS_ARCHITECTURE.md §permalinks.
 */
class PermalinkManager
{
    public const DEFAULT_POST_BASE = 'blog';

    public const DEFAULT_CATEGORY_BASE = 'category';

    public const DEFAULT_TAG_BASE = 'tag';

    /**
     * Single-segment prefixes a permalink base must never use, because they are
     * already claimed by the admin, infra endpoints, or static asset dirs.
     */
    public const RESERVED = [
        'admin', 'cms-health', 'livewire', 'filament', 'storage',
        'uploads', 'themes', 'robots.txt', 'sitemap.xml', 'up', 'vendor',
    ];

    /** Raw stored post base (may be empty). */
    public function rawPostBase(): string
    {
        return $this->raw('permalink.post_base', self::DEFAULT_POST_BASE);
    }

    public function rawCategoryBase(): string
    {
        return $this->raw('permalink.category_base', self::DEFAULT_CATEGORY_BASE);
    }

    public function rawTagBase(): string
    {
        return $this->raw('permalink.tag_base', self::DEFAULT_TAG_BASE);
    }

    /**
     * Effective post base: the normalised stored value, which may be an empty
     * string (empty = base-less, resolved through the generic /{slug} route).
     */
    public function postBase(): string
    {
        return $this->normalizeBase($this->rawPostBase());
    }

    public function categoryBase(): string
    {
        return $this->normalizeBase($this->rawCategoryBase());
    }

    public function tagBase(): string
    {
        return $this->normalizeBase($this->rawTagBase());
    }

    /** True when posts use a URL prefix (a non-empty post base). */
    public function hasPostBase(): bool
    {
        return $this->postBase() !== '';
    }

    public function hasCategoryBase(): bool
    {
        return $this->categoryBase() !== '';
    }

    public function hasTagBase(): bool
    {
        return $this->tagBase() !== '';
    }

    /**
     * URL/route prefix for a content record. Posts use the post base; pages
     * never have a base. Returns null when there is no prefix (page, or an
     * empty post base) — the record then resolves at /{slug}.
     */
    public function contentBase(string $type): ?string
    {
        if ($type !== 'post') {
            return null;
        }

        $base = $this->postBase();

        return $base !== '' ? $base : null;
    }

    /**
     * URL/route prefix for a taxonomy term type (category/tag). Returns null
     * when the base is empty (the term resolves at /{slug}).
     */
    public function termBase(string $taxonomyType): ?string
    {
        $base = $taxonomyType === 'tag' ? $this->tagBase() : $this->categoryBase();

        return $base !== '' ? $base : null;
    }

    /**
     * Lowercase, trimmed, slash-free representation of a base value.
     */
    public function normalizeBase(?string $base): string
    {
        $base = strtolower(trim((string) $base));

        return trim($base, '/');
    }

    public function isReserved(string $base): bool
    {
        return in_array($this->normalizeBase($base), self::RESERVED, true);
    }

    /**
     * @return array<int, string>
     */
    public static function reserved(): array
    {
        return self::RESERVED;
    }

    private function raw(string $key, string $default): string
    {
        $value = settings($key, $default);

        return is_string($value) ? $value : $default;
    }
}
