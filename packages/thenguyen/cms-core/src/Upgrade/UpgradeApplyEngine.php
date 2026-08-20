<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;

/**
 * Core apply engine — replace-not-merge + stale Core removal (CORE-UPGRADE-1,
 * §24–§36).
 *
 * The ONE apply authority. It consumes the ownership model + the verified
 * staging tree + the site's OLD ownership manifest + the package's NEW ownership
 * manifest, and mutates the live tree as:
 *
 *     verified target → cleanly REPLACE each Core-owned replace root
 *     (never recursive-merge) → REMOVE obsolete Core files (old − new).
 *
 * Hard invariant (§25): before any write or delete, the target path is asserted
 * Core-owned AND outside every preserve prefix/sibling. A preserved path
 * (.env, storage/, uploads/, non-Core plugins, custom themes) is never touched.
 *
 * Filesystem safety (§36): whole-root replacement uses an ordered
 * move-aside → promote → drop-old sequence so a failed promotion can restore the
 * moved-aside live copy. True atomic directory swap is not portable (documented
 * limit); maintenance mode covers the promotion window, and full file rollback
 * from the verified backup is the recovery of record.
 *
 * No plugin-specific hardcoding, no second path list — replace roots come from
 * the manifest ownership model only.
 */
final class UpgradeApplyEngine
{
    private string $root;

    private const OLD_SUFFIX = '.tncms-old';

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
     * Promote the verified staged target over the live tree.
     *
     * @param  array<string,mixed>  $model         ownership model
     * @param  array<string,string> $oldOwnedFiles site's recorded ownership (rel=>sha)
     * @param  array<string,string> $newOwnedFiles package ownership (rel=>sha)
     * @return array{replaced_dirs:list<string>, replaced_files:list<string>,
     *               removed_stale:list<string>, pruned_dirs:list<string>}
     */
    public function apply(string $stagingDir, array $model, array $oldOwnedFiles, array $newOwnedFiles): array
    {
        $stagingDir = rtrim(str_replace('\\', '/', $stagingDir), '/');
        if (! is_dir($stagingDir)) {
            throw new RuntimeException("staging dir not found: $stagingDir");
        }

        $replacedDirs = [];
        $replacedFiles = [];

        // 1) Replace whole Core-owned directories (clean, never merged).
        foreach ($model['replace_dirs'] ?? [] as $dirRel) {
            $rel = rtrim(CoreOwnership::normalize($dirRel), '/');
            $this->assertMutable($rel, $model);
            $staged = $stagingDir.'/'.$rel;
            if (! is_dir($staged)) {
                continue; // target no longer ships this root — handled as stale below
            }
            $this->replaceDir($rel, $staged);
            $replacedDirs[] = $rel;
        }

        // 2) Replace individual Core-owned files.
        foreach ($model['replace_files'] ?? [] as $fileRel) {
            $rel = CoreOwnership::normalize($fileRel);
            $this->assertMutable($rel, $model);
            $staged = $stagingDir.'/'.$rel;
            if (! is_file($staged)) {
                continue;
            }
            $this->replaceFile($rel, $staged);
            $replacedFiles[] = $rel;
        }

        // 3) Remove obsolete Core files (present in OLD ownership, absent from NEW).
        //    Deletion is proven Core-owned + non-preserved by deriveObsolete AND
        //    re-asserted here before unlink.
        $removed = [];
        foreach (CoreOwnership::deriveObsolete($oldOwnedFiles, $newOwnedFiles, $model) as $rel) {
            $abs = $this->root.'/'.$rel;
            if (! is_file($abs)) {
                $removed[] = $rel; // already gone (e.g. inside a wholesale-replaced dir)
                continue;
            }
            $this->assertMutable($rel, $model);
            if (@unlink($abs)) {
                $removed[] = $rel;
            }
        }

        // 4) Prune empty directories left inside replace roots (confined to Core).
        $pruned = [];
        foreach ($model['replace_dirs'] ?? [] as $dirRel) {
            $rel = rtrim(CoreOwnership::normalize($dirRel), '/');
            $this->pruneEmptyDirs($this->root.'/'.$rel, $pruned);
        }

        return [
            'replaced_dirs' => $replacedDirs,
            'replaced_files' => $replacedFiles,
            'removed_stale' => $removed,
            'pruned_dirs' => $pruned,
        ];
    }

    // ---- primitives -----------------------------------------------------

    /** Assert a path may be written/deleted: Core-owned AND not preserved (§25). */
    private function assertMutable(string $rel, array $model): void
    {
        if (CoreOwnership::isPreserved($rel, $model)) {
            throw new RuntimeException("refusing to touch preserved path: $rel");
        }
        if (! CoreOwnership::isCoreOwned($rel, $model)) {
            throw new RuntimeException("refusing to touch non-Core path: $rel");
        }
    }

    /** Move live root aside, promote staged root, drop the moved-aside copy. */
    private function replaceDir(string $rel, string $staged): void
    {
        $target = $this->root.'/'.$rel;
        $old = $target.self::OLD_SUFFIX;

        $this->rrmdir($old);

        if (is_dir($target)) {
            if (! @rename($target, $old)) {
                // Fall back to in-place clear if rename-aside is unavailable.
                $this->rrmdir($target);
            }
        }

        $parent = \dirname($target);
        if (! is_dir($parent)) {
            @mkdir($parent, 0775, true);
        }

        if (! @rename($staged, $target)) {
            // Promotion failed — restore the moved-aside live copy if we have it.
            if (is_dir($old) && ! is_dir($target)) {
                @rename($old, $target);
            }
            throw new RuntimeException("failed to promote staged dir: $rel");
        }

        $this->rrmdir($old);
    }

    /** Overwrite a single Core-owned file via temp-write + rename. */
    private function replaceFile(string $rel, string $staged): void
    {
        $target = $this->root.'/'.$rel;
        $dir = \dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $target.'.tncms-new';
        if (! @copy($staged, $tmp)) {
            throw new RuntimeException("failed to stage replacement file: $rel");
        }
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            // rename onto an existing file can fail on some platforms; try copy.
            if (! @copy($staged, $target)) {
                throw new RuntimeException("failed to replace file: $rel");
            }
        }
    }

    /** Recursively remove empty directories under $dir (never removes $dir's parent). */
    private function pruneEmptyDirs(string $dir, array &$pruned): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            if (is_dir($p) && ! is_link($p)) {
                $this->pruneEmptyDirs($p, $pruned);
            }
        }
        $remaining = array_diff(scandir($dir) ?: [], ['.', '..']);
        if ($remaining === []) {
            $rel = CoreOwnership::normalize(substr($dir, strlen($this->root) + 1));
            if (@rmdir($dir)) {
                $pruned[] = $rel;
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            @unlink($dir); // in case a file/symlink sits at the path
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            (is_dir($p) && ! is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
