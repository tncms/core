<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for the Global Script Manager (v1.0.0-beta.7.1.13).
 *
 * @method static bool head(string $key, string $content, int $priority = 10, array $source = [])
 * @method static bool footer(string $key, string $content, int $priority = 10, array $source = [])
 * @method static bool externalHead(string $key, string $url, array $attributes = [], int $priority = 10, array $source = [])
 * @method static bool externalFooter(string $key, string $url, array $attributes = [], int $priority = 10, array $source = [])
 * @method static bool meta(string $name, string $content, int $priority = 10, array $source = [])
 * @method static bool verification(string $provider, string $value, int $priority = 10, array $source = [])
 * @method static bool jsonLd(string $key, array|string $data, int $priority = 10, array $source = [])
 * @method static bool embed(string $key, string $html, string $position = 'head', int $priority = 10, array $source = [])
 * @method static string renderHead()
 * @method static string renderFooter()
 * @method static array all()
 * @method static array rejected()
 * @method static array diagnostics()
 * @method static array warnings()
 * @method static void flush()
 * @method static array healthSnapshot()
 *
 * @see \TheNguyen\CMS\Services\ScriptManager
 */
class Script extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.scripts';
    }
}
