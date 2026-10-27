<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;
use ZipArchive;

/**
 * Core file + .env backup authority (CORE-UPGRADE-1, §17/§18/§19/§20/§41).
 *
 * The SINGLE file-backup implementation (no per-directory one-off code). It
 * snapshots every LIVE Core-owned file — the exact set the apply engine may
 * replace or remove — into a protected backup zip with a checksum manifest, and
 * backs up the live .env separately (protected, never logged). Both are required
 * inputs to the hard backup-verification gate and to automatic file rollback.
 *
 * Ownership CLASSIFICATION is delegated to {@see CoreOwnership} (the one
 * authority); this class only provides the efficient walk of the replace roots
 * and the archive/restore mechanics. Database backup is a separate certified
 * authority ({@see DatabaseBackup}).
 *
 * Path convention: forward-slash, relative to $root.
 */
final class CoreBackup
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $base = $root ?? (\function_exists('base_path') ? base_path() : getcwd());
        $this->root = rtrim(str_replace('\\', '/', (string) $base), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Snapshot live Core-owned files + .env into $backupDir.
     *
     * @param  array<string,mixed>  $model  ownership model (core_ownership)
     * @return array{files_zip:string, files_manifest:string, files_sha256:string,
     *               files_count:int, env_file:?string, env_sha256:?string, bytes:int}
     */
    public function backup(string $backupDir, array $model): array
    {
        $backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
        if (! is_dir($backupDir) && ! @mkdir($backupDir, 0775, true) && ! is_dir($backupDir)) {
            throw new RuntimeException("cannot create backup dir: $backupDir");
        }

        $owned = $this->liveOwnedFiles($model); // rel => sha256
        $zipPath = $backupDir.'/core-files.zip';
        @unlink($zipPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("cannot create backup archive: $zipPath");
        }
        foreach (array_keys($owned) as $rel) {
            $abs = $this->root.'/'.$rel;
            if (is_file($abs)) {
                $zip->addFile($abs, $rel);
            }
        }
        // close() is where libzip writes the archive: on a full volume it fails here.
        if (! $zip->close()) {
            @unlink($zipPath);
            throw new UpgradeStorageException('backup archive could not be finalized', 'close_failed');
        }

        $manifestPath = $backupDir.'/core-files.manifest.json';
        DurableFile::replace($manifestPath, (string) json_encode([
            'generated_at' => gmdate('c'),
            'file_count' => \count($owned),
            'files' => $owned,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'backup manifest');

        // .env — protected, byte-for-byte, never logged.
        $envSrc = $this->root.'/.env';
        $envDest = null;
        $envSha = null;
        if (is_file($envSrc)) {
            $envDest = $backupDir.'/env.backup';
            $envBytes = @file_get_contents($envSrc);
            if (! \is_string($envBytes)) {
                throw new RuntimeException('cannot back up .env');
            }
            DurableFile::replace($envDest, $envBytes, '.env backup');
            $envSha = (string) hash_file('sha256', $envDest);
            if (! hash_equals(hash('sha256', $envBytes), $envSha)) {
                throw new RuntimeException('cannot back up .env');
            }
        }

        return [
            'files_zip' => $zipPath,
            'files_manifest' => $manifestPath,
            'files_sha256' => (string) hash_file('sha256', $zipPath),
            'files_count' => \count($owned),
            'env_file' => $envDest,
            'env_sha256' => $envSha,
            'bytes' => (int) filesize($zipPath) + ($envDest !== null ? (int) filesize($envDest) : 0),
        ];
    }

    /**
     * Hard structural verification of a produced file/.env backup (§20). The DB
     * portion is verified by {@see DatabaseBackup::verify()} at the manager level.
     *
     * @param  array<string,mixed>  $refs  the array returned by backup()
     * @return array{ok:bool, reason:string}
     */
    public function verify(array $refs): array
    {
        $zip = (string) ($refs['files_zip'] ?? '');
        if ($zip === '' || ! is_file($zip) || ! is_readable($zip)) {
            return ['ok' => false, 'reason' => 'file backup missing or unreadable'];
        }
        if (! hash_equals((string) ($refs['files_sha256'] ?? ''), (string) hash_file('sha256', $zip))) {
            return ['ok' => false, 'reason' => 'file backup checksum mismatch'];
        }
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            return ['ok' => false, 'reason' => 'file backup is not a valid archive'];
        }
        $entries = $za->numFiles;
        $expected = (int) ($refs['files_count'] ?? 0);
        if ($expected > 0 && $entries < $expected) {
            $za->close();

            return ['ok' => false, 'reason' => "file backup incomplete ($entries/$expected)"];
        }

        $manifest = (string) ($refs['files_manifest'] ?? '');
        $listed = $manifest !== '' && is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        if (! \is_array($listed) || ! \is_array($listed['files'] ?? null) || \count($listed['files']) !== (int) ($listed['file_count'] ?? -1)) {
            $za->close();

            return ['ok' => false, 'reason' => 'file backup manifest missing or corrupt'];
        }
        foreach (array_keys($listed['files']) as $rel) {
            if ($za->locateName((string) $rel) === false) {
                $za->close();

                return ['ok' => false, 'reason' => 'file backup manifest lists a file the archive lacks'];
            }
        }
        $za->close();

        $envFile = $refs['env_file'] ?? null;
        if ($envFile !== null) {
            if (! is_file((string) $envFile) || ! is_readable((string) $envFile)) {
                return ['ok' => false, 'reason' => '.env backup missing or unreadable'];
            }
            if (! hash_equals((string) ($refs['env_sha256'] ?? ''), (string) hash_file('sha256', (string) $envFile))) {
                return ['ok' => false, 'reason' => '.env backup checksum mismatch'];
            }
        }

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Restore Core-owned files from a backup archive over the live root (§41).
     * Every restored path is re-asserted Core-owned and non-preserved before it
     * touches disk. Returns the number of files restored.
     */
    public function restoreFiles(string $backupDir, array $model): int
    {
        $zipPath = rtrim(str_replace('\\', '/', $backupDir), '/').'/core-files.zip';
        if (! is_file($zipPath)) {
            throw new RuntimeException("backup archive not found: $zipPath");
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("cannot open backup archive: $zipPath");
        }
        $restored = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $rel = CoreOwnership::normalize((string) $zip->getNameIndex($i));
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            // Never restore outside Core ownership (defence in depth).
            if (! CoreOwnership::isCoreOwned($rel, $model) || CoreOwnership::isPreserved($rel, $model)) {
                continue;
            }
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                continue;
            }
            $dest = $this->root.'/'.$rel;
            $dir = \dirname($dest);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (@file_put_contents($dest, $data) !== false) {
                $restored++;
            }
        }
        $zip->close();

        return $restored;
    }

    /**
     * Make the live Core-owned tree EXACTLY equal $baseline (rel => sha256) and
     * verify it (CORE-UPGRADE-WIN-R1). Files absent from the baseline were created
     * during the upgrade (cache / asset regeneration, promoted files) and are
     * removed; changed or missing files are rewritten from the verified backup
     * archive via temp-file + rename. Never writes outside Core ownership.
     *
     * @param  array<string,string>  $baseline
     * @param  array<string,mixed>   $model
     * @return array{restored:int, removed:int, problems:list<array<string,string>>}
     */
    public function reconcile(array $baseline, string $zipPath, array $model): array
    {
        $problems = [];
        $restored = 0;
        $removed = 0;
        $live = $this->liveOwnedFiles($model);

        foreach ($live as $rel => $_sha) {
            if (isset($baseline[$rel]) || ! CoreOwnership::isCoreOwned($rel, $model)) {
                continue;
            }
            if (@unlink($this->root.'/'.$rel)) {
                $removed++;
            } else {
                $problems[] = ['rel' => $rel, 'reason' => 'file created during the upgrade could not be removed'];
            }
        }

        $zip = null;
        $guard = new UpgradePathGuard;
        foreach ($baseline as $rel => $sha) {
            $rel = (string) $rel;
            if (($live[$rel] ?? null) === $sha || ! CoreOwnership::isCoreOwned($rel, $model)) {
                continue;
            }
            // baseline.json is on-disk state: re-prove every path before writing through it.
            if (($why = UpgradePathGuard::unsafeReason($rel) ?? $guard->reparseReason($this->root, $rel)) !== null) {
                $problems[] = ['rel' => $rel, 'reason' => "refused to restore an unsafe recorded path ($why)"];

                continue;
            }
            if ($zip === null) {
                $zip = new ZipArchive();
                if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
                    return ['restored' => $restored, 'removed' => $removed, 'problems' => array_merge($problems, [['rel' => '*', 'reason' => 'backup archive unavailable']])];
                }
            }
            $data = $zip->getFromName($rel);
            if ($data === false || ! hash_equals($sha, hash('sha256', $data))) {
                $problems[] = ['rel' => $rel, 'reason' => 'no verified copy in the backup archive'];

                continue;
            }
            $dest = $this->root.'/'.$rel;
            if (! is_dir(\dirname($dest))) {
                @mkdir(\dirname($dest), 0775, true);
            }
            $tmp = $dest.'.tncms-restore';
            if (@file_put_contents($tmp, $data) === false || ! @rename($tmp, $dest)) {
                @unlink($tmp);
                $problems[] = ['rel' => $rel, 'reason' => 'original could not be written back (file may be locked)'];

                continue;
            }
            $restored++;
        }
        if ($zip instanceof ZipArchive && $zip->filename !== '') {
            $zip->close();
        }

        if ($problems === []) {
            $after = $this->liveOwnedFiles($model);
            foreach (array_unique(array_merge(array_keys($after), array_keys($baseline))) as $rel) {
                if (($after[$rel] ?? null) !== ($baseline[$rel] ?? null)) {
                    $problems[] = ['rel' => $rel, 'reason' => 'live file differs from the pre-upgrade baseline'];
                }
            }
        }

        return ['restored' => $restored, 'removed' => $removed, 'problems' => $problems];
    }

    /**
     * Enumerate LIVE Core-owned files by walking only the replace roots/files
     * (never the whole tree, so storage/uploads/.env are never touched), each
     * confirmed via the ownership authority.
     *
     * @param  array<string,mixed>  $model
     * @return array<string,string> rel => sha256 (sorted)
     */
    public function liveOwnedFiles(array $model): array
    {
        $out = [];

        foreach ($model['replace_dirs'] ?? [] as $dir) {
            $abs = $this->root.'/'.rtrim(CoreOwnership::normalize($dir), '/');
            if (! is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $rel = CoreOwnership::normalize(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root) + 1));
                if (CoreOwnership::isCoreOwned($rel, $model)) {
                    $out[$rel] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }

        foreach ($model['replace_files'] ?? [] as $rel) {
            $rel = CoreOwnership::normalize($rel);
            $abs = $this->root.'/'.$rel;
            if (is_file($abs) && CoreOwnership::isCoreOwned($rel, $model)) {
                $out[$rel] = (string) hash_file('sha256', $abs);
            }
        }

        ksort($out);

        return $out;
    }
}
