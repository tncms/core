<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Core upgrade orchestrator (CORE-UPGRADE-1, Steps 4–6).
 *
 * The single `cms.upgrade` authority. It drives the durable state machine +
 * concurrency lock through the whole workflow — verify package → preflight →
 * backup → VERIFY BACKUP (hard gate) → stage → maintenance → replace-not-merge
 * + stale removal → migrations → cache → health → finish, or certified recovery
 * on failure — reusing the existing Core authorities (InstallerManager,
 * MaintenanceManager, DatabaseBackup) and the phase's engine classes.
 *
 * Every step is transactional at the state level: a step either advances the
 * persisted status or records a failure; a duplicate/illegal call is rejected by
 * the state machine (§7). Nothing here logs a secret (§45/§50).
 *
 * Root + collaborators are injectable so the engine is testable on a temp fixture
 * tree without booting a real site or MySQL.
 */
class UpgradeManager
{
    /**
     * Marks an engine whose storageUsage(), retryCleanup() and purgeAttempt() are
     * safe to run from the bridge without a package: cleanup takes the upgrade
     * lock and the attempt's recovery guard (P0-R3). Older engines lack it.
     */
    public const PACKAGE_FREE_MAINTENANCE = 1;

    /**
     * The per-attempt recovery guard gets no heartbeat (a database restore is one long call), so it is
     * considered stale only after this long; a crashed recovery's guard file may be deleted by the operator.
     */
    private const GUARD_STALE_AFTER = 6 * 3600;

    /** OS errors PHP reports for a full volume or an exhausted quota (ENOSPC = 28, EDQUOT = 122). */
    private const NO_SPACE = '/\berrno=(28|122)\b|No space left on device|Disk quota exceeded|not enough space on the disk/i';

    private const CLEANUP_DISK_FULL = 'the disk is full, so the cleanup could not record its progress. Free a few MB of disk space '
        .'(never delete the upgrade, backup or site folders), then retry the cleanup. The upgrade itself is complete';

    private const LOCK_NOT_WRITTEN = 'the upgrade lock could not be written (the disk may be full); free some space, then retry';

    private string $root;

    private UpgradeStateStore $store;

    private UpgradeLock $lock;

    /** @var callable():PDO */
    private $pdoResolver;

    /** @var array<string,mixed>|null per-instance manifest cache */
    private ?array $manifestCache = null;


    public function __construct(
        ?string $root = null,
        ?UpgradeStateStore $store = null,
        ?UpgradeLock $lock = null,
        ?callable $pdoResolver = null,
    ) {
        $base = $root ?? (\function_exists('base_path') ? base_path() : getcwd());
        $this->root = rtrim(str_replace('\\', '/', (string) $base), '/');
        $storageRoot = $this->root.'/storage/app/upgrades';
        $this->store = $store ?? new UpgradeStateStore($storageRoot);
        $this->lock = $lock ?? new UpgradeLock($storageRoot);
        $this->pdoResolver = $pdoResolver ?? static fn (): PDO => \Illuminate\Support\Facades\DB::connection()->getPdo();
    }

    // ---- accessors ------------------------------------------------------

    public function root(): string
    {
        return $this->root;
    }

    public function store(): UpgradeStateStore
    {
        return $this->store;
    }

    public function lock(): UpgradeLock
    {
        return $this->lock;
    }

    public function currentVersion(): string
    {
        return \class_exists(\TheNguyen\CMS\Support\CmsInfo::class)
            ? \TheNguyen\CMS\Support\CmsInfo::VERSION
            : $this->readVersionOnDisk();
    }

