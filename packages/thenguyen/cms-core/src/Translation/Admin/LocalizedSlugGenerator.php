<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Services\SlugManager;

/**
 * Generates a slug for a specific locale from that locale's title (Phase 8.3).
 *
 * Delegates to the core {@see SlugManager} (the same engine as `cms_slug()`), so
 * slugs are locale-aware. Each locale's slug is generated independently from its
 * own title — the components never derive one locale's slug from another's. This
 * phase makes no routing changes; it only produces slug strings.
 */
final class LocalizedSlugGenerator
{
    public function __construct(private readonly SlugManager $slugs)
    {
    }

    public function generate(string $title, string $locale): string
    {
        return $this->slugs->generate($title, $locale);
    }

    /**
     * Slug for one locale from a title-field state map. Returns null when that
     * locale has no usable title (so an empty title never overwrites a slug).
     *
     * @param array<string, string|null> $titleState locale => title
     */
    public function generateFromState(array $titleState, string $locale): ?string
    {
        $title = $titleState[$locale] ?? null;

        if (! is_string($title) || trim($title) === '') {
            return null;
        }

        return $this->generate($title, $locale);
    }
}
