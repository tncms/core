<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Asset Registry (v1.0.0-beta.7.1.13.1).
 *
 * @method static bool registerStyle(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'head', array $attributes = [], ?string $media = null, array $source = [])
 * @method static bool registerScript(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = [], array $source = [])
 * @method static bool registerModule(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = [], array $source = [])
 * @method static bool registerMarker(string $handle, string $scope = 'both', array $source = [])
 * @method static bool enqueueStyle(string $handle)
 * @method static bool enqueueScript(string $handle)
 * @method static bool enqueueModule(string $handle)
 * @method static bool style(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'head', array $attributes = [], ?string $media = null, array $source = [])
 * @method static bool script(string $handle, string $src, array $deps = [], ?string $version = null, string $scope = 'frontend', string $position = 'footer', array $attributes = [], array $source = [])
 * @method static bool inlineStyle(string $handle, string $code, ?string $before = null, ?string $after = null, string $scope = 'frontend', string $position = 'head', array $source = [])
 * @method static bool inlineScript(string $handle, string $code, ?string $before = null, ?string $after = null, string $scope = 'frontend', string $position = 'footer', array $source = [])
 * @method static string renderFrontendStyles()
 * @method static string renderFrontendScripts()
 * @method static string renderAdminStyles()
 * @method static string renderAdminScripts()
 * @method static string renderStyles(string $scope, string $position = 'head')
 * @method static string renderScripts(string $scope, string $position = 'footer')
 * @method static array all()
 * @method static array inlines()
 * @method static array rejected()
 * @method static array lastRenderWarnings()
 * @method static array lastRenderSkipped()
 * @method static array diagnostics()
 * @method static array warnings()
 * @method static void flush()
 * @method static array healthSnapshot()
 *
 * @see \TheNguyen\CMS\Services\AssetRegistry
 */
class Asset extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.assets';
    }
}
