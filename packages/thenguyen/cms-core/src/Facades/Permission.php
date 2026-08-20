<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $slug, string $name, ?string $group = null, ?string $description = null)
 * @method static array allRegistered()
 * @method static array grouped()
 * @method static void syncDefaults()
 * @method static bool userCan(?\Illuminate\Contracts\Auth\Authenticatable $user, string $permission)
 * @method static bool isSuperAdmin(?\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static bool roleHas(\TheNguyen\CMS\Models\Role $role, string $permission)
 * @method static int superAdminUserCount()
 *
 * @see \TheNguyen\CMS\Services\PermissionManager
 */
class Permission extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.permission';
    }
}
