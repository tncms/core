<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array all()
 * @method static array invalidThemes()
 * @method static \TheNguyen\CMS\Support\Theme|null active()
 * @method static string|null activeSlug()
 * @method static \TheNguyen\CMS\Support\Theme|null find(string $slug)
 * @method static bool activate(string $slug)
 * @method static bool deactivate()
 * @method static bool canDeactivate()
 * @method static bool requiredViewsResolvable(string $slug)
 * @method static array themeConfig(?string $slug = null)
 * @method static array themeOptionsSchema(?string $slug = null)
 * @method static bool hasThemeOptions(?string $slug = null)
 * @method static bool themeSystemReady()
 * @method static string themePath(string $slug)
 * @method static string themeAssetsPath(string $slug)
 * @method static void publishAssets(string $slug)
 *
 * @see \TheNguyen\CMS\Services\ThemeManager
 */
class Theme extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.theme';
    }
}
