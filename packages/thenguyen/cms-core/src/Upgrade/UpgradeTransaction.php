<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use Throwable;

/**
 * Journaled, file-level Core promotion transaction (CORE-UPGRADE-WIN-R1).
 *
 * Replaces the whole-directory rename of 7.1.35 and earlier, which failed on
 * Windows whenever any process held a handle under a Core root (NTFS refuses to
 * rename a directory with open descendants) and then fell back to recursively
 * deleting the live tree in place.
 *
 *  plan()     validates the ownership record, staged bytes and live paths and
 *             derives per-FILE operations (add / replace / delete). Nothing on
 *             the live tree is touched; a refusal is REJECTED.
 *  execute()  1) PRESERVE: rename every live file to be replaced or deleted into
 *                the transaction's `prior/` area (same volume) and verify its
 *                SHA-256 against the plan — locks are discovered before any
 *                candidate is promoted;
 *             2) PROMOTE: rename each staged file into its (now vacant) live
 *                path; on a rename failure, a bounded fallback copies it to a
 *                temp file in the destination directory, verifies the bytes and
 *                renames that into place. Never an in-place overwrite.
 *             Any failure rolls back and is reported with a VERIFIED outcome.
 *  rollback() is driven by the plan plus the OBSERVED filesystem, so it is
 *             idempotent and also recovers a transaction whose PHP process died
 *             at any point (crash, timeout, killed worker).
 *  finalize() runs only after migrations, ownership record, version and health
 *             have all succeeded; it prunes emptied Core directories and drops
 *             the preserved copies. A cleanup failure never undoes the commit.
 *
 * Directories are never moved or recursively deleted, so a Core root that also
 * holds operator files (or a junction) keeps them. Dependency-free besides its
 * own Upgrade/* collaborators, which preload() loads BEFORE the first mutation
 * so no autoload ever reads a half-promoted tree.
 */
final class UpgradeTransaction
{
    public const SCHEMA = 1;

    private const PLAN_FILE = 'plan.json';

    private const BASELINE_FILE = 'baseline.json';

    private const JOURNAL_FILE = 'journal.jsonl';

    private const PRIOR_DIR = 'prior';

    private const RENAME_ATTEMPTS = 3;

    private const RETRY_DELAY_US = 200_000;

    private const HEARTBEAT_EVERY = 250;

    /**
     * Paths no package can claim, whatever ownership model it ships: site
     * secrets, persistent state and user uploads (a tampered or buggy model must
     * not be able to widen Core ownership over them).
     */
    private const PRESERVE_FLOOR = ['.env', '.git', 'storage', 'public/uploads'];

    private string $root;

    private string $dir;

    private string $tag;

    private UpgradeJournal $journal;

    /** @var (callable(string,int,array<string,mixed>):void)|null test/diagnostic seam */
    private $fault;

    /** @var (callable():void)|null keeps the upgrade lock fresh during long work */
    private $heartbeat;

    private int $beats = 0;

    /** Last OS warning captured by {@see attempt()} — error_get_last() is empty under a framework error handler. */
    private string $lastError = '';

    public function __construct(string $root, string $txnDir, ?callable $fault = null, ?callable $heartbeat = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->dir = rtrim(str_replace('\\', '/', $txnDir), '/');
        $this->tag = substr(hash('sha256', $this->dir), 0, 10);
        $this->journal = new UpgradeJournal($this->dir.'/'.self::JOURNAL_FILE);
        $this->fault = $fault;
        $this->heartbeat = $heartbeat;
    }

