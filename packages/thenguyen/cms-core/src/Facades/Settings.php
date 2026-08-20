<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value, ?string $type = null, array $options = [])
 * @method static bool has(string $key)
 * @method static void forget(string $key)
 * @method static array all()
 * @method static array group(string $group)
 * @method static void clearCache()
 * @method static array refresh()
 * @method static bool isCached()
 *
 * @see \TheNguyen\CMS\Services\SettingsManager
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.settings';
    }
}
