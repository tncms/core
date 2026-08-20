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
 * Acquisition is atomic: the file is created with the `x` (exclusive) mode, so
 * two concurrent requests cannot both succeed. A stale lock (older than the TTL,
 * e.g. from a crashed apply) has a defined recovery policy: it may be force-
 * released by the SAME upgrade id that owns it, or reclaimed once stale.
 */
final class UpgradeLock
{
    /** A lock older than this (seconds) with no progress is considered stale. */
    public const STALE_AFTER = 3600;

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

    /** True when a lock exists but is older than the TTL (crashed/abandoned). */
    public function isStale(int $ttl = self::STALE_AFTER): bool
    {
        $owner = $this->owner();
        if ($owner === null) {
            return false;
        }
        $acquired = strtotime((string) ($owner['acquired_at'] ?? '')) ?: 0;

        return ($this->time() - $acquired) > $ttl;
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
            if (! $this->isStale($ttl)) {
                return false;
            }
            // Reclaim a stale lock (§6 recovery policy).
            @unlink($this->file);
        }

        $fh = @fopen($this->file, 'x'); // exclusive create — atomic guard
        if ($fh === false) {
            return false; // lost the race to another request
        }
        fwrite($fh, (string) json_encode([
            'upgrade_id' => $upgradeId,
            'pid' => \function_exists('getmypid') ? (int) getmypid() : 0,
            'acquired_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES));
        fclose($fh);

        return $this->isOwnedBy($upgradeId);
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
