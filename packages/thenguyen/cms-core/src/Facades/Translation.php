<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Translation Engine (Phase 8.0).
 *
 * @method static \TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface registry()
 * @method static \TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface resolver()
 * @method static \TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface cache()
 * @method static \TheNguyen\CMS\Translation\Drivers\TranslationDriverRegistry drivers()
 * @method static \TheNguyen\CMS\Translation\DTOs\TranslationResult resolve(\TheNguyen\CMS\Translation\DTOs\TranslationKey $key, ?\TheNguyen\CMS\Translation\DTOs\TranslationContext $context = null)
 * @method static \TheNguyen\CMS\Translation\DTOs\TranslationResult resolveValue(\TheNguyen\CMS\Translation\DTOs\LocalizedValue $value, ?\TheNguyen\CMS\Translation\DTOs\TranslationContext $context = null)
 * @method static array locales()
 * @method static ?string defaultLocale()
 * @method static ?string fallbackLocale()
 * @method static void flush()
 *
 * @see \TheNguyen\CMS\Translation\TranslationManager
 */
class Translation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.translation';
    }
}
