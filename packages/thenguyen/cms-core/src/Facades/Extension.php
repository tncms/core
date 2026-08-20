<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array themes()
 * @method static array invalidThemes()
 * @method static array plugins()
 * @method static \TheNguyen\CMS\Support\Plugin|null findPlugin(string $slug)
 * @method static array invalidPlugins()
 * @method static array activePluginSlugs()
 * @method static array activePlugins()
 * @method static bool isPluginActive(string $slug)
 * @method static bool activatePlugin(string $slug)
 * @method static bool deactivatePlugin(string $slug)
 * @method static void bootActivePlugins()
 * @method static void ensurePluginAutoload(\TheNguyen\CMS\Support\Plugin $plugin)
 * @method static bool providerExists(\TheNguyen\CMS\Support\Plugin $plugin, string $provider)
 * @method static array pluginProviderWarnings(\TheNguyen\CMS\Support\Plugin $plugin)
 * @method static array activeFilamentResources()
 * @method static array activeFilamentPages()
 * @method static bool extensionFrameworkReady()
 *
 * @see \TheNguyen\CMS\Services\ExtensionManager
 */
class Extension extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.extension';
    }
}
