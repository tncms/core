<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isReady()
 * @method static \TheNguyen\CMS\Support\InstallResult installPluginFromZip(string $zipPath, bool $overwrite = false)
 * @method static \TheNguyen\CMS\Support\InstallResult installThemeFromZip(string $zipPath, bool $overwrite = false)
 * @method static \TheNguyen\CMS\Support\InstallResult deletePlugin(string $slug)
 * @method static \TheNguyen\CMS\Support\InstallResult deleteTheme(string $slug)
 *
 * @see \TheNguyen\CMS\Services\ExtensionInstaller
 */
class ExtensionInstaller extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.extension_installer';
    }
}
