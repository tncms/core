<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Str;
use RuntimeException;
use TheNguyen\CMS\Exceptions\ExtensionInstallBusyException;
use Throwable;

/**
 * One staged, exception-safe install/overwrite of an extension unit
 * (CORE-PLUGIN-UPGRADE-ATOMIC-ROLLBACK-R1; state machine in
 * packages/thenguyen/cms-core/docs/extension-install/ATOMIC-EXTENSION-INSTALL.md).
 *
 * The unit is the live directory `<root>/<slug>` plus, for a plugin declaring public assets, the owned
 * namespace `<assets>/<slug>`. Every swap is a same-volume rename through private, slug-scoped,
 * random transaction directories that sit beside their target (`<root>/.tncms-txn`,
 * `<assets>/.tncms-txn`) and are invisible to discovery (dot-prefixed). Until {@see commit()} the
 * previous unit is kept intact in `…/previous` and is the rollback authority.
 *
 * Exception-safe only: there is no journal, so a killed process leaves the previous copy in
 * `…/previous` for manual recovery (documented), not an automatic restore.
 */
final class ExtensionInstallTransaction
{
    public const TXN_DIR = '.tncms-txn';

    private const STATE_OPEN = 'open';

    private const STATE_COMMITTED = 'committed';

    private const STATE_ROLLED_BACK = 'rolled_back';

    private string $state = self::STATE_OPEN;

    private readonly string $id;

    private readonly string $txnDir;

    /** @var resource|null */
    private $lock = null;

    private bool $promoted = false;

    private ?string $assetNamespace = null;

    private ?string $assetTxnDir = null;

    private bool $assetsPromoted = false;

    /**
     * @param  string  $root  the real extension type root (plugins/ or themes/)
     */
    private function __construct(
        private readonly ExtensionInstallFilesystem $fs,
        private readonly string $root,
        private readonly string $slug,
    ) {
        $this->id = strtolower(Str::random(12));
        $this->txnDir = $this->txnRoot().DIRECTORY_SEPARATOR.$this->slug.'.'.$this->id;
    }

    /**
     * Acquire the per-slug lock and open a transaction. Throws when another install of the same slug
     * holds the lock or the Core-owned transaction root is not a plain contained directory.
     */
    public static function begin(ExtensionInstallFilesystem $fs, string $root, string $slug): self
    {
        $txn = new self($fs, $root, $slug);
        $txn->acquireLock();

        return $txn;
    }

    public function livePath(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$this->slug;
    }

    /** Copy the validated candidate into private staging. Nothing live is touched. */
    public function stage(string $candidateRoot): void
    {
        $this->assertOpen();
        $this->ensurePlainDirectory($this->txnRoot(), $this->root);
        $this->ensurePlainDirectory($this->txnDir, $this->txnRoot());
        $this->fs->copyDirectory($candidateRoot, $this->path('candidate'));
    }

    /** Back up the previous live directory (if any) and promote the staged candidate. */
    public function promote(): void
    {
        $this->assertOpen();

        if ($this->fs->exists($this->livePath())) {
            $this->fs->move($this->livePath(), $this->path('previous'));
        }

        $this->fs->move($this->path('candidate'), $this->livePath());
        $this->promoted = true;
    }

    /**
     * Stage the promoted candidate's declared assets, back up the previous owned namespace (if any)
     * and promote the staged copy. `$realSource` comes from {@see PluginAssetPublisher::sourceFor()}
     * resolved against the live (promoted) directory.
     */
    public function publishAssets(string $realSource, string $assetBase): void
    {
        $this->assertOpen();

        $this->assetNamespace = $assetBase.DIRECTORY_SEPARATOR.$this->slug;
        $this->assetTxnDir = $assetBase.DIRECTORY_SEPARATOR.self::TXN_DIR.DIRECTORY_SEPARATOR.$this->slug.'.'.$this->id;
        $this->ensurePlainDirectory($assetBase.DIRECTORY_SEPARATOR.self::TXN_DIR, $assetBase);
        $this->ensurePlainDirectory($this->assetTxnDir, $assetBase.DIRECTORY_SEPARATOR.self::TXN_DIR);

        $this->fs->copyDirectory($realSource, $this->assetPath('candidate'));
        if ($this->countFiles($realSource) !== $this->countFiles($this->assetPath('candidate'))) {
            throw new RuntimeException('Staged asset copy is incomplete.');
        }

        if ($this->fs->exists($this->assetNamespace)) {
            $this->fs->move($this->assetNamespace, $this->assetPath('previous'));
        }

        $this->fs->move($this->assetPath('candidate'), $this->assetNamespace);
        $this->assetsPromoted = true;
    }

