<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Adapters;

/**
 * The resolved localized fields for one page in one locale, as produced by the
 * {@see PageTranslationReadAdapter}. Immutable.
 *
 * `title`/`slug` are row-level first-available (with the legacy placeholder /
 * empty-string defaults); `excerpt`/`content`/`seoTitle`/`seoDescription` are the
 * strict requested-locale values (nullable), matching exactly how the frontend
 * and SeoManager read them today.
 */
final class PageLocalizedFields
{
    public function __construct(
        public readonly string $title,
        public readonly string $slug,
        public readonly ?string $excerpt,
        public readonly ?string $content,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
        ];
    }
}
