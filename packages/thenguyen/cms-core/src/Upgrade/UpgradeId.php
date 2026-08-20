<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Stable, unique upgrade attempt identifier (CORE-UPGRADE-1, §5).
 *
 * Every attempt gets one id used consistently for state, package storage,
 * staging, backup, logs, history and the lock. Identity is NEVER derived from
 * the uploaded filename (two uploads of the same file are distinct attempts).
 *
 * Format: upg_<UTC yyyymmddhhmmss>_<8 hex> — sortable by time, collision-safe
 * via random suffix, and matched by UpgradeStateStore's strict id validator.
 */
final class UpgradeId
{
    public static function generate(): string
    {
        return 'upg_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(4));
    }

    public static function isValid(string $id): bool
    {
        return preg_match('/^upg_[0-9]{8,14}_[0-9a-f]{6,16}$/', $id) === 1;
    }
}
