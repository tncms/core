<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The three filesystem operations the staged extension install transaction is built from
 * (CORE-PLUGIN-UPGRADE-ATOMIC-ROLLBACK-R1). Every operation either fully succeeds or THROWS — never a
 * silent `false` — so {@see ExtensionInstallTransaction} can roll back on any failure.
 *
 * `move()` is a plain same-volume `rename()`: there is deliberately no copy+delete fallback, so a
 * swap can never half-destroy the previous version. Bound as `cms.extension_install_fs` (tests
 * substitute a subclass to inject failures at precise phases).
 */
class ExtensionInstallFilesystem
{
    public function copyDirectory(string $from, string $to): void
    {
        if (! File::isDirectory($from)) {
            throw new RuntimeException("Copy source is not a directory: {$from}");
        }

        if (File::exists($to)) {
            throw new RuntimeException("Copy target already exists: {$to}");
        }

        if (! File::copyDirectory($from, $to) || ! File::isDirectory($to)) {
            throw new RuntimeException("Copy failed: {$from} -> {$to}");
        }
    }

    public function move(string $from, string $to): void
    {
        if (! file_exists($from)) {
            throw new RuntimeException("Move source does not exist: {$from}");
        }

        if (file_exists($to)) {
            throw new RuntimeException("Move target already exists: {$to}");
        }

        if (! @rename($from, $to) || file_exists($from) || ! file_exists($to)) {
            throw new RuntimeException("Rename failed: {$from} -> {$to}");
        }
    }

    public function deleteDirectory(string $path): void
    {
        if (! File::isDirectory($path)) {
            return;
        }

        if (! File::deleteDirectory($path) || File::exists($path)) {
            throw new RuntimeException("Delete failed: {$path}");
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }
}
