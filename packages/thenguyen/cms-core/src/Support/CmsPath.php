<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

final class CmsPath
{
    public static function base(): string
    {
        return (string) config('cms.paths.base', base_path());
    }

    public static function public(): string
    {
        return (string) config('cms.paths.public', public_path());
    }

    public static function modules(): string
    {
        return (string) config('cms.paths.modules', base_path('modules'));
    }

    public static function themes(): string
    {
        return (string) config('cms.paths.themes', base_path('themes'));
    }

    public static function uploads(): string
    {
        return (string) config('cms.paths.uploads', public_path('uploads'));
    }

    public static function themeAssets(): string
    {
        return (string) config('cms.paths.theme_assets', public_path('themes'));
    }

    public static function cmsAssets(): string
    {
        return (string) config('cms.paths.cms_assets', public_path('vendor/cms'));
    }
}