    public function isInstalled(): bool
    {
        try {
            return \function_exists('app') && app('cms.installer')->isInstalled();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null the in-flight or interrupted attempt (§44) */
    public function active(): ?array
    {
        return $this->store->active();
    }

    /** @return array<string,mixed>|null */
    public function latest(): ?array
    {
        return $this->store->latest();
    }

    /**
     * The Core ownership MODEL. On a real installed site the model travels in the
     * shipped ownership record (tncms-core.manifest.json['ownership']); the dev
     * monorepo's tools/distribution/manifest.php is only a fallback (that file is
     * NOT part of an install/upgrade package).
     */
    public function ownershipModel(): array
    {
        $recorded = $this->recordedCoreManifest();
        if (\is_array($recorded['ownership'] ?? null) && $recorded['ownership'] !== []) {
            return $recorded['ownership'];
        }

        return $this->manifest()['core_ownership'] ?? [];
    }

    public function packagePath(string $id): string
    {
        return $this->store->dir($id).'/package.zip';
    }

    public function stagingDir(string $id): string
    {
        return $this->store->dir($id).'/staging';
    }

    public function backupDir(string $id): string
    {
        return $this->store->dir($id).'/backup';
    }

    // ---- 1. receive + verify package -----------------------------------

    /**
     * Store an uploaded package under protected storage and open a new attempt.
     * Identity is fresh (never derived from the filename).
     *
     * @return array{id:string, state:array<string,mixed>}
     */
    public function receivePackage(string $sourceZipPath, callable $mover): array
    {
        $id = UpgradeId::generate();
        $state = $this->store->create($id, [
            'source_version' => $this->currentVersion(),
            'original_name' => basename($sourceZipPath),
        ]);
        $dest = $this->packagePath($id);
        $dir = \dirname($dest);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $mover($dest); // move_uploaded_file / rename, supplied by the caller

        $this->store->patch($id, [
            'package_sha256' => is_file($dest) ? hash_file('sha256', $dest) : null,
        ]);

        return ['id' => $id, 'state' => $this->store->load($id) ?? $state];
    }

    /**
     * Verify the stored package (§14). Advances to VERIFIED or FAILED.
     *
     * @return array<string,mixed> the verify result
     */
    public function verifyPackage(string $id): array
    {
        $pkg = new UpgradePackage($this->packagePath($id));
        $result = $pkg->verify($this->currentVersion());

        if ($result['ok']) {
            $this->store->transition($id, UpgradeState::VERIFIED, 'Package verified', [
                'target_version' => $result['target_version'],
                'min_source' => $result['min_source'],
                'has_migrations' => $result['has_migrations'],
            ]);
        } else {
            $this->failIfPossible($id, 'Package verification failed: '.$result['reason']);
        }

        return $result;
    }

    // ---- 2. preflight ---------------------------------------------------

    /**
     * System check (§15/§16). Advances to PREFLIGHT_PASSED only when every
     * required check passes; otherwise leaves the state as VERIFIED so the admin
     * can fix the environment and re-run.
     *
     * @return array{ok:bool, checks:array<int,array{name:string,ok:bool,required:bool,detail:string}>}
     */
    public function preflight(string $id): array
    {
        $state = $this->store->load($id) ?? throw new \RuntimeException("no upgrade: $id");
        $checks = $this->preflightChecks($state);
        $ok = true;
        foreach ($checks as $c) {
            if ($c['required'] && ! $c['ok']) {
                $ok = false;
            }
        }

        if ($ok) {
            $current = (string) ($state['status'] ?? '');
            if ($current === UpgradeState::VERIFIED) {
                $this->store->transition($id, UpgradeState::PREFLIGHT_PASSED, 'System check passed');
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @param  array<string,mixed>  $state
     * @return array<int,array{name:string,ok:bool,required:bool,detail:string}>
     */
    public function preflightChecks(array $state): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, bool $required, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };

        $add('installed', $this->isInstalled(), true, 'site is installed');

        $current = $this->currentVersion();
        $target = (string) ($state['target_version'] ?? '');
        $add('current-version', $current !== '', true, $current);
        $add('target-newer', $target !== '' && version_compare($target, $current, '>'), true, "current=$current target=$target");

        $pkg = $state['php_requirement'] ?? '8.3.0';
        $add('php-version', version_compare(PHP_VERSION, (string) $pkg, '>='), true, PHP_VERSION);

        $add('db-connection', $this->databaseReachable(), true, 'database connectivity');

        // Writable: storage upgrades root, every replace root parent + replace files' dirs.
        $model = $this->ownershipModel();
        $writeTargets = [$this->store->root()];
        foreach ($model['replace_dirs'] ?? [] as $d) {
            $writeTargets[] = \dirname($this->root.'/'.rtrim(CoreOwnership::normalize($d), '/'));
        }
        foreach ($model['replace_files'] ?? [] as $f) {
            $writeTargets[] = \dirname($this->root.'/'.CoreOwnership::normalize($f));
        }
        $unwritable = [];
        foreach (array_unique($writeTargets) as $t) {
            if (is_dir($t) && ! is_writable($t)) {
                $unwritable[] = CoreOwnership::normalize(str_replace($this->root.'/', '', $t));
            }
        }
        $add('writable-roots', $unwritable === [], true, implode(', ', \array_slice($unwritable, 0, 8)));

        $blocking = $this->blockingRecovery((string) ($state['upgrade_id'] ?? ''));
        $add('no-unresolved-recovery', $blocking === null, true, $blocking === null ? '' : "upgrade $blocking requires recovery first");

        $add('lock-available',! $this->lock->isLocked() || $this->lock->isOwnedBy((string) $state['upgrade_id']) || $this->lock->isStale(), true, 'upgrade lock');

        // Storage (CORE-UPGRADE-DISK-QUOTA-P0-R1): per-filesystem budget derived from the package's
        // central directory, live Core bytes and two database dumps. UNKNOWN blocks like INSUFFICIENT.
        $capacity = $this->assessCapacity((string) ($state['upgrade_id'] ?? ''), $model, null);
        $add('storage-capacity', $capacity['state'] === StorageCapacity::ENOUGH, true, $capacity['detail']);

        $same = StorageCapacity::sameDevice($this->root, $this->store->root());
        $add('storage-same-volume', $same === true, true, $same === null
            ? 'cannot determine whether the site and its upgrade storage share a volume'
            : ($same ? 'site and upgrade storage share one volume' : 'upgrade storage is on another volume (cross_device): Core files cannot be promoted by rename'));

        $dumpable = $this->databaseIsDumpable();
        $migrations = (bool) ($state['has_migrations'] ?? false);
        if ($dumpable) {
            $triggers = $this->contained(fn () => $this->databaseBackup()->triggerTables(($this->pdoResolver)()), null);
            $add('database-snapshot-restorable', $triggers === [], true, $triggers === []
                ? 'no triggers'
                : ($triggers === null ? 'the database schema could not be inspected' : \count($triggers).' table(s) have triggers; automatic rollback would discard them'));
            $dbBytes = $this->contained(fn () => $this->databaseBackup()->estimateBytes(($this->pdoResolver)()), null);
            $limit = $this->memoryLimitBytes();
            $memoryOk = $limit === null || ($dbBytes !== null && 3 * $dbBytes + 64 * 1048576 <= $limit);
            $add('snapshot-memory', $memoryOk, true, $memoryOk
                ? 'memory_limit can hold a verified snapshot during rollback'
                : 'memory_limit is too small to verify and restore the database snapshot (needs about 3x the database size)');
        }
        $add('database-rollback-supported', $dumpable || ! $migrations || ! $this->databaseReachable(), true, $dumpable
            ? 'MySQL/MariaDB snapshot rollback available'
            : ($migrations ? 'this package runs migrations, but automatic database rollback supports MySQL/MariaDB only' : 'no migrations in this package'));

        $add('package-verified', UpgradeState::hasVerifiedBackup((string) $state['status']) || \in_array($state['status'] ?? '', [UpgradeState::VERIFIED, UpgradeState::PREFLIGHT_PASSED], true), true, (string) ($state['status'] ?? ''));

        return $checks;
    }

    // ---- 3. backup + hard verification gate -----------------------------

    /**
     * Create the full pre-upgrade backup (DB + Core files + .env) and VERIFY it
     * (§17–§21). Advances PREFLIGHT_PASSED → BACKUP_STARTED → BACKUP_VERIFIED, or
     * FAILED. Only a BACKUP_VERIFIED state may proceed to apply (§20).
     *
     * @return array{ok:bool, reason:string, refs:array<string,mixed>}
     */
    public function runBackup(string $id): array
    {
        $state = $this->store->load($id) ?? throw new \RuntimeException("no upgrade: $id");
        // Nothing is written until the system check (including storage capacity) has passed.
        if (! \in_array($state['status'] ?? '', [UpgradeState::PREFLIGHT_PASSED, UpgradeState::BACKUP_STARTED], true)) {
            return ['ok' => false, 'reason' => 'run the system check first; the backup starts only after it has passed', 'refs' => []];
        }
        if (! $this->lock->acquire($id)) {
            return ['ok' => false, 'reason' => 'another upgrade is already running', 'refs' => []];
        }

        if (($state['status'] ?? '') === UpgradeState::PREFLIGHT_PASSED) {
            $this->store->transition($id, UpgradeState::BACKUP_STARTED, 'Backup started');
        }

        $backupDir = $this->backupDir($id);
        $model = $this->ownershipModel();

        try {
            // Database (PDO-native, no shell). Skipped only when no DB is usable.
            $dbRefs = $this->backupDatabase($backupDir);
            // Files + .env.
            $backup = new CoreBackup($this->root);
            $fileRefs = $backup->backup($backupDir, $model);
            $refs = array_merge($fileRefs, $dbRefs);

            $verify = $this->verifyBackup($refs);
            if (! $verify['ok']) {
                $this->failIfPossible($id, 'Backup verification failed: '.$verify['reason']);

                return ['ok' => false, 'reason' => $verify['reason'], 'refs' => $refs];
            }

            $this->store->transition($id, UpgradeState::BACKUP_VERIFIED, 'Backup created and verified', [
                'backup' => $this->secretSafeRefs($refs),
            ]);

            return ['ok' => true, 'reason' => 'ok', 'refs' => $refs];
        } catch (Throwable $e) {
            $safe = $e instanceof \PDOException ? 'database error' : $this->safeMessage($e->getMessage());
            $this->failIfPossible($id, 'Backup failed: '.$safe);

            return ['ok' => false, 'reason' => $safe, 'refs' => []];
        }
    }

    /**
     * @return array{db_file:?string, db_sha256:?string, db_manifest_sha256:?string, db_tables:int, db_rows:int, db_bytes:int}
     */
    public function backupDatabase(string $backupDir): array
    {
        if (! $this->databaseIsDumpable()) {
            return ['db_file' => null, 'db_sha256' => null, 'db_manifest_sha256' => null, 'db_tables' => 0, 'db_rows' => 0, 'db_bytes' => 0];
        }
        $file = rtrim($backupDir, '/').'/database.sql';
        $pdo = ($this->pdoResolver)();
        $meta = $this->databaseBackup()->dump($pdo, $file);
        if (! $meta['restorable']) {
            throw new \RuntimeException('the database cannot be rolled back automatically ('.$meta['not_restorable_reason'].')');
        }

        return [
            'db_file' => $file,
            'db_sha256' => $meta['sha256'],
            'db_manifest_sha256' => $meta['manifest_sha256'],
            'db_tables' => $meta['tables'],
            'db_rows' => $meta['rows'],
            'db_bytes' => $meta['bytes'],
        ];
    }

    /**
     * Hard server-side backup verification (§20). DB (when present) is structurally
     * verified via DatabaseBackup; files + .env via CoreBackup.
     *
     * @param  array<string,mixed>  $refs
     * @return array{ok:bool, reason:string}
     */
    public function verifyBackup(array $refs): array
    {
        $files = (new CoreBackup($this->root))->verify($refs);
        if (! $files['ok']) {
            return $files;
        }
        $dbFile = $refs['db_file'] ?? null;
        if ($dbFile !== null) {
            if ((string) ($refs['db_sha256'] ?? '') === '' || (string) ($refs['db_manifest_sha256'] ?? '') === '') {
                return ['ok' => false, 'reason' => 'database backup invalid: not pinned'];
            }
            $db = $this->databaseBackup()->verify((string) $dbFile, (string) $refs['db_sha256'], (string) $refs['db_manifest_sha256']);
            if (! $db['ok']) {
                return ['ok' => false, 'reason' => 'database backup invalid: '.$db['reason']];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    // ---- 4. apply (stage → plan → maintenance → snapshot → promote → migrate → cache → health → commit) --

    /**
     * Execute the upgrade. Rejects unless a verified backup exists (§20/§7), no
     * earlier attempt is awaiting recovery, and this attempt owns the lock.
     *
     * Core files are promoted by a journaled per-file {@see UpgradeTransaction}
     * (CORE-UPGRADE-WIN-R1). The recorded ownership record is written only at the
     * commit point, after migrations, health and a full promoted-file
     * verification all pass; the transaction's preserved copies are kept until
     * then. Any failure triggers {@see recover()}, which reports a VERIFIED
     * outcome — never `recovered=true` from unchecked restore calls.
     *
     * @return array{ok:bool, reason:string, health?:array<string,mixed>, recovered?:bool, outcome?:string}
     */
    public function apply(string $id): array
    {
        $state = $this->store->load($id) ?? throw new \RuntimeException("no upgrade: $id");
        $status = (string) ($state['status'] ?? '');

        if (! UpgradeState::hasVerifiedBackup($status)) {
            return ['ok' => false, 'reason' => 'a verified backup is required before starting the upgrade'];
        }
        if (UpgradeState::hasMutatedLiveState($status) || $status === UpgradeState::COMPLETED) {
            return ['ok' => false, 'reason' => 'this upgrade has already been applied'];
        }
        if (($blocking = $this->blockingRecovery($id)) !== null) {
            return ['ok' => false, 'reason' => 'upgrade '.$blocking.' still requires recovery; recover it before upgrading again'];
        }
        if (! $this->lock->acquire($id)) {
            return ['ok' => false, 'reason' => 'another upgrade is already running'];
        }
        $this->lock->touch($id); // a heartbeat marks the apply as running (discard() checks it)
        $beat = strtotime((string) ($this->lock->owner()['heartbeat_at'] ?? '')) ?: 0;
        if (time() - $beat > 60) { // missing, or an old one left by an earlier crashed apply
            // Without the mark a discard could pull the lock from under this apply: stop before changing anything.
            return ['ok' => false, 'reason' => self::LOCK_NOT_WRITTEN];
        }
        if (\function_exists('ignore_user_abort')) {
            @ignore_user_abort(true); // a closed browser tab must not abandon a half-promoted tree
        }
        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Everything the mutation and recovery paths need is loaded BEFORE the live tree changes.
        UpgradeTransaction::preload();
        foreach ([CoreBackup::class, DatabaseBackup::class, UpgradeHealthCheck::class, UpgradeState::class] as $class) {
            class_exists($class);
        }

        $model = $this->ownershipModel();
        $target = (string) ($state['target_version'] ?? '');

        try {
            // Stage (safe extraction + staged checksum verify) if not yet staged.
            if ($status === UpgradeState::BACKUP_VERIFIED) {
                $pkg = new UpgradePackage($this->packagePath($id));
                $staged = $pkg->stageTo($this->stagingDir($id));
                if (! $staged['ok']) {
                    $this->failIfPossible($id, 'Staging failed: '.$staged['reason']);

                    return ['ok' => false, 'reason' => $staged['reason']];
                }
                $this->store->transition($id, UpgradeState::STAGED, 'Package staged', [
                    'staged_files' => $staged['files'],
                ]);
            }

            // Plan the per-file transaction (validation only — no live mutation).
            // The target's shipped ownership record is authoritative for the new model + owned-file map.
            $packageCore = $this->packageCoreManifest($id);
            $newOwned = \is_array($packageCore['files'] ?? null) ? $packageCore['files'] : [];
            $applyModel = (\is_array($packageCore['ownership'] ?? null) && $packageCore['ownership'] !== [])
                ? $packageCore['ownership'] : $model;
            $txn = $this->transaction($id);
            try {
                $plan = $txn->plan($this->stagingDir($id), $applyModel, $this->recordedOwnership(), $newOwned);
            } catch (UpgradeTransactionException $e) {
                $this->safeTransition($id, UpgradeState::FAILED, $e->getMessage(), [
                    'failure_stage' => UpgradeState::STAGED,
                    'failure_reason' => $e->getMessage(),
                    'outcome' => UpgradeOutcome::REJECTED,
                    'recovered' => true,
                    'recovery_notes' => ['no files or data were changed'],
                ]);
                $this->lock->release($id);

                return ['ok' => false, 'reason' => $e->getMessage(), 'recovered' => true, 'outcome' => UpgradeOutcome::REJECTED];
            }
            // Runtime capacity: staging is on disk now; what remains is the snapshot (sized by
            // the verified backup dump), metadata, one fallback copy and headroom.
            $remaining = $this->assessCapacity($id, $applyModel, (int) (($this->store->load($id)['backup']['db_bytes'] ?? 0)));
            if ($remaining['state'] !== StorageCapacity::ENOUGH) {
                return $this->reject($id, 'Not enough verified free space to continue safely ('.$remaining['detail'].')');
            }
            $dumpBytes = (int) (($this->store->load($id)['backup']['db_bytes'] ?? 0));
            $limit = $this->memoryLimitBytes();
            if ($limit !== null && 3 * $dumpBytes + 64 * 1048576 > $limit) {
                return $this->reject($id, 'memory_limit is too small to verify and restore the database snapshot if the upgrade fails');
            }
            $this->preserveRecordedManifest($id);

            // Maintenance ON (only after everything verified, §23).
            $this->enterMaintenance();
            $this->store->transition($id, UpgradeState::MAINTENANCE, 'Maintenance mode enabled');

            // Transaction database snapshot, taken INSIDE maintenance so a rollback
            // cannot discard writes made between the backup step and this moment.
            // An unprovable snapshot rejects the upgrade before anything is mutated.
            try {
                $this->snapshotDatabase($id);
            } catch (Throwable $e) {
                $this->exitMaintenance();
                $this->writeDiagnostics($id, ['reason' => 'database snapshot failed', 'detail' => $this->safeMessage($e->getMessage()),
                    'storage_reason' => $e instanceof UpgradeStorageException ? $e->reason : null]);

                return $this->reject($id, 'The database snapshot could not be completed and verified (see the upgrade diagnostics).');
            }
            $this->lock->touch($id);

            $this->store->transition($id, UpgradeState::REPLACING, 'Replacing Core release state', ['plan' => $plan['counts']]);
            $stats = $txn->execute($plan);
            $this->store->patch($id, ['applied' => $plan['counts'] + $stats]);

            // Migrations (§37). The journal marks the point after which the database must be restored;
            // the inventory recorded here is the provenance for tables a failed migration creates.
            $this->recordPreMigrationInventory($id);
            $txn->journal()->append('migrating');
            $this->store->transition($id, UpgradeState::MIGRATING, 'Running migrations');
            $this->lock->touch($id);
            $this->runMigrations();

            // Cache lifecycle + Core asset regeneration (§38).
            $this->store->transition($id, UpgradeState::CACHE_REFRESH, 'Refreshing caches');
            $this->refreshCaches($model);

            // Health (§39/§40).
            $this->store->transition($id, UpgradeState::HEALTH_CHECK, 'Running health checks');
            $health = (new UpgradeHealthCheck($this->root))->run($target, $this->healthChecksDatabase());
            if (! $health['ok']) {
                return $this->recover($id, 'health check failed', $health);
            }

            // Commit gate: every promoted / removed file, then the ownership record.
            if (($problems = $txn->verifyCommitted($plan)) !== []) {
                return $this->recover($id, 'the promoted Core files could not be verified', $health);
            }
            $this->writeRecordedOwnership($id, $applyModel, $newOwned, $target);
            $txn->journal()->append('committed', ['version' => $target]);
            $cleanup = $txn->finalize($plan, $applyModel['replace_dirs'] ?? []);
            $cleanup = $this->mergeCleanup($cleanup, $this->removeTransientArtifacts($id, true));

            // Finish.
            $this->exitMaintenance();
            $this->store->transition($id, UpgradeState::COMPLETED, 'Upgrade completed', [
                'health' => $health,
                'outcome' => UpgradeOutcome::COMMITTED_VERIFIED,
                'cleanup' => $cleanup,
            ]);
            $this->lock->release($id);

            return ['ok' => true, 'reason' => 'ok', 'health' => $health, 'outcome' => UpgradeOutcome::COMMITTED_VERIFIED];
        } catch (UpgradeTransactionException $e) {
            return $this->recover($id, $e->getMessage(), null, $e->diagnostics);
        } catch (Throwable $e) {
            return $this->recover($id, $this->safeMessage($e->getMessage()), null);
        }
    }

    // ---- 5. rollback / recovery ----------------------------------------

    /**
     * Recovery after a failure (§41/§42), or of an interrupted transaction.
     *
     * After the COMMIT POINT (journal `committed`) an upgrade is never rolled
     * back — the site may already be serving and writing — so recovery rolls
     * FORWARD: it re-verifies the promoted files and finishes the commit.
     * Before it:
     *  1. journal-driven rollback of every planned Core file (idempotent);
     *  2. the transaction's database snapshot is restored — only when migrations
     *     had started, only once, and only while the site never left
     *     maintenance — and its tables + row checksums are verified;
     *  3. every Core-owned file is reconciled to the plan-time baseline under the
     *     plan-time ownership model (this also undoes cache/asset regeneration);
     *  4. the recorded ownership record is restored byte-for-byte.
     * Each stage is contained: an unexpected exception becomes a problem, never
     * an escape. `recovered` is true ONLY when all of it verified
     * (ROLLED_BACK_VERIFIED) — then maintenance is lifted. Otherwise the attempt
     * is RECOVERY_REQUIRED: the site STAYS in maintenance and further upgrades
     * are blocked until a retry verifies.
     *
     * @param  array<string,mixed>|null  $health
     * @param  array<string,mixed>  $diagnostics
     * @return array{ok:bool, reason:string, recovered:bool, outcome:string, health?:array<string,mixed>}
     */
    public function recover(string $id, string $reason, ?array $health, array $diagnostics = []): array
    {
        UpgradeTransaction::preload();
        $state = $this->store->load($id) ?? [];
        if (! $this->transaction($id)->journal()->isSafe()) {
            return $this->refuseUnsafeJournal($id, $state, $reason);
        }
        try {
            return $this->recoverWithSafeJournal($id, $state, $reason, $health, $diagnostics);
        } catch (RuntimeException $e) {
            if (! \in_array($e->getMessage(), [UpgradeJournal::UNSAFE, UpgradeJournal::UNREADABLE], true)) {
                throw $e;
            }

            // The journal became a link, or could no longer be opened, while recovery ran: stop, and record it as unverified.
            return $this->refuseUnsafeJournal($id, $this->store->load($id) ?? $state, $reason, $e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>|null  $health
     * @param  array<string,mixed>  $diagnostics
     * @return array{ok:bool, reason:string, recovered:bool, outcome:string, health?:array<string,mixed>}
     */
    private function recoverWithSafeJournal(string $id, array $state, string $reason, ?array $health, array $diagnostics): array
    {
        $status = (string) ($state['status'] ?? '');
        $txn = $this->transaction($id);
        if ($txn->journal()->has('committed')) {
            return $this->rollForward($id, $txn, $state);
        }

        $problems = [];
        $notes = [];
        $neverMutated = $state !== [] && ! UpgradeState::hasMutatedLiveState($status) && ! $txn->journal()->has('begin');
        $earlyCleanup = null;
        if ($neverMutated) {
            // Nothing live was touched (e.g. staging hit a full disk). Give the space back FIRST, so the
            // outcome can be recorded and the site keeps working; the verified backup is kept.
            $earlyCleanup = $this->removeTransientArtifacts($id, false);
        }
        if ($state === [] && ! $txn->journal()->has('begin')) {
            // No readable state and no evidence of promotion: prove the live Core tree equals the backup.
            $problems = $this->contained(fn () => $this->compareWithBackup($id), [['rel' => '*', 'reason' => 'live files could not be compared with the backup']]);
            $notes[] = $problems === [] ? 'Core files match the verified backup' : 'Core files could not be proven unchanged';
        }
        if (UpgradeState::hasMutatedLiveState($status) || $txn->journal()->has('begin')) {
            $this->safeTransition($id, UpgradeState::ROLLBACK, 'Recovering: '.$reason);
            $retry = $txn->journal()->has('rollback_end');
            $files = $this->contained(fn () => $txn->rollback(), ['outcome' => UpgradeOutcome::RECOVERY_REQUIRED, 'problems' => [['rel' => '*', 'reason' => 'transaction rollback failed unexpectedly']]]);

            if ($txn->journal()->has('migrating') && ! $txn->journal()->has('db_restored')) {
                $db = $this->contained(fn () => $this->restoreDatabaseVerified($id, $retry), ['note' => 'database restore failed', 'problems' => [['rel' => 'database', 'reason' => 'restore failed unexpectedly']]]);
                $notes[] = $db['note'];
                $problems = array_merge($problems, $db['problems']);
                if ($db['problems'] === [] && ($db['restored'] ?? false)) {
                    $this->contained(fn () => $txn->journal()->append('db_restored'), null);
                }
            }

            // The full-inventory reconcile under the PLAN-TIME model is the authority for
            // Core files: a per-file rollback problem it demonstrably repaired does not count.
            $baseline = $txn->baseline();
            if ($baseline !== []) {
                $model = $txn->plannedModel() ?? $this->ownershipModel();
                $reconcile = $this->contained(
                    fn () => (new CoreBackup($this->root))->reconcile($baseline, $this->backupDir($id).'/core-files.zip', $model),
                    ['restored' => 0, 'removed' => 0, 'problems' => [['rel' => '*', 'reason' => 'reconcile failed unexpectedly']]],
                );
                $problems = array_merge($problems, $reconcile['problems']);
                $notes[] = $reconcile['problems'] === [] ? 'Core files restored and verified' : 'Core files could not all be restored';
                if ($reconcile['restored'] + $reconcile['removed'] > 0) {
                    $notes[] = "files changed outside the transaction reconciled ({$reconcile['restored']} restored, {$reconcile['removed']} removed)";
                }
                $diagnostics['transaction_rollback_problems'] = $files['problems'];
            } else {
                if ($txn->journal()->has('begin')) {
                    $files['problems'][] = ['rel' => '*', 'reason' => 'plan-time baseline missing; the full Core inventory could not be verified'];
                }
                $problems = array_merge($problems, $files['problems']);
                $notes[] = $files['problems'] === [] ? 'Core files restored and verified' : 'Core files could not all be restored';
            }

            if (! $this->contained(fn () => $this->restoreRecordedManifest($id), false)) {
                $problems[] = ['rel' => self::CORE_MANIFEST_FILE, 'reason' => 'ownership record not restored'];
            }
        }

        $outcome = $problems === [] ? UpgradeOutcome::ROLLED_BACK_VERIFIED : UpgradeOutcome::RECOVERY_REQUIRED;
        $recovered = $outcome === UpgradeOutcome::ROLLED_BACK_VERIFIED;
        if (! $recovered) {
            $notes[] = 'the site remains in maintenance mode until recovery is verified';
        }
        $fields = [
            // Record the stage where the failure actually occurred, not ROLLBACK.
            'failure_stage' => $state['failure_stage'] ?? ($status === UpgradeState::ROLLBACK ? UpgradeState::REPLACING : $status),
            'failure_reason' => $state['failure_reason'] ?? $this->safeMessage($reason),
            'recovered' => $recovered,
            'outcome' => $outcome,
            'recovery_notes' => $notes,
            'recovery_problems' => \count($problems),
            'health' => $health,
        ];
        if ($earlyCleanup !== null) {
            $fields['cleanup'] = $earlyCleanup;
        }
        $this->contained(function () use ($id, $status, $fields, $reason, $notes, $state): void {
            if ($state === []) {
                $this->store->rebuild($id, $fields); // the record was unreadable; write a fresh one
            } elseif (UpgradeState::isTerminal($status)) {
                $this->store->patch($id, $fields + ['recovery_attempted_at' => gmdate('c')]);
            } else {
                $this->safeTransition($id, UpgradeState::FAILED, $this->safeMessage($reason).' — recovery: '.implode('; ', $notes), $fields);
            }
        }, null);
        // Proven by reading it back: a write that failed silently must not count.
        $persisted = (($this->store->load($id) ?? [])['outcome'] ?? null) === $outcome;
        if ($recovered && ! $persisted) {
            // The verified outcome could not be recorded: never report it as recovered.
            $recovered = false;
            $outcome = UpgradeOutcome::RECOVERY_REQUIRED;
            $problems[] = ['rel' => 'state', 'reason' => 'the recovery outcome could not be recorded'];
        }
        if ($recovered) {
            $this->exitMaintenance(); // only once the verified outcome is durably recorded
            $this->contained(fn () => $this->store->patch($id, ['cleanup' => $this->removeTransientArtifacts($id, true)]), null);
        }
        $this->writeDiagnostics($id, ['reason' => $this->safeMessage($reason), 'outcome' => $outcome, 'problems' => $problems] + $diagnostics);
        $this->lock->release($id);

        return ['ok' => false, 'reason' => $this->safeMessage($reason), 'recovered' => $recovered, 'outcome' => $outcome, 'health' => $health];
    }

    /**
     * A journal behind a link (or with a second name) is never read or written: its
     * records could be forged ("committed") or redirect writes outside the attempt.
     * The attempt is recorded RECOVERY_REQUIRED, the site stays in maintenance if
     * it was, and new upgrades stay blocked until an operator removes the link.
     *
     * @param  array<string,mixed>  $state
     * @return array{ok:bool, reason:string, recovered:bool, outcome:string, health:null}
     */
    private function refuseUnsafeJournal(string $id, array $state, string $reason, string $why = UpgradeJournal::UNSAFE): array
    {
        $status = (string) ($state['status'] ?? '');
        $note = $why === UpgradeJournal::UNREADABLE
            ? 'the upgrade journal could not be opened (another program may hold it); close it, then retry recovery'
            : 'the upgrade journal is behind a link or has another name; it was not read or written. Remove the link, then retry recovery';
        if (UpgradeState::isTerminal($status)
            && \in_array($state['outcome'] ?? null, [UpgradeOutcome::ROLLED_BACK_VERIFIED, UpgradeOutcome::COMMITTED_VERIFIED], true)) {
            // Already verified (e.g. a backup tool later hard-linked the journal): keep the verified record.
            $this->lock->release($id);

            return ['ok' => false, 'reason' => $note.' (the verified outcome on record is kept)', 'recovered' => false, 'outcome' => UpgradeOutcome::RECOVERY_REQUIRED, 'health' => null];
        }
        $fields = [
            'failure_stage' => $state['failure_stage'] ?? $status,
            'failure_reason' => $state['failure_reason'] ?? $this->safeMessage($reason),
            'recovered' => false,
            'outcome' => UpgradeOutcome::RECOVERY_REQUIRED,
            'recovery_notes' => [$note],
            'recovery_problems' => 1,
        ];
        $this->contained(function () use ($id, $state, $status, $fields): void {
            if ($state === []) {
                $this->store->rebuild($id, $fields);
            } elseif (UpgradeState::isTerminal($status)) {
                $this->store->patch($id, $fields + ['recovery_attempted_at' => gmdate('c')]);
            } else {
                // Recorded, but never moved to a terminal status: the journal could not be read, so it is unknown
                // whether the commit point was passed, and a later retry must still be able to roll forward.
                $this->store->patch($id, $fields + ['recovery_attempted_at' => gmdate('c')]);
            }
        }, null);
        $this->writeDiagnostics($id, ['reason' => $this->safeMessage($reason), 'outcome' => UpgradeOutcome::RECOVERY_REQUIRED,
            'problems' => [['rel' => 'txn/journal.jsonl', 'reason' => $why]]]);
        $this->lock->release($id);

        return ['ok' => false, 'reason' => $this->safeMessage($reason), 'recovered' => false, 'outcome' => UpgradeOutcome::RECOVERY_REQUIRED, 'health' => null];
    }

    /**
     * Finish an upgrade that passed its commit point (ownership record written)
     * but whose process failed before it was recorded COMPLETED.
     *
     * @param  array<string,mixed>  $state
     * @return array{ok:bool, reason:string, recovered:bool, outcome:string}
     */
    private function rollForward(string $id, UpgradeTransaction $txn, array $state): array
    {
        $plan = $txn->loadPlan();
        $problems = $plan !== null ? $txn->verifyCommitted($plan) : [['rel' => '*', 'reason' => 'transaction plan is missing']];
        if ($problems === [] && ! $txn->journal()->has('finalized')) {
            $model = $txn->plannedModel() ?? $this->ownershipModel();
            $this->contained(fn () => $txn->finalize($plan, $model['replace_dirs'] ?? []), null);
        }
        $outcome = $problems === [] ? UpgradeOutcome::COMMITTED_VERIFIED : UpgradeOutcome::RECOVERY_REQUIRED;
        $fields = ['outcome' => $outcome, 'recovery_notes' => [$problems === [] ? 'upgrade had passed its commit point; completed and verified' : 'committed files could not be verified']];
        $status = (string) (($this->store->load($id) ?? $state)['status'] ?? '');
        if ($problems === [] && UpgradeState::canTransition($status, UpgradeState::COMPLETED)) {
            $this->safeTransition($id, UpgradeState::COMPLETED, 'Upgrade completed (rolled forward after interruption)', $fields);
        } else {
            $this->contained(fn () => $this->store->patch($id, $fields + ['recovery_attempted_at' => gmdate('c')]), null);
        }
        // Proven by reading it back, as recover() does: maintenance is lifted only once "done" is durable.
        $persisted = (($this->store->load($id) ?? [])['outcome'] ?? null) === $outcome;
        if ($problems === [] && ! $persisted) {
            $problems[] = ['rel' => 'state', 'reason' => 'the completed record could not be written'];
            $outcome = UpgradeOutcome::RECOVERY_REQUIRED;
        }
        if ($problems === []) {
            $this->exitMaintenance();
            $this->removeTransientArtifacts($id, true);
        }
        $this->writeDiagnostics($id, ['reason' => 'roll forward after commit point', 'outcome' => $outcome, 'problems' => $problems]);
        $this->lock->release($id);

        $reason = $problems === [] ? 'ok' : ($persisted ? 'committed files could not be verified' : 'the completed record could not be written; free space and retry recovery');

        return ['ok' => $problems === [], 'reason' => $reason, 'recovered' => false, 'outcome' => $outcome];
    }

    /**
     * Operator-requested recovery of an interrupted or RECOVERY_REQUIRED attempt.
     * Refused for a completed upgrade, while a live (non-stale) apply still holds
     * the upgrade lock, and while another recovery of the same attempt runs.
     *
     * @return array{ok:bool, reason:string, recovered?:bool, outcome?:string}
     */
    public function manualRecover(string $id): array
    {
        $state = $this->store->load($id) ?? [];
        $status = (string) ($state['status'] ?? '');
        $txn = $this->transaction($id);
        if ($status === UpgradeState::COMPLETED || ! $this->needsRecovery($id)) {
            return ['ok' => false, 'reason' => 'this upgrade has nothing to recover'];
        }
        if ($this->lock->isLocked() && ! $this->lock->isStale() && ! UpgradeState::isTerminal($status)) {
            return ['ok' => false, 'reason' => 'an upgrade still appears to be running; wait for it to finish or for its lock to expire'];
        }
        $guard = new UpgradeLock($this->store->dir($id));
        $token = 'recover_'.bin2hex(random_bytes(6));
        if (! $guard->acquire($token, self::GUARD_STALE_AFTER)) {
            return ['ok' => false, 'reason' => 'a recovery of this upgrade is already running'];
        }
        try {
            try {
                $committed = $txn->journal()->isSafe() && $txn->journal()->has('committed');
            } catch (RuntimeException) {
                $committed = false; // unreadable: recover() records the refusal instead of crashing here
            }

            return $committed
                ? $this->rollForward($id, $txn, $state)
                : $this->recover($id, 'manual recovery requested', null);
        } finally {
            $guard->release($token);
        }
    }

    /** True when an attempt mutated (or may have mutated) the live site and is not verifiably resolved. */
    public function needsRecovery(string $id): bool
    {
        if ($this->store->isUnreadable($id)) {
            return true;
        }
        $state = $this->store->load($id) ?? [];
        $status = (string) ($state['status'] ?? '');
        if ($status === UpgradeState::COMPLETED) {
            return false;
        }
        if (($state['outcome'] ?? null) === UpgradeOutcome::RECOVERY_REQUIRED) {
            return true;
        }

        return $this->transaction($id)->isOpen()
            || (UpgradeState::hasMutatedLiveState($status) && ! UpgradeState::isTerminal($status));
    }

    /**
     * Retry the post-commit cleanup of a COMPLETED upgrade (e.g. a preserved copy
     * was still held open, or the volume was full). Removes only transient data
     * of that attempt; never touches the committed install or its status.
     *
     * Runs under the exclusive upgrade lock (as apply and purge do) and the
     * attempt's recovery guard (as manualRecover does). Both are try-locks taken
     * in that order, so nothing ever waits and two callers cannot deadlock.
     * Eligibility is decided only from the state read while both are held.
     *
     * @return array{cleanup_ok:bool, pending:list<string>, freed_bytes?:int, refused?:bool, reason?:string}
     */
    public function retryCleanup(string $id): array
    {
        if (! UpgradeId::isValid($id)) {
            return $this->refuseCleanup('unknown upgrade id');
        }
        $token = 'upg_cleanup_'.bin2hex(random_bytes(4));
        if (! $this->lock->acquire($token)) {
            return $this->refuseCleanup($this->lock->isLocked()
                ? 'an upgrade, recovery or cleanup is running; try again when it has finished'
                : self::LOCK_NOT_WRITTEN);
        }
        try {
            $dir = $this->store->dir($id);
            if (! is_dir($dir) && ! UpgradePathGuard::isReparsePoint($dir)) {
                return $this->refuseCleanup('only a completed upgrade can be cleaned up');
            }
            $root = realpath($this->store->root());
            $real = realpath($dir);
            if ($root === false || $real === false || UpgradePathGuard::isReparsePoint($dir)
                || str_replace('\\', '/', $real) !== rtrim(str_replace('\\', '/', $root), '/').'/'.$id
                || UpgradePathGuard::isReparsePoint($dir.'/txn') // finalize() writes and removes under txn/
                || ! $this->transaction($id)->journal()->isSafe()) {
                return $this->refuseCleanup('the upgrade storage of this attempt is behind a link; nothing was removed');
            }
            $guard = new UpgradeLock($dir);
            if (! $guard->acquire($token, self::GUARD_STALE_AFTER)) {
                return $this->refuseCleanup($guard->isLocked()
                    ? 'a recovery of this upgrade is running; try again when it has finished'
                    : self::LOCK_NOT_WRITTEN);
            }
            try {
                return $this->retryCleanupLocked($id);
            } finally {
                $guard->release($token);
            }
        } finally {
            $this->lock->release($token);
        }
    }

    /** @return array{cleanup_ok:bool, pending:list<string>, freed_bytes?:int, refused?:bool, reason?:string} */
    private function retryCleanupLocked(string $id): array
    {
        if ($this->blockingRecovery($id) !== null) {
            return $this->refuseCleanup('another upgrade still requires recovery; recover it first');
        }
        $state = $this->store->isUnreadable($id) ? null : $this->store->load($id);
        $txn = $state !== null ? $this->transaction($id) : null;
        try {
            $committed = $txn !== null && $txn->journal()->has('committed');
        } catch (RuntimeException) {
            return $this->refuseCleanup('the upgrade journal could not be read; nothing was removed');
        }
        if ($state === null || ($state['status'] ?? null) !== UpgradeState::COMPLETED
            || ($state['outcome'] ?? null) !== UpgradeOutcome::COMMITTED_VERIFIED || ! $committed) {
            return $this->refuseCleanup('only a completed upgrade can be cleaned up');
        }
        $plan = $txn->loadPlan();
        $model = $txn->plannedModel() ?? $this->ownershipModel();
        $error = null;
        try {
            $cleanup = $txn->finalize($plan ?? ['ops' => []], $model['replace_dirs'] ?? []);
        } catch (Throwable $e) {
            $error = $this->safeMessage($e->getMessage());
            // Only an OS error that names a full disk or quota is reported as one; anything else keeps its own cause.
            $cleanup = ['cleanup_ok' => false, 'pending' => [preg_match(self::NO_SPACE, $error) === 1
                ? self::CLEANUP_DISK_FULL
                : 'the cleanup could not finish ('.$error.'); the upgrade itself is complete. Resolve the cause, then retry the cleanup']];
        }
        $cleanup = $this->mergeCleanup($cleanup, $this->removeTransientArtifacts($id, true));
        if ($error !== null) {
            $cleanup['error'] = $error; // the original failure, kept in the attempt record
        }
        $this->contained(fn () => $this->store->patch($id, ['cleanup' => $cleanup, 'cleanup_retried_at' => gmdate('c')]), null);

        return $cleanup;
    }

    /** @return array{cleanup_ok:false, pending:list<string>, refused:true, reason:string} */
    private function refuseCleanup(string $reason): array
    {
        return ['cleanup_ok' => false, 'pending' => [$reason], 'refused' => true, 'reason' => $reason];
    }

    /** Upgrade id of an attempt (other than $except) awaiting or needing recovery, or null. */
    public function blockingRecovery(?string $except = null): ?string
    {
        foreach ($this->store->all() as $state) {
            $id = (string) ($state['upgrade_id'] ?? '');
            if ($id === '' || $id === $except) {
                continue;
            }
            if (($state['outcome'] ?? null) === UpgradeOutcome::RECOVERY_REQUIRED) {
                return $id;
            }
            // A crashed apply leaves a non-terminal state with an open transaction journal. A non-terminal state
            // that already mutated the site blocks too, even when the journal says "rolled back": the final
            // record (e.g. a failed database restore) may be the write that was lost.
            if (! UpgradeState::isTerminal((string) ($state['status'] ?? '')) && $this->needsRecovery($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Run one recovery stage; an unexpected exception yields $fallback instead of escaping.
     *
     * @template T
     *
     * @param  callable():T  $stage
     * @param  T  $fallback
     * @return T
     */
    private function contained(callable $stage, mixed $fallback): mixed
    {
        try {
            return $stage();
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function transaction(string $id): UpgradeTransaction
    {
        return new UpgradeTransaction(
            $this->root,
            $this->store->dir($id).'/txn',
            $this->transactionFault(),
            fn () => $this->lock->touch($id),
        );
    }

    /** Overridable seam: a fault injector for the transaction (tests / diagnostics only). */
    protected function transactionFault(): ?callable
    {
        return null;
    }

    /** Overridable seam: the database backup authority (tests inject storage faults here). */
    protected function databaseBackup(): DatabaseBackup
    {
        return new DatabaseBackup;
    }

    /**
     * Snapshot the database inside maintenance and PROVE it (manifest-bound,
     * re-verified, restorable). Throws when it cannot be proven; the caller then
     * rejects the upgrade before anything is mutated.
     */
    private function snapshotDatabase(string $id): void
    {
        if (! $this->databaseReachable()) {
            $this->recordSnapshot($id, ['state' => 'unreachable']);

            return;
        }
        if (! $this->databaseIsDumpable()) {
            $this->recordSnapshot($id, ['state' => 'not_dumpable']);

            return;
        }
        $file = $this->store->dir($id).'/txn/database.sql';
        $backup = $this->databaseBackup();
        $meta = $backup->dump(($this->pdoResolver)(), $file);
        if (! $meta['restorable']) {
            throw new \RuntimeException('database snapshot is not restorable: '.(string) $meta['not_restorable_reason']);
        }
        $verify = $backup->verify($file, $meta['sha256'], $meta['manifest_sha256']);
        if (! $verify['ok']) {
            throw new \RuntimeException('database snapshot verification failed: '.$verify['reason']);
        }
        $this->recordSnapshot($id, [
            'state' => 'snapshot',
            'sha256' => $meta['sha256'],
            'manifest_sha256' => $meta['manifest_sha256'],
            'tables' => $meta['tables'],
            'bytes' => $meta['bytes'],
            'table_list' => $meta['table_list'],
            'non_transactional' => $meta['non_transactional'],
        ]);
    }

    /**
     * Record snapshot facts twice: in the state record (UI) and in the append-only
     * transaction journal, so recovery can still prove the database when every
     * state generation has been lost.
     *
     * @param  array<string,mixed>  $facts
     */
    private function recordSnapshot(string $id, array $facts): void
    {
        $this->transaction($id)->journal()->append('db_snapshot', $facts);
        if (isset($facts['table_list'])) {
            $facts['table_list_sha256'] = hash('sha256', (string) json_encode($facts['table_list']));
            unset($facts['table_list']); // the inventory stays in the protected journal, not the UI-facing state
        }
        $this->store->patch($id, ['txn_db' => $facts]);
    }

    /** @return array<string,mixed> snapshot facts from the state record, else from the journal */
    private function snapshotFacts(string $id): array
    {
        $journal = $this->transaction($id)->journal();
        $snap = $journal->last('db_snapshot');
        if ($snap === null) {
            $snap = ($this->store->load($id) ?? [])['txn_db'] ?? [];
        }
        unset($snap['seq'], $snap['at'], $snap['ev']);
        if (($pre = $journal->last('pre_migration_inventory')) !== null) {
            $snap['pre_migration_tables'] = $pre['tables'] ?? null;
        }

        return \is_array($snap) ? $snap : [];
    }

    /** Record the table inventory right before migrations: provenance for post-snapshot tables. */
    private function recordPreMigrationInventory(string $id): void
    {
        $snap = $this->snapshotFacts($id);
        if (($snap['state'] ?? null) !== 'snapshot') {
            return;
        }
        $tables = $this->databaseBackup()->baseTables(($this->pdoResolver)());
        $this->transaction($id)->journal()->append('pre_migration_inventory', ['tables' => $tables]);
    }

    /**
     * Restore the PROVEN transaction snapshot and verify schema + data against it.
     *
     * Tables absent from the snapshot are dropped only when provenance is
     * established: the inventory recorded immediately before migrations equals
     * the snapshot's table list, so anything new appeared during the migration
     * window. Otherwise nothing is dropped and the attempt is RECOVERY_REQUIRED.
     * A RETRY restores only while the site has stayed in maintenance since the
     * snapshot; once it has served traffic again, later writes would be lost.
     *
     * @return array{note:string, problems:list<array{rel:string,reason:string}>, restored?:bool}
     */
    private function restoreDatabaseVerified(string $id, bool $retry = false): array
    {
        $snap = $this->snapshotFacts($id);
        $stateName = (string) ($snap['state'] ?? 'missing');
        if ($stateName === 'unreachable') {
            return ['note' => 'database not in use', 'problems' => []];
        }
        if ($stateName !== 'snapshot') {
            return ['note' => 'database restore: manual recovery required', 'problems' => [['rel' => 'database', 'reason' => "no proven snapshot ($stateName)"]]];
        }
        if ($this->maintenanceActive() !== true) {
            // Unknown counts as lifted: restoring over a live site would discard writes made since the snapshot.
            return ['note' => 'database restore refused: maintenance mode is not confirmed on', 'problems' => [['rel' => 'database', 'reason' => 'maintenance not confirmed since the snapshot; recover the database manually']]];
        }
        $file = $this->store->dir($id).'/txn/database.sql';
        $backup = $this->databaseBackup();
        $manifestSha = (string) ($snap['manifest_sha256'] ?? '');
        $fileSha = (string) ($snap['sha256'] ?? '');
        if ($manifestSha === '' || $fileSha === '') {
            return ['note' => 'database restore refused: the snapshot was never pinned', 'problems' => [['rel' => 'database', 'reason' => 'snapshot pin missing']]];
        }
        $verify = $backup->verify($file, $fileSha, $manifestSha);
        if (! $verify['ok']) {
            // Never act on an unprovable snapshot: the database is left exactly as it is.
            return ['note' => 'database restore refused: snapshot cannot be proven ('.$verify['reason'].')', 'problems' => [['rel' => 'database', 'reason' => 'snapshot unverifiable: '.$verify['reason']]]];
        }

        $pdo = ($this->pdoResolver)();
        $snapshotTables = array_map('strval', (array) ($verify['manifest']['table_list'] ?? []));
        $preMigration = $snap['pre_migration_tables'] ?? null;
        $extra = array_values(array_diff($backup->baseTables($pdo), $snapshotTables));
        $provenance = \is_array($preMigration) && array_values($preMigration) === array_values($snapshotTables);
        $dropCandidates = $provenance ? $extra : [];

        $this->transaction($id)->journal()->append('db_restore_begin', ['drop_candidates' => \count($dropCandidates)]);
        $result = $backup->restore($pdo, $file, $dropCandidates, $manifestSha);
        $problems = $backup->verifyRestored($pdo, $file, $manifestSha);
        if (! $provenance && $extra !== []) {
            $problems[] = ['rel' => 'database', 'reason' => \count($extra).' table(s) of unproven origin remain (see diagnostics)'];
        }

        return [
            'note' => $problems === [] ? 'database restored and verified'.($result['dropped'] !== [] ? ' ('.\count($result['dropped']).' table(s) created by the failed migration removed)' : '') : 'database restored but not verified',
            'problems' => $problems,
            'restored' => true,
        ];
    }

    /**
     * Per-filesystem capacity for this attempt. With $dumpBytes = null (preflight)
     * the database term is estimated from information_schema; at apply time the
     * measured backup dump size is used and staging is already on disk.
     *
     * @param  array<string,mixed>  $model
     * @return array{state:string, detail:string}
     */
    private function assessCapacity(string $id, array $model, ?int $dumpBytes): array
    {
        $pkg = $id !== '' ? $this->packagePath($id) : '';
        $owned = $this->estimateOwnedBytes($model);
        $db = $dumpBytes ?? ($this->databaseIsDumpable() ? $this->contained(fn () => $this->databaseBackup()->estimateBytes(($this->pdoResolver)()), null) : 0);
        $budget = $pkg !== '' ? UpgradeStorageBudget::estimate($pkg, $owned, $db) : null;
        if ($budget === null) {
            return ['state' => StorageCapacity::UNKNOWN, 'detail' => 'UNKNOWN: the package could not be measured'];
        }
        $storage = $dumpBytes === null
            // preflight: staging + backup archive + two dumps + metadata + headroom
            ? $budget['staging_bytes'] + $budget['backup_bytes'] + $budget['database_bytes'] + $budget['metadata_bytes'] + $budget['headroom_bytes']
            // apply: staging + backup already exist; one more dump (the snapshot) + metadata + headroom
            : $dumpBytes + $budget['metadata_bytes'] + $budget['headroom_bytes'];
        $report = $this->capacityProbe()->assess([
            ['purpose' => 'upgrade-storage', 'path' => $this->store->root(), 'bytes' => $storage],
            ['purpose' => 'site', 'path' => $this->root, 'bytes' => $budget['largest_entry']],
        ]);

        return ['state' => $report['state'], 'detail' => $report['detail']];
    }

    /** Overridable seam: PHP memory_limit in bytes, or null when unlimited. */
    protected function memoryLimitBytes(): ?int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return null;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * Live Core-owned files vs the verified backup manifest (proof that nothing changed).
     *
     * @return list<array{rel:string,reason:string}>
     */
    private function compareWithBackup(string $id): array
    {
        $manifest = json_decode((string) @file_get_contents($this->backupDir($id).'/core-files.manifest.json'), true);
        if (! \is_array($manifest['files'] ?? null)) {
            return [['rel' => '*', 'reason' => 'no verified backup manifest to compare with']];
        }
        $live = (new CoreBackup($this->root))->liveOwnedFiles($this->ownershipModel());
        $problems = [];
        foreach (array_unique(array_merge(array_keys($live), array_keys($manifest['files']))) as $rel) {
            if (($live[$rel] ?? null) !== ($manifest['files'][$rel] ?? null)) {
                $problems[] = ['rel' => (string) $rel, 'reason' => 'differs from the verified backup'];
            }
        }

        return $problems;
    }

    /** Overridable seam: capacity probe (tests inject free-space values). */
    protected function capacityProbe(): StorageCapacity
    {
        return new StorageCapacity;
    }

    /** End the attempt as REJECTED: nothing was mutated (maintenance never entered or already lifted). */
    private function reject(string $id, string $reason): array
    {
        $reason = $this->safeMessage($reason);
        $this->safeTransition($id, UpgradeState::FAILED, $reason, [
            'failure_stage' => ($this->store->load($id) ?? [])['status'] ?? null,
            'failure_reason' => $reason,
            'outcome' => UpgradeOutcome::REJECTED,
            'recovered' => true,
            'recovery_notes' => ['no files or data were changed'],
            'cleanup' => $this->removeTransientArtifacts($id, false),
        ]);
        $this->lock->release($id);

        return ['ok' => false, 'reason' => $reason, 'recovered' => true, 'outcome' => UpgradeOutcome::REJECTED];
    }

    /**
     * Remove what is no longer needed: staging and the uploaded package always;
     * the transaction snapshot only after a verified commit. The verified backup
     * and all metadata are kept. Confined to this attempt's directory, never
     * follows a link, idempotent; a failure is reported, never thrown.
     *
     * @return array{cleanup_ok:bool, pending:list<string>, freed_bytes:int}
     */
    private function removeTransientArtifacts(string $id, bool $committed): array
    {
        $dir = $this->store->dir($id);
        $targets = [$dir.'/staging', $dir.'/package.zip'];
        if ($committed) {
            $targets[] = $dir.'/txn/database.sql';
            $targets[] = DatabaseBackup::manifestPath($dir.'/txn/database.sql');
        }
        $pending = [];
        $freed = 0;
        $guard = new UpgradePathGuard;
        foreach ($targets as $t) {
            if (! file_exists($t) && ! UpgradePathGuard::isReparsePoint($t)) {
                continue;
            }
            $rel = substr($t, \strlen($dir) + 1);
            if (UpgradePathGuard::isReparsePoint($dir) || ($rel !== false && \dirname($rel) !== '.' && $guard->reparseReason($dir, \dirname($rel)) !== null)) {
                $pending[] = basename($t).' (behind a link; left untouched)';

                continue;
            }
            $freed += $this->treeBytes($t);
            $this->removeConfined($t, $dir);
            if (file_exists($t)) {
                $pending[] = basename($t);
            }
        }

        return ['cleanup_ok' => $pending === [], 'pending' => $pending, 'freed_bytes' => $freed];
    }

    /** @param array<string,mixed> $a @param array<string,mixed> $b */
    private function mergeCleanup(array $a, array $b): array
    {
        return [
            'cleanup_ok' => ($a['cleanup_ok'] ?? true) && ($b['cleanup_ok'] ?? true),
            'pending' => array_values(array_merge($a['pending'] ?? [], $b['pending'] ?? [])),
            'freed_bytes' => (int) ($b['freed_bytes'] ?? 0),
        ];
    }

    /** Remove $path (file or tree) only if it lies inside $within; never follows links. */
    private function removeConfined(string $path, string $within): void
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $within = rtrim(str_replace('\\', '/', $within), '/');
        if (! str_starts_with($path, $within.'/')) {
            return;
        }
        if (UpgradePathGuard::isReparsePoint($path)) {
            @rmdir($path) || @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $e) {
            $this->removeConfined($path.'/'.$e, $within);
        }
        @rmdir($path);
    }

    private function treeBytes(string $path): int
    {
        if (UpgradePathGuard::isReparsePoint($path)) {
            return 0;
        }
        if (is_file($path)) {
            return (int) @filesize($path);
        }
        $n = 0;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $e) {
            $n += $this->treeBytes($path.'/'.$e);
        }

        return $n;
    }

    /** The store record for $id, including the synthetic record of an unreadable attempt. */
    private function record(string $id): ?array
    {
        foreach ($this->store->all() as $state) {
            if (($state['upgrade_id'] ?? null) === $id) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Upgrade data kept on disk (operator-facing).
     *
     * @return array{attempts:int, bytes:int, per_attempt:array<string,int>}
     */
    public function storageUsage(): array
    {
        $per = [];
        foreach ($this->store->all() as $state) {
            $id = (string) ($state['upgrade_id'] ?? '');
            if ($id !== '') {
                $per[$id] = $this->treeBytes($this->store->dir($id));
            }
        }

        return ['attempts' => \count($per), 'bytes' => array_sum($per), 'per_attempt' => $per];
    }

    /**
     * Delete one old attempt's data. Refused for the latest successful upgrade
     * (its backup is the last recoverable one), for any attempt that is active,
     * blocking or needs recovery, and while an upgrade holds the lock.
     *
     * @return array{ok:bool, reason:string, freed_bytes?:int}
     */
    public function purgeAttempt(string $id): array
    {
        if (! UpgradeId::isValid($id)) {
            return ['ok' => false, 'reason' => 'unknown upgrade id'];
        }
        $token = 'upg_purge_'.bin2hex(random_bytes(4));
        if (! $this->lock->acquire($token)) {
            return ['ok' => false, 'reason' => 'an upgrade is running; try again when it has finished'];
        }
        try {
            return $this->purgeLocked($id);
        } finally {
            $this->lock->release($token);
        }
    }

    /** @return array{ok:bool, reason:string, freed_bytes?:int} */
    private function purgeLocked(string $id): array
    {
        $state = $this->store->load($id);
        if ($state === null || $this->needsRecovery($id) || $this->blockingRecovery() === $id) {
            return ['ok' => false, 'reason' => 'this attempt cannot be removed (unknown, active or awaiting recovery)'];
        }
        if (! UpgradeState::isTerminal((string) ($state['status'] ?? ''))) {
            return ['ok' => false, 'reason' => 'only finished attempts can be removed'];
        }
        $latestSuccess = null;
        foreach ($this->store->all() as $s) {
            if (($s['status'] ?? null) === UpgradeState::COMPLETED && ($latestSuccess === null || ($s['updated_at'] ?? '') > ($latestSuccess['updated_at'] ?? ''))) {
                $latestSuccess = $s;
            }
        }
        if (($latestSuccess['upgrade_id'] ?? null) === $id) {
            return ['ok' => false, 'reason' => 'the latest successful upgrade keeps its backup'];
        }
        $dir = $this->store->dir($id);
        $bytes = $this->treeBytes($dir);
        $this->removeConfined($dir, $this->store->root());

        return is_dir($dir)
            ? ['ok' => false, 'reason' => 'some files could not be removed (they may be in use)']
            : ['ok' => true, 'reason' => 'ok', 'freed_bytes' => $bytes];
    }

    private function preserveRecordedManifest(string $id): void
    {
        $file = $this->root.'/'.self::CORE_MANIFEST_FILE;
        $dest = $this->store->dir($id).'/txn/recorded-manifest.prior';
        $bytes = is_file($file) ? (string) file_get_contents($file) : null;
        $ok = @file_put_contents($dest, (string) json_encode([
            'present' => $bytes !== null,
            'sha256' => $bytes !== null ? hash('sha256', $bytes) : null,
            'bytes' => $bytes !== null ? base64_encode($bytes) : null,
        ])) !== false;
        if (! $ok) {
            throw new \RuntimeException('the ownership record could not be preserved');
        }
    }

    /** Put the pre-upgrade ownership record back exactly; true when verified. */
    private function restoreRecordedManifest(string $id): bool
    {
        $prior = json_decode((string) @file_get_contents($this->store->dir($id).'/txn/recorded-manifest.prior'), true);
        if (! \is_array($prior)) {
            return true; // never preserved → the transaction never reached the point of writing it
        }
        $file = $this->root.'/'.self::CORE_MANIFEST_FILE;
        if (! ($prior['present'] ?? false)) {
            return ! is_file($file) || @unlink($file);
        }
        $bytes = (string) base64_decode((string) ($prior['bytes'] ?? ''), true);
        if (! is_file($file) || ! hash_equals((string) $prior['sha256'], (string) hash_file('sha256', $file))) {
            $tmp = $file.'.tncms-restore';
            if (@file_put_contents($tmp, $bytes) === false || ! @rename($tmp, $file)) {
                @unlink($tmp);

                return false;
            }
        }

        return hash_equals((string) $prior['sha256'], (string) hash_file('sha256', $file));
    }

    /** @param array<string,mixed> $data protected-storage diagnostics (relative paths only) */
    private function writeDiagnostics(string $id, array $data): void
    {
        @file_put_contents($this->store->dir($id).'/diagnostics.json', (string) json_encode(
            ['upgrade_id' => $id, 'at' => gmdate('c'), 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY] + $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** Strip absolute paths from a message shown to operators. */
    private function safeMessage(string $message): string
    {
        $message = str_replace([$this->root, str_replace('/', '\\', $this->root)], '<site>', $message);

        $message = (string) preg_replace('#\\\\\\\\[^\s,)]+#', '<path>', $message); // UNC: \\server\share\...
        $message = (string) preg_replace('#[A-Za-z]:[\\\\/][^\s,)]*#', '<path>', $message);
        $message = (string) preg_replace('#(?<![\w.])/(?:[\w.-]+/)+[\w.-]*#', '<path>', $message);

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300).'…' : $message;
    }

    // ---- interrupted upgrade detection (§44) ---------------------------

    /**
     * Describe the current recoverable situation for the /upgrade landing page.
     *
     * @return array{has_active:bool, state:?array<string,mixed>, recommendation:string}
     */
    public function situation(): array
    {
        $blocking = $this->blockingRecovery();
        if ($blocking !== null) {
            // An unverified recovery outranks everything: no new upgrade until it is resolved.
            return ['has_active' => true, 'state' => $this->record($blocking), 'recommendation' => 'recover'];
        }
        $active = $this->active();
        if ($active === null) {
            return ['has_active' => false, 'state' => null, 'recommendation' => 'start'];
        }
        $status = (string) ($active['status'] ?? '');
        // A transaction journal that began mutating means the process died mid-promotion.
        $interrupted = $this->transaction((string) $active['upgrade_id'])->isOpen();
        $rec = UpgradeState::hasMutatedLiveState($status) || $interrupted ? 'recover' : 'resume';

        return ['has_active' => true, 'state' => $active, 'recommendation' => $rec];
    }

    /** Abandon a pre-mutation attempt (§44): mark failed + free the lock. */
    /**
     * End an attempt that never mutated the live site. Refused while its apply() is running. An attempt
     * stopped in MAINTENANCE (before any file was replaced) also gets maintenance lifted.
     *
     * @return array{ok:bool, reason:string}
     */
    public function discard(string $id): array
    {
        // The lock is held under $id from the backup step on; only a heartbeat means its apply() is running.
        $owner = $this->lock->owner();
        if ($this->lock->isLocked() && ! $this->lock->isStale()
            && ($owner === null || (($owner['upgrade_id'] ?? null) === $id && isset($owner['heartbeat_at'])))) {
            // Running, or a live lock that cannot be read right now: never guess.
            return ['ok' => false, 'reason' => 'this upgrade is still running (or stopped less than an hour ago); wait for it to finish, then try again'];
        }
        $status = (string) (($this->store->load($id) ?? [])['status'] ?? '');
        if (UpgradeState::hasMutatedLiveState($status) || $this->transaction($id)->isOpen()) {
            return ['ok' => false, 'reason' => 'this upgrade has started changing files; use recovery instead'];
        }
        $wasInMaintenance = $status === UpgradeState::MAINTENANCE;
        $this->safeTransition($id, UpgradeState::FAILED, 'Discarded by administrator', ['cleanup' => $this->removeTransientArtifacts($id, false)]);
        if ($wasInMaintenance && (($this->store->load($id) ?? [])['status'] ?? null) === UpgradeState::FAILED) {
            $this->contained(fn () => $this->exitMaintenance(), null); // nothing was replaced; the site must not stay down
        }
        $this->lock->release($id);

        return ['ok' => true, 'reason' => 'ok'];
    }

    // ---- collaborators / helpers ---------------------------------------

    /** Overridable seam: enter maintenance via the canonical settings authority (§23). */
    protected function enterMaintenance(): void
    {
        $this->setMaintenance(true);
    }

    /** Overridable seam: leave maintenance. */
    protected function exitMaintenance(): void
    {
        $this->setMaintenance(false);
    }

    /** Overridable seam: whether maintenance is currently on (null when it cannot be determined). */
    protected function maintenanceActive(): ?bool
    {
        try {
            if (\function_exists('app') && app()->bound('cms.settings')) {
                return (bool) app('cms.settings')->get('maintenance.enabled', false);
            }
        } catch (Throwable) {
            // undeterminable
        }

        return null;
    }

    private function setMaintenance(bool $on): void
    {
        try {
            if (\function_exists('app') && app()->bound('cms.settings')) {
                app('cms.settings')->set('maintenance.enabled', $on, 'boolean');
            }
        } catch (Throwable) {
            // Non-fatal: maintenance is a courtesy gate, never a hard dependency.
        }
    }

    /** Overridable seam (canonical Core migrations, §37). */
    protected function runMigrations(): void
    {
        if (\function_exists('app') && app()->bound('cms.installer')) {
            app('cms.installer')->runMigrations();
        }
    }

    /** Overridable seam: whether health verification runs live DB checks (§39). */
    protected function healthChecksDatabase(): bool
    {
        return $this->databaseReachable();
    }

    /** Overridable seam (cache lifecycle + Core asset regeneration, §38). */
    protected function refreshCaches(array $model): void
    {
        if (! \function_exists('app')) {
            return;
        }
        try {
            app('cms.installer')->clearCaches();
        } catch (Throwable) {
            // best-effort
        }
        foreach ($model['regenerate_commands'] ?? [] as $cmd) {
            try {
                \Illuminate\Support\Facades\Artisan::call($cmd, ['--no-interaction' => true]);
            } catch (Throwable) {
                // Non-fatal: Core public assets are already promoted from staging.
            }
        }
    }

    private function databaseReachable(): bool
    {
        try {
            ($this->pdoResolver)();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Overridable seam: whether the database can be dumped/restored natively (MySQL/MariaDB). */
    protected function databaseIsDumpable(): bool
    {
        try {
            $driver = (string) \Illuminate\Support\Facades\DB::connection()->getDriverName();

            return \in_array($driver, ['mysql', 'mariadb'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** The shipped ownership-record filename — stable, no dev-manifest dependency. */
    private const CORE_MANIFEST_FILE = 'tncms-core.manifest.json';

    /** Site's recorded Core ownership record (written at install / last upgrade). */
    private function recordedCoreManifest(): array
    {
        $file = $this->root.'/'.self::CORE_MANIFEST_FILE;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (\is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /** @return array<string,string> recorded owned-file map (rel => sha) */
    private function recordedOwnership(): array
    {
        $files = $this->recordedCoreManifest()['files'] ?? [];

        return \is_array($files) ? $files : [];
    }

    /** Write the new ownership record atomically (temp + rename) and verify it reads back. */
    private function writeRecordedOwnership(string $id, array $model, array $newOwned, string $version): void
    {
        $file = $this->root.'/'.self::CORE_MANIFEST_FILE;
        $json = (string) json_encode([
            'product' => 'TNCMS',
            'version' => $version,
            'generated_at' => gmdate('c'),
            'ownership' => $model,
            'files' => $newOwned,
            'file_count' => \count($newOwned),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $file.'.tncms-new';
        if (@file_put_contents($tmp, $json) === false || ! @rename($tmp, $file)
            || ! hash_equals(hash('sha256', $json), (string) @hash_file('sha256', $file))) {
            @unlink($tmp);
            throw new \RuntimeException('the new ownership record could not be written');
        }
    }

    /** The staged package's shipped core manifest (ownership model + owned files). */
    private function packageCoreManifest(string $id): array
    {
        $file = $this->stagingDir($id).'/'.self::CORE_MANIFEST_FILE;
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (\is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function estimateOwnedBytes(array $model): int
    {
        $bytes = 0;
        foreach ($model['replace_dirs'] ?? [] as $d) {
            $abs = $this->root.'/'.rtrim(CoreOwnership::normalize($d), '/');
            if (! is_dir($abs)) {
                continue;
            }
            try {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    if ($f->isFile()) {
                        $bytes += $f->getSize();
                    }
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return $bytes;
    }

    /** Strip absolute local paths that are not needed by the UI from the record. */
    private function secretSafeRefs(array $refs): array
    {
        return [
            'db_file' => $refs['db_file'] ?? null,
            'db_sha256' => $refs['db_sha256'] ?? null,
            'db_manifest_sha256' => $refs['db_manifest_sha256'] ?? null,
            'db_bytes' => $refs['db_bytes'] ?? 0,
            'db_tables' => $refs['db_tables'] ?? 0,
            'db_rows' => $refs['db_rows'] ?? 0,
            'files_zip' => $refs['files_zip'] ?? null,
            'files_sha256' => $refs['files_sha256'] ?? null,
            'files_count' => $refs['files_count'] ?? 0,
            'env_file' => $refs['env_file'] ?? null,
            'env_sha256' => $refs['env_sha256'] ?? null,
        ];
    }

    /** Pre-mutation failure: record it, drop the untrusted package/staging (never the backup), free the lock. */
    private function failIfPossible(string $id, string $reason): void
    {
        $this->safeTransition($id, UpgradeState::FAILED, $reason, ['cleanup' => $this->removeTransientArtifacts($id, false)]);
        $this->lock->release($id);
    }

    private function safeTransition(string $id, string $to, string $note, array $fields = []): void
    {
        try {
            $this->store->transition($id, $to, $note, $fields);
        } catch (Throwable) {
            // Illegal transition (already terminal) — leave the record as-is.
        }
    }

    private function manifest(): array
    {
        if ($this->manifestCache === null) {
            $file = $this->root.'/tools/distribution/manifest.php';
            $this->manifestCache = is_file($file) ? (require $file) : ['core_ownership' => []];
        }

        return $this->manifestCache;
    }

    private function readVersionOnDisk(): string
    {
        return (new UpgradeHealthCheck($this->root))->versionOnDisk() ?? '';
    }

}
