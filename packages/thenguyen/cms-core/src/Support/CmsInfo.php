<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

final class CmsInfo
{
    public const VERSION = '1.0.0-beta.7.1.39';

    /**
     * True inside a long-running worker (Laravel Octane on Swoole/RoadRunner/
     * FrankenPHP, or FrankenPHP worker mode). Not a supported runtime
     * (CORE-PLATFORM-SEAMS-R2); used only to warn.
     */
    public static function isLongRunningWorker(): bool
    {
        return ! empty($_SERVER['LARAVEL_OCTANE']) || ! empty($_SERVER['FRANKENPHP_WORKER']) || app()->bound('octane');
    }

    /** Canonical brand identity (see CMS_ARCHITECTURE.md §brand). */
    public const BRAND = 'TN CMS';

    public const WEBSITE = 'https://tncms.org';

    public const SUPPORT_EMAIL = 'support@tncms.org';

    public const AUTHOR = 'The Nguyen Media';

    public static function name(): string
    {
        return (string) config('cms.name', self::BRAND);
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * The committed active-theme slug — the SINGLE canonical authority also used
     * by the frontend, the Themes page, Theme Options, Import Demo and the page
     * template registry (cms_settings "theme.active", resolved through
     * {@see \TheNguyen\CMS\Services\ThemeManager::active()} with its deterministic
     * fallback).
     *
     * config('cms.theme.active') (== env('CMS_ACTIVE_THEME')) is ONLY the install
     * seed for that setting and is never updated on activation; it is used here
     * exclusively as a bootstrap/recovery fallback for when the theme service or
     * database is not yet available (pre-install boot). Diagnostics must never
     * report that stale value in place of the committed theme.
     */
    public static function activeTheme(): string
    {
        try {
            if (app()->bound('cms.theme')) {
                $slug = app('cms.theme')->active()?->slug;

                if (is_string($slug) && $slug !== '') {
                    return $slug;
                }
            }
        } catch (\Throwable) {
            // Fall through to the bootstrap/recovery config fallback below.
        }

        return (string) config('cms.theme.active', 'default');
    }

    public static function deploymentMode(): string
    {
        return (string) config('cms.deployment.mode', 'public_root');
    }

    public static function basePath(): string
    {
        return (string) config('cms.paths.base', base_path());
    }

    public static function publicPath(): string
    {
        return (string) config('cms.paths.public', public_path());
    }
}
