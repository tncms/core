<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Canonical CMS Cache policy (CORE-OPTIMIZE-1).
 *
 * Core modules, themes and future plugins consume the caching posture through
 * this authority instead of reading the raw setting key:
 *
 *   if (CmsCachePolicy::enabled()) { ...populate optimization cache... }
 *
 * @method static bool enabled()
 * @method static bool disabled()
 * @method static bool clear()
 *
 * @see \TheNguyen\CMS\Services\CmsCachePolicy
 */
class CmsCachePolicy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.cache_policy';
    }
}
