<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Support\Collection all(bool $activeOnly = true)
 * @method static \Illuminate\Support\Collection active()
 * @method static \TheNguyen\CMS\Models\Language|null default()
 * @method static string defaultCode()
 * @method static \TheNguyen\CMS\Models\Language|null current()
 * @method static string currentCode()
 * @method static \TheNguyen\CMS\Models\Language|null find(string $code)
 * @method static void setCurrent(string $code)
 * @method static bool setDefault(string $code)
 * @method static \TheNguyen\CMS\Models\Language create(array $data)
 * @method static \TheNguyen\CMS\Models\Language update(\TheNguyen\CMS\Models\Language $language, array $data)
 * @method static bool delete(\TheNguyen\CMS\Models\Language $language)
 * @method static string|null deletionBlockReason(\TheNguyen\CMS\Models\Language $language)
 * @method static bool hasTranslatedData(string $code)
 * @method static bool isActive(string $code)
 * @method static string normalizeCode(?string $code)
 * @method static bool shouldPrefixDefaultLocale()
 * @method static string localizedUrl(string $code, ?string $path = null)
 * @method static array getPublicLocales()
 * @method static array optionList()
 *
 * @see \TheNguyen\CMS\Services\LanguageManager
 */
class Language extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.language';
    }
}
