<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string generate(string $text, string $locale = 'vi')
 * @method static string uniqueContentSlug(string $baseSlug, string $locale, ?int $ignoreTranslationId = null)
 * @method static string uniqueTermSlug(string $baseSlug, string $locale, ?int $ignoreTranslationId = null)
 * @method static string uniquePublicSlug(string $slug, string $locale, ?string $referenceType = null, ?int $referenceId = null)
 * @method static \TheNguyen\CMS\Models\Slug|null findPublic(string $slug, string $locale)
 * @method static int rebuildPublicSlugs()
 * @method static string makeFullPath(string $slug, ?string $prefix = null)
 *
 * @see \TheNguyen\CMS\Services\SlugManager
 */
class Slug extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.slug';
    }
}
