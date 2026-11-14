<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Best-effort OPcache reset after Core replaces PHP files on disk.
 *
 * With opcache.validate_timestamps=0 (or within opcache.revalidate_freq) the PHP process pool keeps executing
 * the previously compiled files after they were overwritten. Resetting is harmless when OPcache is absent,
 * disabled, or its API is restricted (opcache.restrict_api): the call is skipped and false is returned.
 */
final class OpcodeCache
{
    public static function reset(): bool
    {
        if (! function_exists('opcache_reset') || ! function_exists('opcache_get_status')) {
            return false;
        }

        try {
            $status = @opcache_get_status(false);

            if (! is_array($status) || ! ($status['opcache_enabled'] ?? false)) {
                return false;
            }

            return @opcache_reset() === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
