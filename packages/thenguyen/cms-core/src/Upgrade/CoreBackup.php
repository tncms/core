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
        $zip->close();

        $manifestPath = $backupDir.'/core-files.manifest.json';
        file_put_contents($manifestPath, (string) json_encode([
            'root' => $this->root,
            'generated_at' => gmdate('c'),
            'file_count' => \count($owned),
            'files' => $owned,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // .env — protected, byte-for-byte, never logged.
        $envSrc = $this->root.'/.env';
        $envDest = null;
        $envSha = null;
        if (is_file($envSrc)) {
            $envDest = $backupDir.'/env.backup';
            if (! @copy($envSrc, $envDest)) {
                throw new RuntimeException('cannot back up .env');
            }
            $envSha = (string) hash_file('sha256', $envDest);
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
        $za->close();
        $expected = (int) ($refs['files_count'] ?? 0);
        if ($expected > 0 && $entries < $expected) {
            return ['ok' => false, 'reason' => "file backup incomplete ($entries/$expected)"];
        }

        $manifest = (string) ($refs['files_manifest'] ?? '');
        if ($manifest === '' || ! is_file($manifest)) {
            return ['ok' => false, 'reason' => 'file backup manifest missing'];
        }

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
