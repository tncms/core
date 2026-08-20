<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Adapters;

/**
 * The resolved localized fields for one term in one locale, as produced by the
 * {@see TermTranslationReadAdapter}. Immutable. Taxonomy analog of
 * {@see \TheNguyen\CMS\Translation\Adapters\PostLocalizedFields}.
 *
 * `name`/`slug`/`description` are row-level first-available (with the legacy
 * `Term #id` placeholder / empty-string defaults / nullable description);
 * `seoTitle`/`seoDescription` are the strict requested-locale values (nullable),
 * matching exactly how the frontend and SeoManager read a term today.
 */
final class TermLocalizedFields
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly ?string $seoTitle,
        public readonly ?string $seoDescription,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
        ];
    }
}
