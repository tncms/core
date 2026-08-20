<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool isEnabled()
 * @method static string mode()
 * @method static int statusCode()
 * @method static ?int retryAfterMinutes()
 * @method static array excludePaths()
 * @method static array allowedIps()
 * @method static array settings()
 * @method static bool shouldBypass(\Illuminate\Http\Request $request, ?\Illuminate\Contracts\Auth\Authenticatable $user = null)
 * @method static bool isExcludedPath(string $path)
 * @method static \Symfony\Component\HttpFoundation\Response response(\Illuminate\Http\Request $request)
 * @method static string render()
 *
 * @see \TheNguyen\CMS\Services\MaintenanceManager
 */
class Maintenance extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.maintenance';
    }
}
