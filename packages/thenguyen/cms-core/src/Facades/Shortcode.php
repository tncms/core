<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $tag, callable|string $callback)
 * @method static bool has(string $tag)
 * @method static void remove(string $tag)
 * @method static array all()
 * @method static string render(?string $content, array $context = [])
 * @method static string strip(?string $content)
 *
 * @see \TheNguyen\CMS\Services\ShortcodeManager
 */
class Shortcode extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.shortcodes';
    }
}