    /** Load every class the mutation and recovery paths need, before the live tree changes. */
    public static function preload(): void
    {
        foreach ([CoreOwnership::class, CoreBackup::class, UpgradeOutcome::class, UpgradeTransactionException::class, UpgradeJournal::class, UpgradePathGuard::class] as $class) {
            class_exists($class);
        }
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function journal(): UpgradeJournal
    {
        return $this->journal;
    }

    /** True when this transaction began mutating and has not been finalized or verifiably rolled back. */
    public function isOpen(): bool
    {
        if (! $this->journal->isSafe()) {
            return true; // an unreadable (linked) journal cannot prove the transaction closed
        }
        try {
            $last = $this->journal->last('begin', 'finalized', 'rollback_end');
        } catch (\RuntimeException) {
            return true; // nor can one that cannot be opened; callers must still reach recovery, never crash
        }

        return $last !== null && (($last['ev'] ?? '') === 'begin'
            || (($last['ev'] ?? '') === 'rollback_end' && ($last['outcome'] ?? '') !== UpgradeOutcome::ROLLED_BACK_VERIFIED));
    }

    // ---- plan ----------------------------------------------------------

    /**
     * Derive and persist the per-file plan. Never mutates the live tree.
     *
     * @param  array<string,mixed>  $model  ownership model applied by this upgrade
     * @param  array<string,string>  $oldOwned  site's recorded ownership (rel => sha256)
     * @param  array<string,string>  $newOwned  package ownership (rel => sha256)
     * @return array<string,mixed>
     */
    public function plan(string $stagingDir, array $model, array $oldOwned, array $newOwned): array
    {
        self::preload();
        $staging = rtrim(str_replace('\\', '/', $stagingDir), '/');
        if (! is_dir($staging)) {
            $this->reject('the staged package is missing');
        }
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            $this->reject('the upgrade transaction area cannot be created');
        }
        if ($this->journal->has('begin')) {
            $this->reject('this transaction has already started');
        }
        $this->assertModelSafe($model);
        $this->assertSameVolume($staging);

        if ($newOwned === []) {
            $newOwned = CoreOwnership::ownedFiles($staging, $model);
        }
        if ($newOwned === []) {
            $this->reject('the package ownership record lists no Core files');
        }

        $guard = new UpgradePathGuard;
        $baseline = (new CoreBackup($this->root))->liveOwnedFiles($model);
        $folded = [];
        $ops = [];
        $unchanged = 0;

        foreach ($newOwned as $rel => $sha) {
            $rel = $this->checkedRel((string) $rel, $model, $folded);
            if (! \is_string($sha) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
                $this->reject("invalid checksum recorded for $rel");
            }
            $src = $staging.'/'.$rel;
            if (($why = $guard->reparseReason($staging, $rel)) !== null || ! is_file($src)) {
                $this->reject("staged entry is not a regular file: $rel".($why !== null ? " ($why)" : ''));
            }
            if (! hash_equals($sha, (string) hash_file('sha256', $src))) {
                $this->reject("staged checksum mismatch: $rel");
            }
            $live = $this->root.'/'.$rel;
            $this->assertLivePath($guard, $rel, $live);
            if (file_exists($live)) {
                $orig = $baseline[$rel] ?? (string) hash_file('sha256', $live);
                if (hash_equals($orig, $sha)) {
                    $unchanged++;

                    continue;
                }
                $ops[] = ['op' => 'replace', 'rel' => $rel, 'orig' => $orig, 'cand' => $sha];
            } else {
                $ops[] = ['op' => 'add', 'rel' => $rel, 'orig' => null, 'cand' => $sha];
            }
        }

        foreach (CoreOwnership::deriveObsolete($oldOwned, $newOwned, $model) as $rel) {
            $rel = $this->checkedRel($rel, $model, $folded);
            $live = $this->root.'/'.$rel;
            $this->assertLivePath($guard, $rel, $live);
            if (! file_exists($live)) {
                continue;
            }
            $ops[] = ['op' => 'delete', 'rel' => $rel, 'orig' => $baseline[$rel] ?? (string) hash_file('sha256', $live), 'cand' => null];
        }

        usort($ops, static fn (array $a, array $b): int => strcmp($a['rel'], $b['rel']));

        $touched = array_fill_keys(array_column($ops, 'rel'), true);
        $unmanaged = 0;
        foreach (array_keys($baseline) as $rel) {
            if (! isset($touched[$rel]) && ! isset($newOwned[$rel])) {
                $unmanaged++; // in a Core root, not shipped, not recorded: preserved untouched
            }
        }

        $counts = ['add' => 0, 'replace' => 0, 'delete' => 0];
        foreach ($ops as $op) {
            $counts[$op['op']]++;
        }
        $plan = [
            'schema' => self::SCHEMA,
            'created_at' => gmdate('c'),
            'staging' => $staging,
            'model' => $model,
            'ops' => $ops,
            'counts' => $counts + ['unchanged' => $unchanged, 'unmanaged_preserved' => $unmanaged],
        ];
        $this->writeDurable($this->dir.'/'.self::BASELINE_FILE, $baseline);
        $this->writeDurable($this->dir.'/'.self::PLAN_FILE, $plan);
        $this->journal->append('planned', $plan['counts']);

        return $plan;
    }

