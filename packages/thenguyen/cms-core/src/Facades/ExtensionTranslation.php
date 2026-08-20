<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string core(string $key, array $replace = [], ?string $locale = null)
 * @method static string theme(string $key, array $replace = [], ?string $locale = null)
 * @method static string plugin(string $plugin, string $key, array $replace = [], ?string $locale = null)
 * @method static void registerActiveTranslationPaths()
 * @method static array syncTranslationFiles(?array $locales = null)
 * @method static array fileCounts()
 * @method static int coreKeyCount(?string $locale = null)
 * @method static bool adminTranslationReady()
 * @method static int untranslatedCoreKeyCount()
 * @method static array coreTranslationStats()
 *
 * @see \TheNguyen\CMS\Services\ExtensionTranslationManager
 */
class ExtensionTranslation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.extension_translation';
    }
}
