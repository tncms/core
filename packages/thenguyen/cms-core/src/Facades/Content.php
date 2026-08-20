<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TheNguyen\CMS\Models\Content create(array $data)
 * @method static \TheNguyen\CMS\Models\Content update(\TheNguyen\CMS\Models\Content $content, array $data)
 * @method static bool delete(\TheNguyen\CMS\Models\Content $content)
 * @method static ?\TheNguyen\CMS\Models\Content findBySlug(string $slug, string $locale = 'vi', ?string $type = null)
 * @method static ?\TheNguyen\CMS\Models\ContentTranslation getTranslation(\TheNguyen\CMS\Models\Content $content, string $locale = 'vi')
 *
 * @see \TheNguyen\CMS\Services\ContentManager
 */
class Content extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.content';
    }
}