    /** @return array<string,mixed>|null */
    public function loadPlan(): ?array
    {
        $data = json_decode((string) @file_get_contents($this->dir.'/'.self::PLAN_FILE), true);

        return \is_array($data) && ($data['schema'] ?? null) === self::SCHEMA ? $data : null;
    }

    /** @return array<string,mixed>|null the ownership model the plan (and its baseline) was derived with */
    public function plannedModel(): ?array
    {
        $model = $this->loadPlan()['model'] ?? null;

        return \is_array($model) ? $model : null;
    }

    /** @return array<string,string> rel => sha256 of every Core-owned live file at plan time */
    public function baseline(): array
    {
        $data = json_decode((string) @file_get_contents($this->dir.'/'.self::BASELINE_FILE), true);

        return \is_array($data) ? $data : [];
    }

    // ---- execute -------------------------------------------------------

    /**
     * Preserve, then promote. On any failure the live tree is rolled back and an
     * UpgradeTransactionException carries the VERIFIED outcome.
     *
     * @param  array<string,mixed>  $plan
     * @return array{preserved:int, promoted:int, fallback_copies:int, retries:int}
     */
    public function execute(array $plan): array
    {
        self::preload();
        $stats = ['preserved' => 0, 'promoted' => 0, 'fallback_copies' => 0, 'retries' => 0];
        $phase = 'preserve';

        try {
            // Inside the try: a journal that cannot be written fails closed with a verified outcome.
            $this->journal->append('begin', ['ops' => \count($plan['ops'])]);
            foreach ($plan['ops'] as $i => $op) {
                if ($op['op'] === 'add') {
                    continue;
                }
                $this->hook('preserve', $i, $op);
                $live = $this->root.'/'.$op['rel'];
                $prior = $this->priorPath($op['rel']);
                $this->makeDir(\dirname($prior), false);
                $this->journal->append('preserve', ['i' => $i]);
                $this->renameOrFail($live, $prior, 'preserve', $op, $stats);
                if (! hash_equals((string) $op['orig'], (string) @hash_file('sha256', $prior))) {
                    throw $this->stepFailure('preserve', $op, 'the live file changed after the upgrade was planned', null, 1);
                }
                $this->journal->append('preserved', ['i' => $i]);
                $stats['preserved']++;
                $this->beat();
            }

            $phase = 'promote';
            foreach ($plan['ops'] as $i => $op) {
                if ($op['op'] === 'delete') {
                    continue;
                }
                $this->hook('promote', $i, $op);
                $live = $this->root.'/'.$op['rel'];
                $src = $plan['staging'].'/'.$op['rel'];
                $this->makeDir(\dirname($live), true);
                $this->journal->append('promote', ['i' => $i]);
                if (! $this->renameWithRetry($src, $live, $stats)) {
                    $this->promoteByVerifiedCopy($src, $live, $op, $stats);
                }
                if (! hash_equals((string) $op['cand'], (string) @hash_file('sha256', $live))) {
                    throw $this->stepFailure('promote', $op, 'the promoted file does not match the package checksum', null, 1);
                }
                $this->journal->append('promoted', ['i' => $i]);
                $stats['promoted']++;
                $this->beat();
            }
        } catch (Throwable $e) {
            $diag = $e instanceof UpgradeTransactionException ? $e->diagnostics : ['error' => $this->safe($e->getMessage())];
            $this->note('fs_failed', ['phase' => $phase] + $diag);
            $rollback = $this->rollback();

            throw new UpgradeTransactionException(
                $e instanceof UpgradeTransactionException ? $e->getMessage() : 'the Core files could not be promoted',
                $rollback['outcome'],
                $phase,
                $diag + ['rollback_problems' => $rollback['problems']],
            );
        }

        $this->journal->append('fs_applied', $stats);

        return $stats;
    }

