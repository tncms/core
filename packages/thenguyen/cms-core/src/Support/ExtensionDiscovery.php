<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Shared discovery rules for theme/plugin folders on disk (v1.0.0-beta.2).
 *
 * Discovery scans only the *direct children* of the themes/ and plugins/ roots.
 * A small, fixed set of well-known infrastructure folder names — plus any hidden
 * (dot-prefixed) folder — are never treated as an extension and are skipped
 * entirely: they appear in neither the valid nor the invalid list. Every other
 * folder is still inspected, and one without a valid manifest is reported as
 * *invalid* (never thrown). Keeping the rule in one place guarantees ThemeManager
 * and ExtensionManager discover deterministically and identically.
 */
final class ExtensionDiscovery
{
    /**
     * Infrastructure folder names that are never extensions. Stored lowercase
     * and matched case-insensitively (e.g. "__MACOSX" → "__macosx").
     */
    public const IGNORED_DIRECTORIES = [
        '.git',
        '.github',
        'node_modules',
        'vendor',
        'references',
        'storage',
        'tests',
        '__macosx',
    ];

    /**
     * True when a directory (given its basename) must be skipped by discovery:
     * an empty name, any hidden folder (leading dot), or any reserved
     * infrastructure name above.
     */
    public static function isIgnored(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '.')) {
            return true;
        }

        return in_array(strtolower($name), self::IGNORED_DIRECTORIES, true);
    }
}
