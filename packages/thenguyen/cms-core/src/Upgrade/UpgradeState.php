<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Durable upgrade state machine (CORE-UPGRADE-1, §4/§7).
 *
 * The SINGLE authority for the legal lifecycle of a Core upgrade attempt. The
 * status itself is persisted by {@see UpgradeStateStore} in a JSON file that
 * survives ordinary browser refreshes; this class owns only the vocabulary and
 * the allowed transitions, so idempotency + illegal-transition rejection have
 * ONE definition (never re-derived in the controller or engine).
 *
 * Pure and dependency-free.
 */
final class UpgradeState
{
    // ---- ordered happy-path states -------------------------------------
    /** Package uploaded/selected and stored in protected storage; nothing verified. */
    public const UPLOADED = 'uploaded';

    /** Package manifest/product/version/checksums validated (§14). */
    public const VERIFIED = 'verified';

    /** System check passed: installed, PHP/DB/disk/writable/lock (§15). */
    public const PREFLIGHT_PASSED = 'preflight_passed';

    /** Backup in progress (DB + Core files + .env). */
    public const BACKUP_STARTED = 'backup_started';

    /** Backup produced AND verified — the hard gate to apply (§20). */
    public const BACKUP_VERIFIED = 'backup_verified';

    /** Package safely extracted to protected staging + staged checksums verified (§22). */
    public const STAGED = 'staged';

    /** Maintenance mode entered (§23). */
    public const MAINTENANCE = 'maintenance';

    /** Replace-not-merge file promotion + stale Core removal in progress (§25/§35). */
    public const REPLACING = 'replacing';

    /** Core migrations running (§37). */
    public const MIGRATING = 'migrating';

    /** Cache clear/rebuild (§38). */
    public const CACHE_REFRESH = 'cache_refresh';

    /** Post-apply health checks (§39/§40). */
    public const HEALTH_CHECK = 'health_check';

    /** Upgrade finished successfully. Terminal. */
    public const COMPLETED = 'completed';

    // ---- recovery / terminal-failure states ----------------------------
    /** Automatic recovery (file + optional DB restore) in progress (§41/§42). */
    public const ROLLBACK = 'rollback';

    /** Terminal failure. `failure_stage` records where; recovery state is explicit. */
    public const FAILED = 'failed';

    /**
     * Legal forward transitions. A key maps to every status it may move to.
     * Every dangerous stage may also branch to FAILED (recorded) or ROLLBACK.
     * Absence of a (from → to) pair means the transition is ILLEGAL and must be
     * rejected (§7 idempotency: a duplicate POST cannot advance a settled state).
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::UPLOADED         => [self::VERIFIED, self::FAILED],
        self::VERIFIED         => [self::PREFLIGHT_PASSED, self::FAILED],
        self::PREFLIGHT_PASSED => [self::BACKUP_STARTED, self::FAILED],
        self::BACKUP_STARTED   => [self::BACKUP_VERIFIED, self::FAILED],
        self::BACKUP_VERIFIED  => [self::STAGED, self::FAILED],
        self::STAGED           => [self::MAINTENANCE, self::FAILED],
        self::MAINTENANCE      => [self::REPLACING, self::ROLLBACK, self::FAILED],
        self::REPLACING        => [self::MIGRATING, self::ROLLBACK, self::FAILED],
        self::MIGRATING        => [self::CACHE_REFRESH, self::ROLLBACK, self::FAILED],
        self::CACHE_REFRESH    => [self::HEALTH_CHECK, self::ROLLBACK, self::FAILED],
        self::HEALTH_CHECK     => [self::COMPLETED, self::ROLLBACK, self::FAILED],
        self::ROLLBACK         => [self::FAILED],
        self::COMPLETED        => [],
        self::FAILED           => [],
    ];

    /** All known statuses, happy-path order first. */
    public const ALL = [
        self::UPLOADED, self::VERIFIED, self::PREFLIGHT_PASSED,
        self::BACKUP_STARTED, self::BACKUP_VERIFIED, self::STAGED,
        self::MAINTENANCE, self::REPLACING, self::MIGRATING,
        self::CACHE_REFRESH, self::HEALTH_CHECK, self::COMPLETED,
        self::ROLLBACK, self::FAILED,
    ];

    public static function isKnown(string $status): bool
    {
        return \in_array($status, self::ALL, true);
    }

    /** True when no further transition is possible (completed or failed). */
    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? null) === [];
    }

    /**
     * True once the certified backup gate is cleared — i.e. Start Upgrade may run.
     * Everything from STAGED onward is post-backup-verified (§20).
     */
    public static function hasVerifiedBackup(string $status): bool
    {
        return \in_array($status, [
            self::BACKUP_VERIFIED, self::STAGED, self::MAINTENANCE, self::REPLACING,
            self::MIGRATING, self::CACHE_REFRESH, self::HEALTH_CHECK, self::COMPLETED,
        ], true);
    }

    /**
     * True when live application state may already have been mutated (§43): once
     * REPLACING begins, a failure is post-mutation and requires recovery, never a
     * silent restart.
     */
    public static function hasMutatedLiveState(string $status): bool
    {
        return \in_array($status, [
            self::REPLACING, self::MIGRATING, self::CACHE_REFRESH,
            self::HEALTH_CHECK, self::ROLLBACK, self::COMPLETED,
        ], true);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return \in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws \InvalidArgumentException on an illegal transition (§7). */
    public static function assertTransition(string $from, string $to): void
    {
        if (! self::isKnown($from) || ! self::isKnown($to)) {
            throw new \InvalidArgumentException("unknown upgrade status: $from → $to");
        }
        if (! self::canTransition($from, $to)) {
            throw new \InvalidArgumentException("illegal upgrade transition: $from → $to");
        }
    }
}