    // ---- rollback ------------------------------------------------------

    /**
     * Restore every planned entry to its pre-upgrade bytes and verify it.
     * Idempotent; safe after a crash at any point of execute().
     *
     * @return array{outcome:string, problems:list<array<string,mixed>>}
     */
    public function rollback(): array
    {
        self::preload();
        $plan = $this->loadPlan();
        if ($plan === null) {
            // No plan → execute() never ran, so nothing was mutated.
            return $this->journal->has('begin')
                ? ['outcome' => UpgradeOutcome::RECOVERY_REQUIRED, 'problems' => [['reason' => 'transaction plan is missing']]]
                : ['outcome' => UpgradeOutcome::ROLLED_BACK_VERIFIED, 'problems' => []];
        }
        $this->note('rollback_begin');
        $problems = [];

        $guard = new UpgradePathGuard;
        foreach (array_reverse($plan['ops'], true) as $i => $op) {
            $why = UpgradePathGuard::unsafeReason((string) ($op['rel'] ?? '')) ?? $guard->reparseReason($this->root, (string) $op['rel']);
            if ($why !== null) {
                $problems[] = ['rel' => (string) ($op['rel'] ?? '?'), 'reason' => "refused to restore an unsafe recorded path ($why)"];

                continue;
            }
            try {
                $this->hook('rollback', $i, $op);
                $this->undo($op, (string) $plan['staging']);
            } catch (Throwable $e) {
                $problems[] = ['rel' => $op['rel'], 'reason' => $this->safe($e->getMessage())];
            }
        }
        $this->removeCreatedDirs();

        foreach ($plan['ops'] as $op) {
            $live = $this->root.'/'.$op['rel'];
            if ($op['op'] === 'add') {
                if (file_exists($live)) {
                    $problems[] = ['rel' => $op['rel'], 'reason' => 'target-added file is still present'];
                }
            } elseif (! hash_equals((string) $op['orig'], (string) @hash_file('sha256', $live))) {
                $problems[] = ['rel' => $op['rel'], 'reason' => 'original bytes not restored'];
            }
            if (is_file($this->tempPath($live))) {
                $problems[] = ['rel' => $op['rel'], 'reason' => 'temporary promotion file remains'];
            }
        }
        $problems = array_values(array_unique($problems, SORT_REGULAR));
        $outcome = $problems === [] ? UpgradeOutcome::ROLLED_BACK_VERIFIED : UpgradeOutcome::RECOVERY_REQUIRED;
        if ($outcome === UpgradeOutcome::ROLLED_BACK_VERIFIED) {
            $this->removeTree($this->dir.'/'.self::PRIOR_DIR);
        }
        $this->note('rollback_end', ['outcome' => $outcome, 'problems' => \count($problems)]);

        return ['outcome' => $outcome, 'problems' => $problems];
    }

