<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use TheNguyen\CMS\Support\AssetPublishResult;

/**
 * Generic plugin public-asset provisioning (v1.0.0).
 *
 * A plugin ships ALREADY-BUILT browser assets (JS/CSS) inside its own directory and opts in via the
 * canonical `plugin.json` `assets` block:
 *
 *   "assets": { "source": "public" }
 *
 * On install/upgrade the installer asks this service to copy that plugin-relative source directory into
 * the Core-owned public namespace `<cms.paths.plugin_assets>/<slug>` (default `public/vendor/<slug>`),
 * so the plugin's runtime `asset('vendor/<slug>/…')` URLs resolve. It is deliberately GENERIC: it holds
 * no plugin-specific names, paths, or classes, and it NEVER builds anything — TN CMS installation must
 * not invoke Node/npm/Vite (PLUGIN_INSTALL != FRONTEND_BUILD).
 *
 * Safety (fails closed):
 *   - the declared source is a RELATIVE path that must resolve strictly INSIDE the plugin root
 *     (traversal, absolute, drive-qualified, UNC, and symlink/reparse escapes are rejected);
 *   - the destination is derived by Core from the trusted slug — a plugin can NEVER name an arbitrary
 *     host destination, and one plugin's namespace can never reach another's;
 *   - only the plugin's OWN slug namespace under the public base is ever created or removed; no arbitrary
 *     foreign public file is touched.
 */
final class PluginAssetPublisher
{
    /** Canonical opt-in manifest key. A plugin without it behaves exactly as before (skipped). */
    public const MANIFEST_KEY = 'assets';

    public function __construct(private readonly ?string $assetsBase = null) {}

    /**
     * Publish a plugin's declared public assets from its installed root into the Core-owned namespace.
     * Returns a skipped result when no `assets` block is declared; a failed result (never a throw) on
     * any validation/containment/copy problem.
     *
     * @param  array<string, mixed>  $manifest  the plugin.json contents
     */
    public function publish(string $pluginRoot, string $slug, array $manifest): AssetPublishResult
    {
        $declaration = $manifest[self::MANIFEST_KEY] ?? null;
        if ($declaration === null) {
            return AssetPublishResult::skipped();
        }

        try {
            $source = $this->readDeclaredSource($declaration);
            $slugError = $this->validateSlug($slug);
            if ($slugError !== null) {
                return AssetPublishResult::failed($slugError);
            }

            $realPluginRoot = realpath($pluginRoot);
            if ($realPluginRoot === false || ! is_dir($realPluginRoot)) {
                return AssetPublishResult::failed("Plugin root not found: {$pluginRoot}");
            }

            // Resolve + contain the source strictly inside the plugin root.
            $realSource = realpath($realPluginRoot.DIRECTORY_SEPARATOR.$this->toOsPath($source));
            if ($realSource === false || ! is_dir($realSource)) {
                return AssetPublishResult::failed("Declared asset source does not exist: {$source}");
            }
            if ($realSource === $realPluginRoot
                || ! str_starts_with($realSource.DIRECTORY_SEPARATOR, $realPluginRoot.DIRECTORY_SEPARATOR)) {
                return AssetPublishResult::failed("Asset source escapes the plugin root: {$source}");
            }
            if ($this->containsLink($realSource)) {
                return AssetPublishResult::failed('Asset source contains symbolic links, which are not allowed.');
            }

            // Core-owned, slug-derived destination (the plugin can never influence this).
            $baseReal = $this->ensureBase();
            if ($baseReal === null) {
                return AssetPublishResult::failed('Public asset base directory is unavailable.');
            }
            $destination = $baseReal.DIRECTORY_SEPARATOR.$slug;

            // Stale-owned reconcile: remove ONLY this plugin's prior namespace, then copy fresh.
            if (File::isDirectory($destination)) {
                $this->assertOwnedDestination($destination, $baseReal);
                File::deleteDirectory($destination);
            }
            if (! File::copyDirectory($realSource, $destination) || ! File::isDirectory($destination)) {
                return AssetPublishResult::failed('Failed to copy plugin public assets into place.');
            }

            return AssetPublishResult::published($this->countFiles($destination));
        } catch (\Throwable $e) {
            return AssetPublishResult::failed('Asset provisioning failed: '.$e->getMessage());
        }
    }

