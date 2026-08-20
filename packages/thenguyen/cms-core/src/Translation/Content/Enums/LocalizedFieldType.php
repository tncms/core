<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content\Enums;

/**
 * The canonical vocabulary of localized content field types (Phase 8.4).
 *
 * Each type carries sensible DEFAULT capabilities (fallback policy, whether the
 * default locale is required, searchable, indexable, slug-aware, HTML-capable).
 * A {@see \TheNguyen\CMS\Translation\Content\LocalizedFieldDefinition} starts from
 * these defaults and may override any of them per field. Nothing module-specific
 * is hardcoded — these are reusable primitives.
 */
enum LocalizedFieldType: string
{
    case Title = 'title';
    case Slug = 'slug';
    case Excerpt = 'excerpt';
    case Summary = 'summary';
    case Content = 'content';
    case RichContent = 'rich_content';
    case Markdown = 'markdown';
    case SeoTitle = 'seo_title';
    case SeoDescription = 'seo_description';
    case SeoKeywords = 'seo_keywords';
    case Meta = 'meta';
    case Custom = 'custom';

    /** Whether the field carries HTML (rich/markdown output). */
    public function html(): bool
    {
        return match ($this) {
            self::RichContent, self::Markdown => true,
            default => false,
        };
    }

    /** Whether the field should feed a search index. */
    public function searchable(): bool
    {
        return match ($this) {
            self::Slug, self::Meta => false,
            default => true,
        };
    }

    /** Whether the field should be indexed (e.g. for listings/SEO). */
    public function indexable(): bool
    {
        return $this !== self::Meta;
    }

    /** Whether the field is a slug (locale-independent, URL-shaped). */
    public function slugAware(): bool
    {
        return $this === self::Slug;
    }

    /** Whether the default locale must have a value. */
    public function requiredInDefaultLocale(): bool
    {
        return match ($this) {
            self::Title, self::Slug => true,
            default => false,
        };
    }

    /** The default fallback policy. Slugs/meta are strict (per-locale). */
    public function fallback(): LocalizedFallbackPolicy
    {
        return match ($this) {
            self::Slug, self::Meta => LocalizedFallbackPolicy::Strict,
            default => LocalizedFallbackPolicy::Chain,
        };
    }
}