    /**
     * Verify every promoted/deleted entry matches the plan (commit gate).
     *
     * @param  array<string,mixed>  $plan
     * @return list<array<string,string>>
     */
    public function verifyCommitted(array $plan): array
    {
        $problems = [];
        foreach ($plan['ops'] as $op) {
            $live = $this->root.'/'.$op['rel'];
            if ($op['op'] === 'delete') {
                if (file_exists($live)) {
                    $problems[] = ['rel' => $op['rel'], 'reason' => 'obsolete file still present'];
                }
            } elseif (! hash_equals((string) $op['cand'], (string) @hash_file('sha256', $live))) {
                $problems[] = ['rel' => $op['rel'], 'reason' => 'live file does not match the package'];
            }
        }

        return $problems;
    }

    // ---- finalize ------------------------------------------------------

    /**
     * Post-commit cleanup: prune Core directories emptied by deletions and drop
     * the preserved copies. Failures are reported, never thrown.
     *
     * @param  array<string,mixed>  $plan
     * @param  list<string>  $replaceRoots
     * @return array{cleanup_ok:bool, pending:list<string>}
     */
    public function finalize(array $plan, array $replaceRoots): array
    {
        $this->journal->append('finalized');
        $roots = array_map(static fn ($d) => rtrim(CoreOwnership::normalize((string) $d), '/'), $replaceRoots);
        foreach ($plan['ops'] as $op) {
            if ($op['op'] !== 'delete') {
                continue;
            }
            $dir = \dirname($op['rel']);
            while ($dir !== '.' && $dir !== '' && ! \in_array($dir, $roots, true) && $this->insideAny($dir, $roots)) {
                $abs = $this->root.'/'.$dir;
                if (UpgradePathGuard::isReparsePoint($abs) || ! is_dir($abs) || array_diff(scandir($abs) ?: [], ['.', '..']) !== []) {
                    break;
                }
                @rmdir($abs);
                $dir = \dirname($dir);
            }
        }
        $pending = [];
        $prior = $this->dir.'/'.self::PRIOR_DIR;
        $this->removeTree($prior);
        if (file_exists($prior)) {
            $pending[] = 'preserved copies (a file may still be held open by another process)';
        }

        return ['cleanup_ok' => $pending === [], 'pending' => $pending];
    }

    // ---- internals -----------------------------------------------------

    /** @param array<string,mixed> $op */
    private function undo(array $op, string $staging): void
    {
        $live = $this->root.'/'.$op['rel'];
        $prior = $this->priorPath($op['rel']);
        $src = $staging.'/'.$op['rel'];
        $tmp = $this->tempPath($live);
        if (is_file($tmp) && ! @unlink($tmp)) {
            throw new \RuntimeException('cannot remove a temporary promotion file');
        }

        if ($op['op'] === 'add') {
            if (file_exists($live)) {
                if (! hash_equals((string) $op['cand'], (string) @hash_file('sha256', $live))) {
                    throw new \RuntimeException('unexpected content at a target-added path; left untouched');
                }
                $this->evict($live, $src);
            }

            return;
        }

        if (! is_file($prior)) {
            return; // never moved (or already restored): verification decides
        }
        if (file_exists($live)) {
            $sha = (string) @hash_file('sha256', $live);
            if ($op['cand'] === null || ! hash_equals((string) $op['cand'], $sha)) {
                throw new \RuntimeException('unexpected content at a preserved path; left untouched');
            }
            $this->evict($live, $src);
        }
        $this->makeDir(\dirname($live), false);
        $stats = ['retries' => 0];
        if (! $this->renameWithRetry($prior, $live, $stats)) {
            throw new \RuntimeException('cannot move the preserved original back into place'.$this->codeSuffix());
        }
    }

    /** Move a promoted file out of the live tree (back to staging, else delete). */
    private function evict(string $live, string $src): void
    {
        $this->makeDir(\dirname($src), false);
        $stats = ['retries' => 0];
        if ($this->renameWithRetry($live, $src, $stats) || $this->attempt(static fn (): bool => unlink($live))) {
            return;
        }
        throw new \RuntimeException('cannot remove a promoted file'.$this->codeSuffix());
    }