    /**
     * Remove a plugin's OWNED published assets (uninstall / install rollback). A safe no-op when the
     * namespace is absent; fails closed if the derived path would ever escape the public base.
     */
    public function purge(string $slug): AssetPublishResult
    {
        if ($this->validateSlug($slug) !== null) {
            return AssetPublishResult::failed('Invalid slug for asset purge.');
        }

        $baseReal = realpath($this->base());
        if ($baseReal === false) {
            return AssetPublishResult::purged(0);
        }

        $realDest = realpath($baseReal.DIRECTORY_SEPARATOR.$slug);
        if ($realDest === false || ! is_dir($realDest)) {
            return AssetPublishResult::purged(0);
        }

        try {
            $this->assertOwnedDestination($realDest, $baseReal);
            File::deleteDirectory($realDest);
        } catch (\Throwable $e) {
            return AssetPublishResult::failed('Asset purge failed: '.$e->getMessage());
        }

        return AssetPublishResult::purged();
    }

    /** Core-owned public destination for a slug: `<base>/<slug>`. Never plugin-specified. */
    public function destinationFor(string $slug): string
    {
        return rtrim($this->base(), '/\\').DIRECTORY_SEPARATOR.$slug;
    }

    // -----------------------------------------------------------------------------------------------

    /**
     * Validate and extract the relative `source` from the declaration, rejecting unsafe paths.
     *
     * @param  mixed  $declaration
     */
    private function readDeclaredSource(mixed $declaration): string
    {
        if (! is_array($declaration) || ! isset($declaration['source'])
            || ! is_string($declaration['source']) || trim($declaration['source']) === '') {
            throw new RuntimeException("Invalid 'assets' declaration: a non-empty relative 'source' is required.");
        }

        $source = trim($declaration['source']);
        if ($this->isUnsafeRelative($source)) {
            throw new RuntimeException("Unsafe asset source path: {$source}");
        }

        return $source;
    }

    private function base(): string
    {
        return $this->assetsBase ?? (string) config('cms.paths.plugin_assets', public_path('vendor'));
    }

    private function ensureBase(): ?string
    {
        $base = $this->base();
        if (! File::isDirectory($base)) {
            File::makeDirectory($base, 0755, true, true);
        }
        $real = realpath($base);

        return $real === false ? null : $real;
    }

    /** Fail-closed: $dest must be strictly inside $baseReal (never the base itself). */
    private function assertOwnedDestination(string $dest, string $baseReal): void
    {
        $realDest = realpath($dest);
        $realDest = $realDest === false ? $dest : $realDest;
        if ($realDest === $baseReal || ! str_starts_with($realDest.DIRECTORY_SEPARATOR, $baseReal.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Refusing to modify a public path outside the plugin asset namespace: {$dest}");
        }
    }

    private function isUnsafeRelative(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_starts_with($normalized, '/')) {
            return true; // empty, POSIX-absolute, or UNC (`//host`)
        }
        if (preg_match('#^[a-zA-Z]:#', $normalized) === 1) {
            return true; // Windows drive
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function toOsPath(string $relative): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    private function validateSlug(string $slug): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            return "Invalid plugin slug for asset provisioning: {$slug}";
        }

        return null;
    }

    /** True when the subtree contains any symlink or (Windows) junction/reparse point. */
    private function containsLink(string $dir): bool
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $item) {
            $path = $item->getPathname();
            if (is_link($path) || (! is_dir($path) && ! is_file($path))) {
                return true;
            }
        }

        return false;
    }

    private function countFiles(string $dir): int
    {
        if (! is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $count++;
            }
        }

        return $count;
    }
}
