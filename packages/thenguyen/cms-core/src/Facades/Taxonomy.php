<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void ensureCoreTaxonomies()
 * @method static \TheNguyen\CMS\Models\Term createTerm(string $taxonomySlug, array $data)
 * @method static \TheNguyen\CMS\Models\Term updateTerm(\TheNguyen\CMS\Models\Term $term, array $data)
 * @method static bool deleteTerm(\TheNguyen\CMS\Models\Term $term)
 * @method static ?\TheNguyen\CMS\Models\Term findTermBySlug(string $slug, string $locale = 'vi', ?string $taxonomySlug = null)
 *
 * @see \TheNguyen\CMS\Services\TaxonomyManager
 */
class Taxonomy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.taxonomy';
    }
}
