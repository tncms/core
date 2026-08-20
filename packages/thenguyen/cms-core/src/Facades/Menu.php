<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TheNguyen\CMS\Models\Menu createMenu(array $data)
 * @method static \TheNguyen\CMS\Models\Menu updateMenu(\TheNguyen\CMS\Models\Menu $menu, array $data)
 * @method static bool deleteMenu(\TheNguyen\CMS\Models\Menu $menu)
 * @method static \TheNguyen\CMS\Models\MenuItem createItem(\TheNguyen\CMS\Models\Menu $menu, array $data)
 * @method static \TheNguyen\CMS\Models\MenuItem updateItem(\TheNguyen\CMS\Models\MenuItem $item, array $data)
 * @method static bool deleteItem(\TheNguyen\CMS\Models\MenuItem $item)
 * @method static \TheNguyen\CMS\Models\Menu|null getMenuBySlug(string $slug, string $locale = 'vi')
 * @method static \TheNguyen\CMS\Models\Menu|null getMenuByLocation(string $location, string $locale = 'vi')
 * @method static array tree(\TheNguyen\CMS\Models\Menu $menu, string $locale = 'vi')
 * @method static void reorderItems(\TheNguyen\CMS\Models\Menu $menu, array $orderedIds)
 *
 * @see \TheNguyen\CMS\Services\MenuManager
 */
class Menu extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.menu';
    }
}
