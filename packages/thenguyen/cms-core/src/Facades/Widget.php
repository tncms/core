<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $class)
 * @method static array registered()
 * @method static string|null find(string $type)
 * @method static array available()
 * @method static array groupedAvailable()
 * @method static array schemaFor(string $type)
 * @method static void registerArea(string $slug, string $name, array $options = [])
 * @method static array areas()
 * @method static void syncAreas()
 * @method static void registerPreset(string $slug, array $definition)
 * @method static array presets()
 * @method static array|null findPreset(string $slug)
 * @method static int applyPreset(string $slug, int $areaId)
 * @method static array export(?array $areaSlugs = null)
 * @method static int import(array $data, string $mode = 'merge')
 * @method static \TheNguyen\CMS\Models\Widget|null duplicate(int $widgetId)
 * @method static string renderArea(string $slug, ?string $locale = null)
 * @method static string renderWidget(\TheNguyen\CMS\Models\Widget $widget, ?string $locale = null)
 *
 * @see \TheNguyen\CMS\Services\WidgetManager
 */
class Widget extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.widget';
    }
}
