<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TheNguyen\CMS\Services\SeoManager reset()
 * @method static \TheNguyen\CMS\Services\SeoManager forHome()
 * @method static \TheNguyen\CMS\Services\SeoManager forContent(\TheNguyen\CMS\Models\Content $content, string $locale = 'vi')
 * @method static \TheNguyen\CMS\Services\SeoManager forArchive(\TheNguyen\CMS\Models\Term $term, string $type, string $locale = 'vi')
 * @method static string title()
 * @method static string description()
 * @method static string keywords()
 * @method static string canonical()
 * @method static string robots()
 * @method static string titleSeparator()
 * @method static string ogTitle()
 * @method static string ogDescription()
 * @method static string ogType()
 * @method static string|null ogImage()
 * @method static string twitterCard()
 * @method static string twitterTitle()
 * @method static string twitterDescription()
 * @method static string|null twitterImage()
 * @method static array current()
 * @method static string robotsTxt()
 * @method static array sitemap()
 * @method static string sitemapXml()
 *
 * @see \TheNguyen\CMS\Services\SeoManager
 */
class Seo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.seo';
    }
}