    /** @param array<string,mixed> $op @param array<string,int> $stats */
    private function renameOrFail(string $from, string $to, string $step, array $op, array &$stats): void
    {
        if (! $this->renameWithRetry($from, $to, $stats)) {
            $err = $this->lastError;
            throw $this->stepFailure($step, $op, 'the file could not be moved; it may be locked by another program', $err, self::RENAME_ATTEMPTS, [
                'source_exists' => file_exists($from),
                'destination_exists' => file_exists($to),
            ]);
        }
    }

    /** @param array<string,int> $stats */
    private function renameWithRetry(string $from, string $to, array &$stats): bool
    {
        for ($attempt = 1; $attempt <= self::RENAME_ATTEMPTS; $attempt++) {
            if ($this->attempt(static fn (): bool => rename($from, $to))) {
                return true;
            }
            if ($attempt < self::RENAME_ATTEMPTS) {
                $stats['retries'] = ($stats['retries'] ?? 0) + 1;
                usleep(self::RETRY_DELAY_US);
            }
        }

        return false;
    }

    /** @param array<string,mixed> $op @param array<string,int> $stats */
    private function promoteByVerifiedCopy(string $src, string $live, array $op, array &$stats): void
    {
        $renameError = $this->lastError;
        $tmp = $this->tempPath($live);
        $this->journal->append('promote_copy', ['rel' => $op['rel']]);
        $copied = $this->attempt(static fn (): bool => copy($src, $tmp));
        if (! $copied || ! hash_equals((string) $op['cand'], (string) @hash_file('sha256', $tmp))) {
            // The copy's own OS error is the cause (e.g. "No space left on device"); the earlier
            // rename refusal is kept as context.
            $copyError = $copied ? 'copy verification failed (checksum mismatch)' : $this->lastError;
            @unlink($tmp);
            throw $this->stepFailure('promote', $op, 'the package file could not be placed (the disk may be full, or the file is locked)',
                $copyError, self::RENAME_ATTEMPTS, ['rename_error' => $renameError !== '' ? $this->safe($renameError) : null]);
        }
        if (! $this->renameWithRetry($tmp, $live, $stats)) {
            $err = $this->lastError;
            @unlink($tmp);
            throw $this->stepFailure('promote', $op, 'the package file could not be placed', $err, self::RENAME_ATTEMPTS);
        }
        $stats['fallback_copies']++;
    }

    /** Create $dir (and parents); journal each live directory created so rollback can remove it. */
    private function makeDir(string $dir, bool $live): void
    {
        if (is_dir($dir)) {
            return;
        }
        $missing = [];
        for ($d = $dir; $d !== '' && ! is_dir($d) && \strlen($d) > \strlen($this->root); $d = \dirname($d)) {
            if (file_exists($d) || UpgradePathGuard::isReparsePoint($d)) {
                throw new \RuntimeException('a file or link blocks a Core directory');
            }
            $missing[] = $d;
        }
        foreach (array_reverse($missing) as $d) {
            if ($live) {
                $this->journal->append('mkdir', ['rel' => $this->rel($d)]);
            }
            if (! @mkdir($d, 0775) && ! is_dir($d)) {
                throw new \RuntimeException('a Core directory cannot be created'.$this->codeSuffix());
            }
        }
    }

    private function removeCreatedDirs(): void
    {
        $dirs = [];
        foreach ($this->journal->events() as $row) {
            if (($row['ev'] ?? null) === 'mkdir' && \is_string($row['rel'] ?? null)) {
                $dirs[] = $row['rel'];
            }
        }
        foreach (array_reverse(array_unique($dirs)) as $rel) {
            if (UpgradePathGuard::unsafeReason($rel) !== null) {
                continue;
            }
            $abs = $this->root.'/'.$rel;
            if (is_dir($abs) && ! UpgradePathGuard::isReparsePoint($abs) && array_diff(scandir($abs) ?: [], ['.', '..']) === []) {
                @rmdir($abs);
            }
        }
    }

