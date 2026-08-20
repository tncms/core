<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static int ttl()
 * @method static int version()
 * @method static void flush()
 * @method static string resolveKey(string $locale, string $fullPath)
 * @method static string termArchiveKey(string $locale, int $termId, int $page)
 * @method static array{ready: bool, enabled: bool, ttl: int, version: int} health()
 *
 * @see \TheNguyen\CMS\Services\PublicContentCacheManager
 */
class PublicContentCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.public_cache';
    }
}
