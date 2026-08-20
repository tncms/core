<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(string $key, mixed $default = null, ?string $theme = null)
 * @method static void set(string $key, mixed $value, ?string $theme = null)
 * @method static array all(?string $theme = null)
 * @method static array schema(?string $theme = null)
 * @method static bool hasOptions(?string $theme = null)
 *
 * @see \TheNguyen\CMS\Services\ThemeOptionManager
 */
class ThemeOption extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.theme_option';
    }
}
