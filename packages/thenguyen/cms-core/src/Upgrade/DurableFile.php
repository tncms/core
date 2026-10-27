<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Verified, crash-aware file writes for upgrade metadata (CORE-UPGRADE-DISK-QUOTA-P0-R1).
 *
 * A write is complete only when every byte was accepted, the stream flushed and
 * (where the runtime supports it) fsync'd, the handle closed cleanly, and the
 * bytes read back match. Replacement goes through a same-directory temp file
 * and rename(), so the previous content is never overwritten in place:
 *  - POSIX: rename(2) atomically replaces the target;
 *  - Windows: PHP uses MoveFileEx(MOVEFILE_REPLACE_EXISTING); not guaranteed
 *    atomic across power loss, which is why callers that must survive a torn
 *    replace keep a previous generation (see UpgradeStateStore).
 * Any short write, flush, sync, close or rename failure throws and removes the
 * temp file; the target is left exactly as it was.
 *
 * Dependency-free so recovery can use it without the framework.
 */
final class DurableFile
{
    private const RENAME_ATTEMPTS = 3;

    private const RETRY_DELAY_US = 150_000;

    /** Write $bytes to an open stream: every byte, then flush (+ fsync where supported). */
    public static function writeAll($fh, string $bytes, string $what): void
    {
        $len = \strlen($bytes);
        $done = 0;
        while ($done < $len) {
            $n = @fwrite($fh, $done === 0 ? $bytes : substr($bytes, $done));
            if ($n === false || $n === 0) {
                throw new UpgradeStorageException("$what: short write ($done of $len bytes)", 'write_short');
            }
            $done += $n;
        }
    }

    /** Flush, sync where supported, and close; each step is checked. */
    public static function finish($fh, string $what): void
    {
        if (! @fflush($fh)) {
            @fclose($fh);
            throw new UpgradeStorageException("$what: flush failed", 'flush_failed');
        }
        // fsync() exists only for real files (PHP >= 8.1); other stream types rely on the checked flush.
        $plain = (stream_get_meta_data($fh)['wrapper_type'] ?? '') === 'plainfile';
        if ($plain && \function_exists('fsync') && ! @fsync($fh)) {
            @fclose($fh);
            throw new UpgradeStorageException("$what: sync failed", 'sync_failed');
        }
        if (! @fclose($fh)) {
            throw new UpgradeStorageException("$what: close failed", 'close_failed');
        }
    }

    /**
     * Atomically (where the OS allows) replace $path with exactly $bytes.
     *
     * @throws UpgradeStorageException
     */
    public static function replace(string $path, string $bytes, string $what = 'file'): void
    {
        $tmp = self::writeTemp($path, $bytes, $what);
        if (! self::rename($tmp, $path)) {
            @unlink($tmp);
            throw new UpgradeStorageException("$what: could not be put in place", 'rename_failed');
        }
    }

    /**
     * Write $bytes to a verified temp file next to $path and return its path.
     *
     * @throws UpgradeStorageException
     */
    public static function writeTemp(string $path, string $bytes, string $what = 'file'): string
    {
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new UpgradeStorageException("$what: directory cannot be created", 'create_failed');
        }
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(4));
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) {
            throw new UpgradeStorageException("$what: cannot create a new file", 'create_failed');
        }
        try {
            self::writeAll($fh, $bytes, $what);
            self::finish($fh, $what);
        } catch (UpgradeStorageException $e) {
            @unlink($tmp);
            throw $e;
        }
        $back = @file_get_contents($tmp);
        if (! \is_string($back) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $back))) {
            @unlink($tmp);
            throw new UpgradeStorageException("$what: read-back verification failed", 'verify_failed');
        }

        return $tmp;
    }

    /** rename() with a short bounded retry (transient Windows sharing violations). */
    public static function rename(string $from, string $to): bool
    {
        for ($i = 1; $i <= self::RENAME_ATTEMPTS; $i++) {
            if (@rename($from, $to)) {
                return true;
            }
            if ($i < self::RENAME_ATTEMPTS) {
                usleep(self::RETRY_DELAY_US);
            }
        }

        return false;
    }
}
