<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

/**
 * Operator limits of the frontend media library. Each value is read per call from the site
 * setting, falling back to config('cms.media.frontend.*'), and clamped to a safe range so a
 * mistyped setting can never disable a limit.
 *
 * | setting                              | config key                         | default | range        |
 * |--------------------------------------|------------------------------------|---------|--------------|
 * | media.frontend_quota_mb              | cms.media.frontend.quota_mb        | 100     | 1..102400    |
 * | media.frontend_max_dimension         | cms.media.frontend.max_dimension   | 6000    | 64..20000    |
 * | media.frontend_uploads_per_minute    | cms.media.frontend.uploads_per_minute | 10   | 1..120       |
 * | (config only)                        | cms.media.frontend.list_per_minute | 120     | 10..600      |
 */
final class FrontendMediaLimits
{
    public static function quotaBytes(): int
    {
        return self::value('media.frontend_quota_mb', 'quota_mb', 100, 1, 102400) * 1024 * 1024;
    }

    public static function maxDimension(): int
    {
        return self::value('media.frontend_max_dimension', 'max_dimension', 6000, 64, 20000);
    }

    public static function uploadsPerMinute(): int
    {
        return self::value('media.frontend_uploads_per_minute', 'uploads_per_minute', 10, 1, 120);
    }

    /** CORE-P12-1: second ceiling per client IP, shared by every account behind it. */
    public static function ipUploadsPerMinute(): int
    {
        return self::value(null, 'ip_uploads_per_minute', 60, 1, 1200);
    }

    public static function deletesPerMinute(): int
    {
        return self::value(null, 'deletes_per_minute', 30, 1, 600);
    }

    public static function listPerMinute(): int
    {
        return self::value(null, 'list_per_minute', 120, 10, 600);
    }

    private static function value(?string $setting, string $configKey, int $default, int $min, int $max): int
    {
        $fallback = config('cms.media.frontend.'.$configKey, $default);
        $fallback = is_numeric($fallback) ? (int) $fallback : $default;

        $raw = $setting !== null ? settings($setting, $fallback) : $fallback;
        $value = is_numeric($raw) ? (int) $raw : $fallback;

        if ($value < $min || $value > $max) {
            $value = ($fallback >= $min && $fallback <= $max) ? $fallback : $default;
        }

        return $value;
    }
}
