<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Resolve a repository's committed HEAD commit from Git's on-disk layout WITHOUT
 * shelling out (cross-platform, sandbox-safe).
 *
 * Unlike a naive `$repo/.git/HEAD` read, this follows Git's authoritative seams so it
 * is correct for a LINKED WORKTREE, where `.git` is a `gitdir:` pointer FILE (not a
 * directory) and the branch refs live in the shared common dir:
 *
 *   - `.git` directory → git dir is `$repo/.git`;
 *   - `.git` file      → parse `gitdir: <path>` (relative to `$repo`) for the worktree git dir;
 *   - refs resolve against the `commondir` (the shared `.git`) with a packed-refs fallback.
 *
 * Returns the 40-char lowercase SHA of HEAD, or {@see self::UNCOMMITTED} for a
 * non-Git / unresolvable directory. HEAD is a committed pointer, so a dirty working
 * tree does not change the result.
 */
final class GitHeadResolver
{
    public const UNCOMMITTED = 'uncommitted';

    public static function resolve(string $repo): string
    {
        $gitDir = self::gitDir($repo);
        if ($gitDir === null) {
            return self::UNCOMMITTED;
        }

        $head = @trim((string) @file_get_contents($gitDir.'/HEAD'));
        if ($head === '') {
            return self::UNCOMMITTED;
        }

        if (! str_starts_with($head, 'ref: ')) {
            return self::isSha($head) ? $head : self::UNCOMMITTED; // detached HEAD
        }

        return self::resolveRef($gitDir, self::commonDir($gitDir), substr($head, 5));
    }

    /** The git directory: `$repo/.git` when a dir, or the pointer target when a worktree `.git` file. */
    private static function gitDir(string $repo): ?string
    {
        $dot = $repo.'/.git';

        if (is_dir($dot)) {
            return $dot;
        }

        if (is_file($dot)) {
            $line = @trim((string) @file_get_contents($dot));
            if (str_starts_with($line, 'gitdir: ')) {
                return self::absolutize($repo, trim(substr($line, 8)));
            }
        }

        return null;
    }

    /** The shared common dir (branch refs live there for linked worktrees); defaults to the git dir. */
    private static function commonDir(string $gitDir): string
    {
        $file = $gitDir.'/commondir';

        if (is_file($file)) {
            $rel = @trim((string) @file_get_contents($file));
            if ($rel !== '') {
                return self::absolutize($gitDir, $rel);
            }
        }

        return $gitDir;
    }

    private static function resolveRef(string $gitDir, string $commonDir, string $ref): string
    {
        foreach ([$gitDir.'/'.$ref, $commonDir.'/'.$ref] as $loose) {
            $sha = @trim((string) @file_get_contents($loose));
            if (self::isSha($sha)) {
                return $sha;
            }
        }

        foreach (@file($commonDir.'/packed-refs') ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && $line[0] !== '^' && str_ends_with($line, ' '.$ref)) {
                $sha = substr($line, 0, 40);
                if (self::isSha($sha)) {
                    return $sha;
                }
            }
        }

        return self::UNCOMMITTED;
    }

    /** Resolve a path that may be absolute (Windows drive or POSIX) or relative to a base. */
    private static function absolutize(string $base, string $path): string
    {
        if (preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path) === 1) {
            return str_replace('\\', '/', $path);
        }

        return str_replace('\\', '/', $base.'/'.$path);
    }

    private static function isSha(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1;
    }
}
