<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use ZipArchive;

/**
 * Storage needed by one Core upgrade attempt (CORE-UPGRADE-DISK-QUOTA-P0-R1).
 *
 * Derived from the planned operations, not a fixed multiplier:
 *  - staging      = exact sum of uncompressed entry sizes from the package's
 *                   central directory (no extraction), files + implied dirs as inodes;
 *  - backup       = live Core-owned bytes (upper bound: the archive is deflated);
 *  - database     = TWO dumps (verified backup + in-maintenance snapshot);
 *  - metadata     = baseline/plan/journal/manifests (~300 B per owned file + manifest copies);
 *  - fallback     = the largest entry (one verified-copy promotion at a time);
 *  - headroom     = fixed reserve so state, journal and diagnostics stay writable.
 * The package itself is already on disk when this is evaluated and is not counted.
 * Zip-bomb bounds mirror UpgradePackage (80,000 entries / 1 GiB uncompressed).
 */
final class UpgradeStorageBudget
{
    public const HEADROOM_BYTES = 33_554_432; // 32 MiB

    private const MAX_ENTRIES = 80_000;

    private const MAX_TOTAL_BYTES = 1_073_741_824;

    private const METADATA_BYTES_PER_FILE = 300;

    /**
     * @return array{package_bytes:int, staging_bytes:int, staging_inodes:int, largest_entry:int,
     *               backup_bytes:int, database_bytes:int, metadata_bytes:int, headroom_bytes:int,
     *               total_bytes:int, total_inodes:int}|null  null when the package cannot be measured safely
     */
    public static function estimate(string $packageZip, int $liveOwnedBytes, ?int $databaseBytes): ?array
    {
        $zip = new ZipArchive;
        if (! is_file($packageZip) || $zip->open($packageZip) !== true) {
            return null;
        }
        $bytes = 0;
        $largest = 0;
        $dirs = [];
        $files = 0;
        $manifest = 0;
        $n = $zip->numFiles;
        if ($n > self::MAX_ENTRIES) {
            $zip->close();

            return null;
        }
        for ($i = 0; $i < $n; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                $zip->close();

                return null;
            }
            $name = str_replace('\\', '/', (string) $st['name']);
            $size = (int) $st['size'];
            if (str_ends_with($name, '/')) {
                $dirs[rtrim($name, '/')] = true;

                continue;
            }
            $files++;
            $bytes += $size;
            $largest = max($largest, $size);
            if ($name === UpgradePackage::CORE_MANIFEST) {
                $manifest = $size;
            }
            for ($d = \dirname($name); $d !== '.' && $d !== '' && ! isset($dirs[$d]); $d = \dirname($d)) {
                $dirs[$d] = true;
            }
            if ($bytes > self::MAX_TOTAL_BYTES) {
                $zip->close();

                return null;
            }
        }
        $zip->close();

        $db = 2 * max(0, (int) $databaseBytes);
        $meta = $files * self::METADATA_BYTES_PER_FILE + 3 * $manifest;
        $total = $bytes + max(0, $liveOwnedBytes) + $db + $meta + $largest + self::HEADROOM_BYTES;

        return [
            'package_bytes' => (int) filesize($packageZip),
            'staging_bytes' => $bytes,
            'staging_inodes' => $files + \count($dirs),
            'largest_entry' => $largest,
            'backup_bytes' => max(0, $liveOwnedBytes),
            'database_bytes' => $db,
            'metadata_bytes' => $meta,
            'headroom_bytes' => self::HEADROOM_BYTES,
            'total_bytes' => $total,
            'total_inodes' => $files + \count($dirs) + 64,
        ];
    }
}
