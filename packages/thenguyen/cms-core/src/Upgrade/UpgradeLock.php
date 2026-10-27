<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Single-writer Core upgrade lock (CORE-UPGRADE-1, §6).
 *
 * Only ONE Core upgrade may execute at a time. A second browser tab, a second
 * Super Admin, a duplicate apply POST or a parallel attempt must be blocked. The
 * lock is a small JSON file in protected storage recording the owning upgrade
 * id, pid and acquisition time — durable across requests (never session-only).
 *
 * Acquisition is atomic and never leaves an empty lock (CORE-UPGRADE-DISK-QUOTA-P0-R1):
 * the owner record is first written and verified in a private temp file, then
 * published with link() (fails if a lock exists). Where hard links are not
 * supported the fallback is `x`-mode create + verified write, and a failed
 * write removes the lock it just created. A stale lock (no heartbeat within the
 * TTL) or a corrupt/empty lock older than CORRUPT_GRACE may be reclaimed; a live,
 * readable, heart-beating lock is never taken over. Reclaim moves the stale lock
 * aside and re-checks it, so two reclaimers cannot both win.
 *
 * Operator remediation: when no upgrade process is running, deleting
 * storage/app/upgrades/upgrade.lock is safe.
 */
final class UpgradeLock
{
    /** A lock older than this (seconds) with no progress is considered stale. */
    public const STALE_AFTER = 3600;

    /** An unreadable lock younger than this may still be mid-write (non-link fallback). */
    public const CORRUPT_GRACE = 60;

    private string $file;

    public function __construct(?string $root = null)
    {
        $base = $root ?? (\function_exists('storage_path') ? storage_path('app/upgrades') : sys_get_temp_dir().'/tncms-upgrades');
        $base = rtrim(str_replace('\\', '/', $base), '/');
        if (! is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $this->file = $base.'/upgrade.lock';
    }

    public function path(): string
    {
        return $this->file;
    }

    public function isLocked(): bool
    {
        return is_file($this->file);
    }

    /** @return array{upgrade_id:string,pid:int,acquired_at:string}|null */
    public function owner(): ?array
    {
        if (! is_file($this->file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->file), true);

        return \is_array($data) ? $data : null;
    }

    public function isOwnedBy(string $upgradeId): bool
    {
        $owner = $this->owner();

        return $owner !== null && ($owner['upgrade_id'] ?? null) === $upgradeId;
    }

    /**
     * True when a lock exists and neither its acquisition nor its last heartbeat
     * is within the TTL (crashed/abandoned). A long but progressing upgrade keeps
     * heart-beating, so it is never reclaimed from under itself.
     */
    public function isStale(int $ttl = self::STALE_AFTER): bool
    {
        $owner = $this->owner();
        if ($owner === null || ! isset($owner['upgrade_id'])) {
            // Empty or corrupt (e.g. created on a full volume): stale once past the grace period.
            $mtime = @filemtime($this->file);

            return is_file($this->file) && $mtime !== false && ($this->time() - $mtime) > self::CORRUPT_GRACE;
        }
        $acquired = strtotime((string) ($owner['acquired_at'] ?? '')) ?: 0;
        $beat = strtotime((string) ($owner['heartbeat_at'] ?? '')) ?: 0;

        return ($this->time() - max($acquired, $beat)) > $ttl;
    }

    /** Record progress for the owning upgrade (no-op for any other id). */
    public function touch(string $upgradeId): void
    {
        $owner = $this->owner();
        if ($owner === null || ($owner['upgrade_id'] ?? null) !== $upgradeId) {
            return;
        }
        $owner['heartbeat_at'] = gmdate('c');
        if (! $this->isOwnedBy($upgradeId)) {
            return; // reclaimed meanwhile: never resurrect a lock we no longer hold
        }
        try {
            DurableFile::replace($this->file, (string) json_encode($owner, JSON_UNESCAPED_SLASHES), 'upgrade lock heartbeat');
        } catch (UpgradeStorageException) {
            // best effort: a missed heartbeat only shortens the stale window, the lock stays valid
        }
    }

    /**
     * Atomically acquire the lock for $upgradeId. Returns false when another
     * live (non-stale) upgrade already holds it. A stale lock is reclaimed.
     * Re-acquiring your own lock is idempotent-safe (returns true).
     */
    public function acquire(string $upgradeId, int $ttl = self::STALE_AFTER): bool
    {
        if ($this->isOwnedBy($upgradeId)) {
            return true;
        }
        if ($this->isLocked()) {
            $judged = (string) @file_get_contents($this->file); // the exact content found stale
            if (! $this->isStale($ttl) || (string) @file_get_contents($this->file) !== $judged || ! $this->reclaimStale($judged)) {
                return false;
            }
        }

        $record = (string) json_encode([
            'upgrade_id' => $upgradeId,
            'pid' => \function_exists('getmypid') ? (int) getmypid() : 0,
            'acquired_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);

        try {
            $tmp = DurableFile::writeTemp($this->file, $record, 'upgrade lock');
        } catch (UpgradeStorageException) {
            return false; // nothing was published
        }
        $published = @link($tmp, $this->file);
        @unlink($tmp);
        if (! $published && ! is_file($this->file)) {
            $published = $this->createExclusive($record); // hard links unsupported here
        }

        return $published && $this->isOwnedBy($upgradeId);
    }

    /** `x`-mode fallback: create, write, verify; never leave an empty lock behind. */
    private function createExclusive(string $record): bool
    {
        $fh = @fopen($this->file, 'xb');
        if ($fh === false) {
            return false; // lost the race to another request
        }
        try {
            DurableFile::writeAll($fh, $record, 'upgrade lock');
            DurableFile::finish($fh, 'upgrade lock');
        } catch (UpgradeStorageException) {
            @unlink($this->file);

            return false;
        }

        return true;
    }

    /** Move a stale lock aside; give it back if it was replaced by a fresh one meanwhile. */
    private function reclaimStale(string $seen): bool
    {
        $tomb = $this->file.'.stale-'.bin2hex(random_bytes(4));
        if (! @rename($this->file, $tomb)) {
            return false;
        }
        if ((string) @file_get_contents($tomb) !== $seen) {
            @link($tomb, $this->file); // we took a lock that was just renewed: restore it if still free
            @unlink($tomb);

            return false;
        }
        @unlink($tomb);

        return true;
    }

    /** Release the lock only if $upgradeId owns it. */
    public function release(string $upgradeId): bool
    {
        if (! $this->isOwnedBy($upgradeId)) {
            return false;
        }

        return @unlink($this->file);
    }

    private function time(): int
    {
        return time();
    }
}
