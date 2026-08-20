<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use PDO;
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
    private string $root;

    private UpgradeStateStore $store;

    private UpgradeLock $lock;

    /** @var callable():PDO */
    private $pdoResolver;

    /** @var array<string,mixed>|null per-instance manifest cache */
    private ?array $manifestCache = null;

    /** Safety margin for the disk-capacity estimate (§16). */
    private const DISK_MARGIN_BYTES = 268_435_456; // 256 MiB

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

        $add('lock-available', ! $this->lock->isLocked() || $this->lock->isOwnedBy((string) $state['upgrade_id']) || $this->lock->isStale(), true, 'upgrade lock');

        // Disk capacity (§16): staging (~2× package) + file backup (~owned bytes) + margin.
        $pkgBytes = is_file($this->packagePath((string) $state['upgrade_id'])) ? (int) filesize($this->packagePath((string) $state['upgrade_id'])) : 0;
        $ownedBytes = $this->estimateOwnedBytes($model);
        $need = ($pkgBytes * 2) + $ownedBytes + self::DISK_MARGIN_BYTES;
        $free = (int) @disk_free_space($this->root);
        $add('disk-space', $free === 0 || $free >= $need, true, 'need '.$this->human($need).' free '.$this->human($free));

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
            $this->failIfPossible($id, 'Backup failed: '.$e->getMessage());

            return ['ok' => false, 'reason' => $e->getMessage(), 'refs' => []];
        }
    }

    /**
     * @return array{db_file:?string, db_sha256:?string, db_tables:int, db_rows:int}
     */
    public function backupDatabase(string $backupDir): array
    {
        if (! $this->databaseIsDumpable()) {
            return ['db_file' => null, 'db_sha256' => null, 'db_tables' => 0, 'db_rows' => 0];
        }
        $file = rtrim($backupDir, '/').'/database.sql';
        $pdo = ($this->pdoResolver)();
        $meta = (new DatabaseBackup())->dump($pdo, $file);

        return [
            'db_file' => $file,
            'db_sha256' => $meta['sha256'],
            'db_tables' => $meta['tables'],
            'db_rows' => $meta['rows'],
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
            $db = (new DatabaseBackup())->verify((string) $dbFile, (string) ($refs['db_sha256'] ?? '') ?: null);
            if (! $db['ok']) {
                return ['ok' => false, 'reason' => 'database backup invalid: '.$db['reason']];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    // ---- 4. apply (stage → maintenance → replace → migrate → cache → health) --

    /**
     * Execute the upgrade. Rejects unless a verified backup exists (§20/§7) and
     * this attempt owns the lock. Any failure AFTER live mutation begins triggers
     * automatic recovery and records a failed (never falsely completed) status.
     *
     * @return array{ok:bool, reason:string, health?:array<string,mixed>, recovered?:bool}
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
        if (! $this->lock->acquire($id)) {
            return ['ok' => false, 'reason' => 'another upgrade is already running'];
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
                $status = UpgradeState::STAGED;
            }

            // Maintenance ON (only after everything verified, §23).
            $this->enterMaintenance();
            $this->store->transition($id, UpgradeState::MAINTENANCE, 'Maintenance mode enabled');

            // Replace-not-merge + stale removal. The target's shipped ownership
            // record is authoritative for the new model + owned-file map.
            $this->store->transition($id, UpgradeState::REPLACING, 'Replacing Core release state');
            $oldOwned = $this->recordedOwnership();
            $packageCore = $this->packageCoreManifest($id);
            $newOwned = \is_array($packageCore['files'] ?? null) ? $packageCore['files'] : [];
            $applyModel = (\is_array($packageCore['ownership'] ?? null) && $packageCore['ownership'] !== [])
                ? $packageCore['ownership'] : $model;
            $engine = new UpgradeApplyEngine($this->root);
            $applied = $engine->apply($this->stagingDir($id), $applyModel, $oldOwned, $newOwned);
            $this->store->patch($id, ['applied' => [
                'replaced_dirs' => \count($applied['replaced_dirs']),
                'replaced_files' => \count($applied['replaced_files']),
                'removed_stale' => \count($applied['removed_stale']),
            ]]);
            // Persist the new ownership as the site's record for the next upgrade.
            $this->writeRecordedOwnership($id, $applyModel, $newOwned, $target);

            // Migrations (§37).
            $this->store->transition($id, UpgradeState::MIGRATING, 'Running migrations');
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

            // Finish.
            $this->exitMaintenance();
            $this->store->transition($id, UpgradeState::COMPLETED, 'Upgrade completed', ['health' => $health]);
            $this->lock->release($id);

            return ['ok' => true, 'reason' => 'ok', 'health' => $health];
        } catch (Throwable $e) {
            return $this->recover($id, $e->getMessage(), null);
        }
    }

    // ---- 5. rollback / recovery ----------------------------------------

    /**
     * Automatic recovery after a post-mutation failure (§41/§42): restore Core
     * files + (when a dump exists) the database, drop maintenance, and record a
     * FAILED status with an explicit recovery result. Never claims completed.
     *
     * @param  array<string,mixed>|null  $health
     * @return array{ok:false, reason:string, recovered:bool, health?:array<string,mixed>}
     */
    public function recover(string $id, string $reason, ?array $health): array
    {
        $state = $this->store->load($id) ?? [];
        $status = (string) ($state['status'] ?? '');
        $model = $this->ownershipModel();
        $recovered = true;
        $notes = [];

        if (UpgradeState::hasMutatedLiveState($status)) {
            $this->safeTransition($id, UpgradeState::ROLLBACK, 'Recovering: '.$reason);
            try {
                (new CoreBackup($this->root))->restoreFiles($this->backupDir($id), $model);
                $notes[] = 'files restored';
            } catch (Throwable $e) {
                $recovered = false;
                $notes[] = 'file restore failed';
            }
            $dbRestore = $this->restoreDatabase($id);
            $notes[] = $dbRestore;
            if (str_contains($dbRestore, 'failed') || str_contains($dbRestore, 'manual')) {
                $recovered = $recovered && ! str_contains($dbRestore, 'failed');
            }
        }

        $this->exitMaintenance();
        $this->safeTransition($id, UpgradeState::FAILED, $reason.' — recovery: '.implode('; ', $notes), [
            // Record the stage where the failure actually occurred, not ROLLBACK.
            'failure_stage' => $status,
            'recovered' => $recovered,
            'recovery_notes' => $notes,
            'health' => $health,
        ]);
        $this->lock->release($id);

        return ['ok' => false, 'reason' => $reason, 'recovered' => $recovered, 'health' => $health];
    }

    /** @return string human recovery note (never throws) */
    private function restoreDatabase(string $id): string
    {
        $refs = ($this->store->load($id)['backup'] ?? []);
        $dbFile = $refs['db_file'] ?? null;
        if ($dbFile === null || ! is_file((string) $dbFile)) {
            return 'database restore: manual recovery required (no dump)';
        }
        if (! $this->databaseIsDumpable()) {
            return 'database restore: manual recovery required';
        }
        try {
            (new DatabaseBackup())->restore(($this->pdoResolver)(), (string) $dbFile);

            return 'database restored';
        } catch (Throwable) {
            return 'database restore failed';
        }
    }

    // ---- interrupted upgrade detection (§44) ---------------------------

    /**
     * Describe the current recoverable situation for the /upgrade landing page.
     *
     * @return array{has_active:bool, state:?array<string,mixed>, recommendation:string}
     */
    public function situation(): array
    {
        $active = $this->active();
        if ($active === null) {
            return ['has_active' => false, 'state' => null, 'recommendation' => 'start'];
        }
        $status = (string) ($active['status'] ?? '');
        $rec = UpgradeState::hasMutatedLiveState($status) ? 'recover' : 'resume';

        return ['has_active' => true, 'state' => $active, 'recommendation' => $rec];
    }

    /** Abandon a pre-mutation attempt (§44): mark failed + free the lock. */
    public function discard(string $id): void
    {
        $this->safeTransition($id, UpgradeState::FAILED, 'Discarded by administrator');
        $this->lock->release($id);
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

    private function databaseIsDumpable(): bool
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

    private function writeRecordedOwnership(string $id, array $model, array $newOwned, string $version): void
    {
        $file = $this->root.'/'.self::CORE_MANIFEST_FILE;
        @file_put_contents($file, (string) json_encode([
            'product' => 'TNCMS',
            'version' => $version,
            'generated_at' => gmdate('c'),
            'ownership' => $model,
            'files' => $newOwned,
            'file_count' => \count($newOwned),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
            'db_tables' => $refs['db_tables'] ?? 0,
            'db_rows' => $refs['db_rows'] ?? 0,
            'files_zip' => $refs['files_zip'] ?? null,
            'files_sha256' => $refs['files_sha256'] ?? null,
            'files_count' => $refs['files_count'] ?? 0,
            'env_file' => $refs['env_file'] ?? null,
            'env_sha256' => $refs['env_sha256'] ?? null,
        ];
    }

    private function failIfPossible(string $id, string $reason): void
    {
        $this->safeTransition($id, UpgradeState::FAILED, $reason);
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

    private function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).$u[$i];
    }
}
