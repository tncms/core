<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string markerPath()
 * @method static bool isInstalled()
 * @method static bool canRun()
 * @method static void markInstalled()
 * @method static array requirements()
 * @method static bool requirementsPassed()
 * @method static string detectAppUrl()
 * @method static bool writeEnv(array $values)
 * @method static array testDatabaseConnection(array $cfg)
 * @method static void applyRuntimeDatabase(array $cfg)
 * @method static void ensureAppKey()
 * @method static void runMigrations()
 * @method static array runSeeders()
 * @method static void applyDefaultLanguage(string $code)
 * @method static void clearCaches()
 * @method static object createSuperAdmin(array $data)
 * @method static bool superAdminExists()
 * @method static string frontendUrl()
 * @method static string adminUrl()
 *
 * @see \TheNguyen\CMS\Services\InstallerManager
 */
class Installer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.installer';
    }
}