    /**
     * The commit point: the candidate unit is live. Removes backups/staging best-effort and returns
     * user-safe warnings for anything left behind (never rolls back a committed install).
     *
     * @return list<string>
     */
    public function commit(): array
    {
        $this->assertOpen();
        $this->state = self::STATE_COMMITTED;

        $warnings = [];
        foreach (array_filter([$this->txnDir, $this->assetTxnDir]) as $dir) {
            try {
                $this->fs->deleteDirectory($dir);
            } catch (Throwable $e) {
                report($e);
                $warnings[] = 'Installed successfully, but a previous-version backup could not be removed: '.$this->residueLabel($dir).'. It is safe to delete.';
            }
        }

        $this->removeEmptyTxnRoots();
        $this->releaseLock();

        return $warnings;
    }

    /**
     * Restore the previous unit (or remove a failed fresh install). Returns true when everything was
     * restored and staging removed; false when a restore step failed — the residue (including the
     * untouched previous copy) is then deliberately kept for recovery.
     */
    public function rollback(): bool
    {
        if ($this->state !== self::STATE_OPEN) {
            return true;
        }
        $this->state = self::STATE_ROLLED_BACK;

        $restored = true;
        if ($this->assetNamespace !== null && $this->assetTxnDir !== null) {
            $restored = $this->restore($this->assetNamespace, $this->assetPath('previous'), $this->assetPath('failed'), $this->assetsPromoted);
        }
        $restored = $this->restore($this->livePath(), $this->path('previous'), $this->path('failed'), $this->promoted) && $restored;

        if ($restored) {
            foreach (array_filter([$this->txnDir, $this->assetTxnDir]) as $dir) {
                try {
                    $this->fs->deleteDirectory($dir);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        $this->removeEmptyTxnRoots();
        $this->releaseLock();

        return $restored;
    }

    /** User-safe identification of a Core-owned residue directory (no absolute path). */
    public function residueLabel(?string $dir = null): string
    {
        $dir ??= $this->txnDir;

        return basename(dirname($dir)).'/'.basename($dir);
    }

    // -----------------------------------------------------------------------------------------------

    /**
     * Put `$backup` back at `$live`. When the transaction placed something at `$live`, move it aside
     * first (and drop it); with no backup, a promoted fresh install is simply removed.
     */
    private function restore(string $live, string $backup, string $failed, bool $promoted): bool
    {
        try {
            if ($this->fs->exists($backup)) {
                if ($this->fs->exists($live)) {
                    $this->fs->move($live, $failed);
                }
                $this->fs->move($backup, $live);

                return true;
            }

            if ($promoted && $this->fs->exists($live)) {
                $this->fs->move($live, $failed);
            }

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The per-slug lock file. It lives in Core's private storage (never beside the extensions), keyed
     * by the real extension root + slug, so different roots/slugs never share a lock and the lock
     * leaves no residue in plugins/, themes/ or the public asset base.
     */
    public static function lockPathFor(string $root, string $slug): string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'tncms-installer-locks');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $realRoot = realpath($root);

        return $dir.DIRECTORY_SEPARATOR.substr(hash('sha256', ($realRoot === false ? $root : $realRoot).'
'.$slug), 0, 32).'.lock';
    }

    private function acquireLock(): void
    {
        $handle = @fopen(self::lockPathFor($this->root, $this->slug), 'c');
        if ($handle === false) {
            throw new RuntimeException('The extension install lock could not be opened.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new ExtensionInstallBusyException("Another installation of \"{$this->slug}\" is already in progress. Try again shortly.");
        }

        $this->lock = $handle;
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * Create `$dir` (if absent) and fail closed unless it is a real, non-link directory strictly inside
     * `$parent` — cleanup must never follow a planted link out of the Core-owned roots.
     */
    private function ensurePlainDirectory(string $dir, string $parent): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('The extension transaction directory could not be created.');
        }

        $realDir = realpath($dir);
        $realParent = realpath($parent);
        if (is_link($dir) || $realDir === false || $realParent === false
            || ! str_starts_with($realDir, $realParent.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The extension transaction directory is not a contained plain directory.');
        }
    }

    /**
     * Drop the shared `.tncms-txn` roots when nothing (no other transaction, no kept residue) is in
     * them. `rmdir()` refuses a non-empty directory, so a concurrent transaction of another slug is
     * never affected.
     */
    private function removeEmptyTxnRoots(): void
    {
        foreach (array_filter([$this->txnRoot(), $this->assetTxnDir !== null ? dirname($this->assetTxnDir) : null]) as $dir) {
            if (is_dir($dir) && ! is_link($dir)) {
                @rmdir($dir);
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->state !== self::STATE_OPEN) {
            throw new RuntimeException('The extension install transaction is already closed.');
        }
    }

    private function txnRoot(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.self::TXN_DIR;
    }

    private function path(string $name): string
    {
        return $this->txnDir.DIRECTORY_SEPARATOR.$name;
    }

    private function assetPath(string $name): string
    {
        return (string) $this->assetTxnDir.DIRECTORY_SEPARATOR.$name;
    }

    private function countFiles(string $dir): int
    {
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    public function __destruct()
    {
        // A transaction abandoned by an unexpected throw must not keep the slug locked.
        $this->releaseLock();
    }
}