    /** @param array<string,mixed> $model */
    private function assertModelSafe(array $model): void
    {
        foreach (array_merge($model['replace_dirs'] ?? [], $model['replace_files'] ?? []) as $entry) {
            $rel = rtrim(CoreOwnership::normalize((string) $entry), '/');
            if (($why = UpgradePathGuard::unsafeReason($rel)) !== null) {
                $this->reject("unsafe ownership entry $rel ($why)");
            }
            if (CoreOwnership::isPreserved($rel, $model) || $this->underFloor($rel)) {
                $this->reject("refusing to touch preserved path: $rel");
            }
        }
    }

    private function underFloor(string $rel): bool
    {
        $folded = strtolower($rel);
        foreach (self::PRESERVE_FLOOR as $floor) {
            if ($folded === $floor || str_starts_with($folded, $floor.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $model
     * @param  array<string,string>  $folded  lower-case rel => rel, for collision detection
     */
    private function checkedRel(string $rel, array $model, array &$folded): string
    {
        if (($why = UpgradePathGuard::unsafeReason($rel)) !== null) {
            $this->reject("unsafe path in the ownership record ($why)");
        }
        if (CoreOwnership::isPreserved($rel, $model) || $this->underFloor($rel)) {
            $this->reject("refusing to touch preserved path: $rel");
        }
        if (! CoreOwnership::isCoreOwned($rel, $model)) {
            $this->reject("refusing to touch non-Core path: $rel");
        }
        $key = mb_strtolower($rel, 'UTF-8');
        if (isset($folded[$key]) && $folded[$key] !== $rel) {
            $this->reject("case-insensitive path collision: $rel / {$folded[$key]}");
        }
        $folded[$key] = $rel;

        return $rel;
    }

    private function assertLivePath(UpgradePathGuard $guard, string $rel, string $live): void
    {
        if (($why = $guard->reparseReason($this->root, $rel)) !== null) {
            $this->reject("live path $rel: $why");
        }
        if (($why = $guard->caseReason($this->root, $rel)) !== null) {
            $this->reject("live path $rel: $why");
        }
        if (file_exists($live) && ! is_file($live)) {
            $this->reject("live path is not a regular file: $rel");
        }
    }

    /** Renames are only atomic and copy-free within one volume. */
    private function assertSameVolume(string $staging): void
    {
        $dev = static fn (string $p): ?int => \is_array($s = @stat($p)) ? (int) $s['dev'] : null;
        $root = $dev($this->root);
        if ($root === null || $root !== $dev($staging) || $root !== $dev($this->dir)) {
            $this->reject('the staging area is not on the same volume as the site');
        }
    }

    private function priorPath(string $rel): string
    {
        return $this->dir.'/'.self::PRIOR_DIR.'/'.$rel;
    }

    private function tempPath(string $live): string
    {
        return $live.'.tncms-'.$this->tag.'.new';
    }

    private function rel(string $abs): string
    {
        return CoreOwnership::normalize(substr($abs, \strlen($this->root) + 1));
    }

    /** @param list<string> $roots */
    private function insideAny(string $rel, array $roots): bool
    {
        foreach ($roots as $r) {
            if (str_starts_with($rel, $r.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $data */
    private function writeDurable(string $file, array $data): void
    {
        $tmp = $file.'.tmp';
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fh = @fopen($tmp, 'wb');
        $ok = $fh !== false && @fwrite($fh, $json) === \strlen($json) && @fflush($fh);
        if ($ok && \function_exists('fsync')) {
            @fsync($fh);
        }
        if ($fh !== false) {
            fclose($fh);
        }
        if (! $ok || ! @rename($tmp, $file)) {
            @unlink($tmp);
            $this->reject('the upgrade transaction record cannot be written');
        }
    }

    /** Remove a transaction-owned tree without ever following a symlink or junction. */
    private function removeTree(string $dir): void
    {
        if (UpgradePathGuard::isReparsePoint($dir)) {
            @rmdir($dir) || @unlink($dir);

            return;
        }
        if (! is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $e) {
            $this->removeTree($dir.'/'.$e);
        }
        @rmdir($dir);
    }

    /**
     * Best-effort journal record on a failure/recovery path: a full disk or an
     * unwritable journal must never prevent the rollback itself.
     *
     * @param  array<string,mixed>  $data
     */
    private function note(string $event, array $data = []): void
    {
        try {
            $this->journal->append($event, $data);
        } catch (Throwable) {
            // recovery derives its truth from the plan + observed filesystem
        }
    }

    /** @param array<string,mixed> $op */
    private function hook(string $point, int $i, array $op): void
    {
        if ($this->fault !== null) {
            ($this->fault)($point, $i, $op);
        }
    }

    private function beat(): void
    {
        if ($this->heartbeat !== null && (++$this->beats % self::HEARTBEAT_EVERY) === 0) {
            ($this->heartbeat)();
        }
    }

    /**
     * @param  array<string,mixed>  $op
     * @param  array<string,mixed>  $extra
     */
    private function stepFailure(string $step, array $op, string $why, ?string $phpError, int $attempts, array $extra = []): UpgradeTransactionException
    {
        $code = null;
        if ($phpError !== null && preg_match('/\(code: (\d+)\)/', $phpError, $m) === 1) {
            $code = (int) $m[1];
        }

        return new UpgradeTransactionException(
            "Upgrade step '$step' failed for {$op['rel']}: $why.",
            UpgradeOutcome::RECOVERY_REQUIRED, // replaced by the verified rollback outcome in execute()
            $step,
            [
                'step' => $step,
                'op' => $op['op'],
                'rel' => $op['rel'],
                'attempts' => $attempts,
                'os_error' => $phpError !== null ? $this->safe($phpError) : null,
                'win32_code' => $code,
                'platform' => PHP_OS_FAMILY,
            ] + $extra,
        );
    }

    /**
     * Run one filesystem call with a scoped error handler so the OS reason
     * (e.g. "... (code: 32)") is captured even under Laravel's handler.
     */
    private function attempt(callable $call): bool
    {
        $this->lastError = '';
        set_error_handler(function (int $no, string $message): bool {
            $this->lastError = $message;

            return true;
        });
        try {
            return (bool) $call();
        } finally {
            restore_error_handler();
        }
    }

    private function codeSuffix(): string
    {
        $msg = $this->lastError;

        return preg_match('/\(code: (\d+)\)/', $msg, $m) === 1 ? " (OS code {$m[1]})" : '';
    }

    /** Strip absolute paths so a message is safe for protected logs and UI alike. */
    private function safe(string $message): string
    {
        $message = str_replace([$this->dir, $this->root, str_replace('/', '\\', $this->dir), str_replace('/', '\\', $this->root)], ['<txn>', '<root>', '<txn>', '<root>'], $message);
        // Same rules as UpgradeManager::safeMessage(): UNC, drive letter, then POSIX absolute paths.
        $message = (string) preg_replace('#\\\\\\\\[^\s,)]+#', '<path>', $message);
        $message = (string) preg_replace('#[A-Za-z]:[\\\\/][^\s,)]*#', '<path>', $message);

        return (string) preg_replace('#(?<![\w.])/(?:[\w.-]+/)+[\w.-]*#', '<path>', $message);
    }

    private function reject(string $why): never
    {
        try {
            if (is_dir($this->dir)) {
                $this->journal->append('rejected', ['reason' => $why]);
            }
        } catch (Throwable) {
            // the refusal itself must still surface
        }

        throw new UpgradeTransactionException("The upgrade was refused before any change: $why.", UpgradeOutcome::REJECTED, 'plan', ['reason' => $why]);
    }
}
