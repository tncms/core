<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string rawPostBase()
 * @method static string rawCategoryBase()
 * @method static string rawTagBase()
 * @method static string postBase()
 * @method static string categoryBase()
 * @method static string tagBase()
 * @method static bool hasPostBase()
 * @method static bool hasCategoryBase()
 * @method static bool hasTagBase()
 * @method static string|null contentBase(string $type)
 * @method static string|null termBase(string $taxonomyType)
 * @method static string normalizeBase(?string $base)
 * @method static bool isReserved(string $base)
 *
 * @see \TheNguyen\CMS\Services\PermalinkManager
 */
class Permalink extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.permalink';
    }
}
