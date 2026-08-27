<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

final class CmsInfo
{
    public const VERSION = '1.0.0-beta.7.1.22';

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

    public static function activeTheme(): string
    {
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
