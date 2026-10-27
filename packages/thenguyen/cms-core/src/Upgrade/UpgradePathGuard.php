<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Path validation for Core upgrade transactions (CORE-UPGRADE-WIN-R1).
 *
 * Install-relative paths come from a package's ownership record and are
 * untrusted until proven safe. A path is refused when it is absolute, escapes
 * the root, uses an NTFS alternate data stream or a Windows device name, has a
 * trailing dot/space segment (Win32 silently strips those), or crosses a
 * symlink / NTFS junction. PHP reports a junction as neither is_link() nor
 * is_dir(), and readlink() returns a final path even for plain files, so the
 * probe is lstat(): a junction carries no file-type bits.
 *
 * Dependency-free so it can run during recovery without the framework.
 */
final class UpgradePathGuard
{
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    /** @var array<string,bool> dir => is reparse point */
    private array $reparse = [];

    /** @var array<string,array<string,true>> dir => exact entry names */
    private array $listing = [];

    /** Why $rel is unsafe, or null when it is a safe install-relative file path. */
    public static function unsafeReason(string $rel): ?string
    {
        if ($rel === '' || \strlen($rel) > 1024) {
            return 'empty or over-long path';
        }
        if (str_contains($rel, '\\')) {
            return 'backslash in path';
        }
        if (str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:#', $rel) === 1) {
            return 'absolute path';
        }
        if (preg_match('/[\x00-\x1f:*?"<>|]/', $rel) === 1) {
            return 'control, wildcard or alternate-data-stream character';
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'empty, "." or ".." segment';
            }
            if (rtrim($segment, '. ') !== $segment) {
                return 'segment ends with a dot or space';
            }
            if (\in_array(strtolower(explode('.', $segment)[0]), self::RESERVED, true)) {
                return 'Windows device name';
            }
        }

        return null;
    }

    public static function isReparsePoint(string $abs): bool
    {
        if (is_link($abs)) {
            return true;
        }
        $st = @lstat($abs);
        if (! \is_array($st)) {
            return false;
        }
        $type = $st['mode'] & 0170000;

        // A symlink, or (Windows) a junction / other reparse point: lstat reports no file type.
        return $type === 0120000 || ($type === 0 && file_exists($abs));
    }

    /** Why the existing part of $base/$rel crosses a reparse point, or null. */
    public function reparseReason(string $base, string $rel): ?string
    {
        $segments = explode('/', $rel);
        $path = rtrim($base, '/');
        foreach ($segments as $segment) {
            $path .= '/'.$segment;
            if (! array_key_exists($path, $this->reparse)) {
                $this->reparse[$path] = self::isReparsePoint($path);
            }
            if ($this->reparse[$path]) {
                return 'path crosses a symlink or junction';
            }
            if (! file_exists($path)) {
                return null; // the remainder does not exist yet
            }
        }

        return null;
    }

    /**
     * Why the EXISTING part of $base/$rel differs in letter case from the
     * recorded path, or null. The walk stops at the first segment that does not
     * exist under any casing.
     */
    public function caseReason(string $base, string $rel): ?string
    {
        $dir = rtrim($base, '/');
        foreach (explode('/', $rel) as $segment) {
            if (! isset($this->listing[$dir])) {
                $names = is_dir($dir) ? array_diff(scandir($dir) ?: [], ['.', '..']) : [];
                $this->listing[$dir] = array_fill_keys($names, true);
            }
            if (! isset($this->listing[$dir][$segment])) {
                $folded = mb_strtolower($segment, 'UTF-8');
                foreach (array_keys($this->listing[$dir]) as $name) {
                    if (mb_strtolower((string) $name, 'UTF-8') === $folded) {
                        return 'on-disk letter case differs from the recorded path';
                    }
                }

                return null; // does not exist yet
            }
            $dir .= '/'.$segment;
        }

        return null;
    }
}
