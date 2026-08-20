<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Canonical Core-release ownership logic (CORE-UPGRADE-1).
 *
 * The ownership MODEL (which roots are Core-owned) is data, authored once in
 * tools/distribution/manifest.php under 'core_ownership' and embedded verbatim
 * into every package's tncms-core.manifest.json. This class is the single
 * authority for the LOGIC that interprets that model:
 *
 *   - classify a path as Core-owned / site-preserved;
 *   - enumerate the Core-owned file set of a release tree (path => sha256);
 *   - derive obsolete Core paths for stale-file removal (old − new).
 *
 * Deliberately dependency-free (no Laravel, no Composer autoload assumptions)
 * so the distribution publisher can `require` it directly at build time and the
 * runtime apply engine can autoload it — ONE implementation, never duplicated
 * (§53). Every method is pure and side-effect-free.
 *
 * Path convention: forward-slash, relative to the installation root, no leading
 * slash (e.g. "packages/thenguyen/cms-core/src/Support/CmsInfo.php").
 */
final class CoreOwnership
{
    /** Normalise to forward-slash, strip leading "./" and leading slash. */
    public static function normalize(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = preg_replace('#^\./#', '', $rel) ?? $rel;

        return ltrim($rel, '/');
    }

    /**
     * True when a path is site/user-owned and must NEVER be written or deleted
     * by the apply engine — checked FIRST, so it always wins over ownership.
     * Covers the hard preserve prefixes (.env, storage/, public/uploads/) and
     * the non-Core children of shared "sibling" roots (e.g. plugins/ecommerce,
     * themes/company, public/vendor/page-builder).
     */
    public static function isPreserved(string $rel, array $model): bool
    {
        $rel = self::normalize($rel);

        foreach ($model['preserve_prefixes'] ?? [] as $prefix) {
            $prefix = self::normalize($prefix);
            if ($rel === rtrim($prefix, '/') || str_starts_with($rel, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        // A child of a sibling root that is NOT one of the Core-owned children.
        foreach ($model['preserve_siblings'] ?? [] as $root => $coreChildren) {
            $root = self::normalize($root);
            if (! str_starts_with($rel, $root . '/')) {
                continue;
            }
            $child = explode('/', substr($rel, strlen($root) + 1))[0] ?? '';
            if ($child !== '' && ! in_array($child, $coreChildren, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a path is Core release state (a replace_file, or under a
     * replace_dir). Preserved paths are never Core-owned — preserve wins.
     */
    public static function isCoreOwned(string $rel, array $model): bool
    {
        $rel = self::normalize($rel);

        if (self::isPreserved($rel, $model)) {
            return false;
        }

        if (in_array($rel, array_map([self::class, 'normalize'], $model['replace_files'] ?? []), true)) {
            return true;
        }

        foreach ($model['replace_dirs'] ?? [] as $dir) {
            $dir = rtrim(self::normalize($dir), '/');
            if ($rel === $dir || str_starts_with($rel, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enumerate the Core-owned files physically present in a release tree.
     *
     * @return array<string,string> relative path => sha256 (sorted by key)
     */
    public static function ownedFiles(string $root, array $model): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $out = [];

        if (! is_dir($root)) {
            return $out;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = self::normalize(substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1));
            if (self::isCoreOwned($rel, $model)) {
                $out[$rel] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * Derive the obsolete Core paths to remove on upgrade: files that were
     * Core-owned in the OLD release but are absent from the NEW release.
     *
     * Deletion safety (§12/§44): absence from the target is necessary but NOT
     * sufficient — a path is returned ONLY if it is still classified Core-owned
     * and is NOT preserved. Prior Core ownership must be proven by presence in
     * $oldFiles (the site's recorded ownership manifest), never inferred.
     *
     * @param  array<string,string>  $oldFiles  old ownership manifest (path => hash)
     * @param  array<string,string>  $newFiles  new package  ownership manifest
     * @return list<string> obsolete relative paths, sorted
     */
    public static function deriveObsolete(array $oldFiles, array $newFiles, array $model): array
    {
        $obsolete = [];
        foreach ($oldFiles as $rel => $_hash) {
            $rel = self::normalize($rel);
            if (array_key_exists($rel, $newFiles)) {
                continue; // still shipped — keep/replace, not obsolete
            }
            if (! self::isCoreOwned($rel, $model) || self::isPreserved($rel, $model)) {
                continue; // never delete anything not provably Core-owned
            }
            $obsolete[] = $rel;
        }
        sort($obsolete);

        return $obsolete;
    }
}
