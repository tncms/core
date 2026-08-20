<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Support\InstallResult;
use ZipArchive;

/**
 * Theme/Plugin ZIP Installer (v1.0.0-beta.2).
 *
 * Safe, local-upload-only installation of themes and plugins from a `.zip`.
 * Everything happens in a throwaway temp directory under
 * `storage/app/tncms-installer/{random}`; nothing is ever extracted directly
 * into `themes/` or `plugins/`. Only after the archive passes every security and
 * manifest check is the detected root folder moved into its final destination.
 * The temp directory is always cleaned up (success or failure), and no raw
 * exception ever reaches the UI — every path returns an {@see InstallResult}.
 *
 * Out of scope for this phase (deliberately not implemented): remote-URL install,
 * CLI install, marketplace, license manager, file editors, auto-update. The
 * installer never boots, activates, or executes extension code — a freshly
 * installed plugin/theme appears in its manager page as **inactive**.
 */
class ExtensionInstaller
{
    /**
     * Reserved slugs that must never become a theme/plugin folder name.
     *
     * Two groups, both blocked identically:
     *   - Laravel application roots a malicious/buggy slug must never collide
     *     with or shadow: vendor, storage, bootstrap, public, app, config,
     *     resources, routes, database.
     *   - CMS-internal names: admin, themes, plugins, core.
     *
     * Note these guard the *manifest slug* (which becomes the destination folder
     * name); they are independent of the configured themes/ and plugins/ roots,
     * which are resolved internally and never derived from user input.
     */
    private const RESERVED_SLUGS = [
        // Laravel application roots.
        'vendor', 'storage', 'bootstrap', 'public', 'app',
        'config', 'resources', 'routes', 'database',
        // CMS-internal names.
        'admin', 'themes', 'plugins', 'core',
    ];

    /** Hard limits to blunt zip-bomb style archives (simple, practical). */
    private const MAX_ENTRIES = 5000;

    private const MAX_TOTAL_BYTES = 104_857_600; // 100 MB uncompressed

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly ThemeManager $themes,
        private readonly PluginAssetPublisher $assetPublisher = new PluginAssetPublisher(),
    ) {
    }

    /**
     * True when the installer can run at all (the PHP zip extension is present).
     * Used by /cms-health; never throws.
     */
    public function isReady(): bool
    {
        return extension_loaded('zip') && class_exists(ZipArchive::class);
    }

    public function installPluginFromZip(string $zipPath, bool $overwrite = false): InstallResult
    {
        return $this->install('plugin', 'plugin.json', $zipPath, $overwrite);
    }

    public function installThemeFromZip(string $zipPath, bool $overwrite = false): InstallResult
    {
        return $this->install('theme', 'theme.json', $zipPath, $overwrite);
    }

    /**
     * Permanently delete an installed (inactive) plugin's directory.
     */
    public function deletePlugin(string $slug): InstallResult
    {
        return $this->delete('plugin', $slug);
    }

    /**
     * Permanently delete an installed (non-active) theme's directory.
     */
    public function deleteTheme(string $slug): InstallResult
    {
        return $this->delete('theme', $slug);
    }

    /**
     * Shared, safe delete pipeline for both extension types.
     *
     * The slug is validated and the path is resolved *internally* from the
     * configured root (the UI never supplies a path). A realpath containment
     * check is the final defense-in-depth guard before anything is removed: the
     * resolved directory must live strictly inside themes/ or plugins/. Active
     * extensions are refused, the last remaining theme is refused, and no raw
     * exception ever reaches the UI.
     *
     * @param  'plugin'|'theme'  $type
     */
    private function delete(string $type, string $slug): InstallResult
    {
        try {
            // 1. Strict slug validation (blocks "..", "/", "\\", uppercase, etc.).
            $slugError = $this->validateSlug($slug);
            if ($slugError !== null) {
                return InstallResult::failure($type, $slugError, [], $slug);
            }

            // 2. Resolve the path internally — never from the caller.
            $root = $this->typeRoot($type);
            $destination = $root . DIRECTORY_SEPARATOR . $slug;

            if (! File::isDirectory($destination)) {
                return InstallResult::failure($type, ucfirst($type) . " \"{$slug}\" is not installed.", [], $slug);
            }

            // 3. realpath containment guard: resolved dir must be strictly inside
            //    the type root (never the root itself, never outside it).
            $realRoot = realpath($root);
            $realDest = realpath($destination);

            if ($realRoot === false || $realDest === false
                || $realDest === $realRoot
                || ! str_starts_with($realDest, $realRoot . DIRECTORY_SEPARATOR)) {
                return InstallResult::failure(
                    $type,
                    "Refused to delete: the resolved path is outside the {$type}s directory.",
                    [],
                    $slug,
                );
            }

            // 4. Active-extension protection.
            if ($type === 'plugin') {
                if ($this->isPluginActiveSafe($slug)) {
                    return InstallResult::failure($type, 'Deactivate the plugin before deleting it.', [], $slug);
                }
            } else {
                if ($this->activeThemeSlugSafe() === $slug) {
                    return InstallResult::failure($type, 'Activate another theme before deleting this one.', [], $slug);
                }

                // Never leave the CMS with zero valid themes.
                if (count($this->themes->all()) <= 1) {
                    return InstallResult::failure($type, 'Cannot delete the only installed theme.', [], $slug);
                }
            }

            // 5. Remove the directory recursively.
            if (! File::deleteDirectory($realDest)) {
                return InstallResult::failure($type, "The {$type} directory could not be deleted.", [], $slug);
            }

            // 5a. Remove the plugin's OWNED published public assets (public/vendor/<slug>). Safe no-op
            //     when it published none; only the plugin's own slug namespace is ever touched.
            if ($type === 'plugin') {
                $this->assetPublisher->purge($slug);
            }

            $this->refreshDiscovery();

            return InstallResult::ok($type, $slug, $realDest, ucfirst($type) . " \"{$slug}\" deleted.");
        } catch (\Throwable $e) {
            return InstallResult::failure($type, 'Deletion failed: ' . $e->getMessage(), [], $slug);
        }
    }

    /**
     * Shared install pipeline for both extension types.
     *
     * @param  'plugin'|'theme'  $type
     */
    private function install(string $type, string $manifestFile, string $zipPath, bool $overwrite): InstallResult
    {
        if (! $this->isReady()) {
            return InstallResult::failure($type, 'The PHP "zip" extension is not available on this server.');
        }

        $zipError = $this->validateZipFile($zipPath);
        if ($zipError !== null) {
            return InstallResult::failure($type, $zipError);
        }

        $tempDir = $this->makeTempDir();

        try {
            // 1. Open + validate every entry name BEFORE writing anything to disk.
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return InstallResult::failure($type, 'The uploaded file could not be opened as a ZIP archive.');
            }

            $entryError = $this->validateZipEntries($zip);
            if ($entryError !== null) {
                $zip->close();

                return InstallResult::failure($type, $entryError);
            }

            // 2. Safe to extract — all entry names are relative and in-bounds.
            $extracted = $zip->extractTo($tempDir);
            $zip->close();

            if (! $extracted) {
                return InstallResult::failure($type, 'The ZIP archive could not be extracted.');
            }

            // 3. Post-extraction symlink guard (caught here even if the OS created one).
            if ($this->containsSymlink($tempDir)) {
                return InstallResult::failure($type, 'The archive contains symbolic links, which are not allowed.');
            }

            // 4. Locate the single manifest (supports root-folder and flat layouts).
            $manifests = $this->findManifests($tempDir, $manifestFile);

            if ($manifests === []) {
                return InstallResult::failure($type, "The archive does not contain a {$manifestFile} file.");
            }

            if (count($manifests) > 1) {
                return InstallResult::failure($type, "The archive contains multiple {$manifestFile} files and is ambiguous.");
            }

            $sourceRoot = dirname($manifests[0]);

            // 5. Validate the manifest contents.
            $data = $this->readManifest($manifests[0]);
            if ($data === null) {
                return InstallResult::failure($type, "The {$manifestFile} file is not valid JSON.");
            }

            $manifestErrors = $this->manifestErrors($data);
            if ($manifestErrors !== []) {
                return InstallResult::failure(
                    $type,
                    'The manifest is missing required field(s): ' . implode(', ', $manifestErrors) . '.',
                    $manifestErrors,
                );
            }

            $slug = (string) $data['slug'];
            $slugError = $this->validateSlug($slug);
            if ($slugError !== null) {
                return InstallResult::failure($type, $slugError, [], $slug);
            }

            // 6. Type-specific structural checks + collect non-fatal warnings.
            $warnings = [];
            $structureError = $type === 'theme'
                ? $this->themeStructureError($sourceRoot)
                : $this->pluginStructureError($sourceRoot, $data, $warnings);

            if ($structureError !== null) {
                return InstallResult::failure($type, $structureError, [], $slug);
            }

            // 7. Resolve destination + apply the overwrite policy.
            $destination = $this->destinationPath($type, $slug);

            if (File::isDirectory($destination)) {
                if (! $overwrite) {
                    return InstallResult::failure(
                        $type,
                        ucfirst($type) . " \"{$slug}\" is already installed. Enable “Overwrite existing files” to replace it.",
                        [],
                        $slug,
                    );
                }

                if ($this->isActive($type, $slug)) {
                    return InstallResult::failure(
                        $type,
                        "The {$type} \"{$slug}\" is currently active. Deactivate it before overwriting.",
                        [],
                        $slug,
                    );
                }

                File::deleteDirectory($destination);
            }

            // 8. Copy the validated source into place (scoped to the slug folder).
            //    Copy (not rename) is used because a cross-directory rename from
            //    the storage temp dir is unreliable on some platforms; the temp
            //    directory is removed in the finally block regardless.
            if (! File::copyDirectory($sourceRoot, $destination)) {
                return InstallResult::failure($type, 'The validated files could not be moved into place.', [], $slug);
            }

            // 8a. Provision opt-in plugin public assets from the just-installed root into the Core-owned
            //     public namespace. A plugin without an "assets" declaration is unaffected. On failure,
            //     roll back this install (the plugin dir Core just created + any partial public assets)
            //     so a partial state never reports success.
            if ($type === 'plugin') {
                $assetResult = $this->assetPublisher->publish($destination, $slug, $data);
                if ($assetResult->isFailure()) {
                    File::deleteDirectory($destination);
                    $this->assetPublisher->purge($slug);

                    return InstallResult::failure($type, $assetResult->message, [], $slug);
                }
                $warnings = array_merge($warnings, $assetResult->warnings);
            }

            $this->refreshDiscovery();

            return InstallResult::ok(
                $type,
                $slug,
                $destination,
                ucfirst($type) . " \"{$slug}\" installed successfully. It is inactive — activate it when ready.",
                $warnings,
            );
        } catch (\Throwable $e) {
            return InstallResult::failure($type, 'Installation failed: ' . $e->getMessage());
        } finally {
            // Always clean up the temp directory, success or failure.
            File::deleteDirectory($tempDir);
        }
    }

    // ---------------------------------------------------------------------
    // ZIP file + entry validation
    // ---------------------------------------------------------------------

    private function validateZipFile(string $zipPath): ?string
    {
        if (! is_file($zipPath) || ! is_readable($zipPath)) {
            return 'The uploaded file could not be read.';
        }

        if (strtolower((string) pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            return 'Only .zip archives are accepted.';
        }

        return null;
    }

    /**
     * Validate every entry name for path traversal, absolute paths, drive
     * letters, empty/dangerous names, and the entry-count/size limits — BEFORE
     * any extraction happens. Returns the first error, or null when safe.
     */
    private function validateZipEntries(ZipArchive $zip): ?string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'The archive contains too many files.';
        }

        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                return 'The archive contains an unreadable entry.';
            }

            $name = (string) $stat['name'];

            if (trim($name) === '') {
                return 'The archive contains an empty entry name.';
            }

            // Normalise Windows separators for a single consistent check.
            $normalized = str_replace('\\', '/', $name);

            // Absolute (unix) or drive-letter (windows) paths are rejected.
            if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:#', $normalized) === 1) {
                return 'The archive contains an absolute path, which is not allowed.';
            }

            // Path traversal in any segment.
            foreach (explode('/', $normalized) as $segment) {
                if ($segment === '..') {
                    return 'The archive contains a path-traversal entry (".."), which is not allowed.';
                }
            }

            $totalBytes += (int) ($stat['size'] ?? 0);

            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                return 'The archive is too large to install.';
            }
        }

        return null;
    }

    /**
     * Walk the extracted tree and report whether any path is a symlink.
     */
    private function containsSymlink(string $dir): bool
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            if (is_link($item->getPathname())) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Manifest discovery + validation
    // ---------------------------------------------------------------------

    /**
     * Find every occurrence of the manifest filename in the extracted tree.
     * One match → its directory is the source root (handles both a wrapping
     * folder and a flat archive). Zero → missing; more than one → ambiguous.
     *
     * @return array<int, string> absolute manifest file paths
     */
    private function findManifests(string $dir, string $manifestFile): array
    {
        $found = [];

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isFile() && $item->getFilename() === $manifestFile) {
                $found[] = $item->getPathname();
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $file): ?array
    {
        $data = json_decode((string) File::get($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Required manifest keys missing or empty (name, slug, version, author).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function manifestErrors(array $data): array
    {
        $missing = [];

        foreach (['name', 'slug', 'version', 'author'] as $key) {
            $value = $data[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Strict slug validation, shared by install (manifest slug → folder name)
     * and delete (caller-supplied slug). The pattern requires a leading
     * lowercase letter or digit, so it inherently rejects an empty slug, a
     * hidden/dot folder (".foo"), whitespace, path separators, "..", drive
     * letters, and uppercase. Reserved names are then refused outright.
     */
    private function validateSlug(string $slug): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            return "The manifest slug \"{$slug}\" is invalid. Use lowercase letters, numbers, hyphens, or underscores.";
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return "The manifest slug \"{$slug}\" is reserved and cannot be used.";
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Type-specific structure checks
    // ---------------------------------------------------------------------

    /**
     * Themes must ship layouts/master, unless a valid "default" theme exists on
     * disk to inherit missing views from (mirrors ThemeManager view fallback).
     */
    private function themeStructureError(string $sourceRoot): ?string
    {
        $hasMaster = File::exists($sourceRoot . '/views/layouts/master.blade.php');

        if ($hasMaster) {
            return null;
        }

        if ($this->themes->find('default') !== null) {
            return null;
        }

        return 'The theme is missing views/layouts/master.blade.php and no default theme exists to inherit it from.';
    }

    /**
     * Plugins have no extra required files. Declared provider classes are NOT
     * executed or required to exist during install; a missing provider source
     * file is recorded as a non-fatal warning.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>    $warnings  (by-ref) collected non-fatal notes
     */
    private function pluginStructureError(string $sourceRoot, array $data, array &$warnings): ?string
    {
        $providers = is_array($data['providers'] ?? null) ? $data['providers'] : [];

        if ($providers !== [] && ! File::isDirectory($sourceRoot . '/src')) {
            $warnings[] = 'The plugin declares service providers but ships no src/ directory.';
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Destination + active-state helpers
    // ---------------------------------------------------------------------

    /**
     * Absolute root directory for an extension type.
     *
     * @param  'plugin'|'theme'  $type
     */
    private function typeRoot(string $type): string
    {
        return $type === 'theme'
            ? (string) config('cms.paths.themes', base_path('themes'))
            : (string) config('cms.paths.plugins', base_path('plugins'));
    }

    /**
     * @param  'plugin'|'theme'  $type
     */
    private function destinationPath(string $type, string $slug): string
    {
        return $this->typeRoot($type) . DIRECTORY_SEPARATOR . $slug;
    }

    /**
     * @param  'plugin'|'theme'  $type
     */
    private function isActive(string $type, string $slug): bool
    {
        try {
            return $type === 'theme'
                ? $this->activeThemeSlugSafe() === $slug
                : $this->isPluginActiveSafe($slug);
        } catch (\Throwable) {
            // If active-state can't be resolved, treat as active (safer: blocks
            // an overwrite/delete rather than risk clobbering a live extension).
            return true;
        }
    }

    private function isPluginActiveSafe(string $slug): bool
    {
        try {
            return $this->extensions->isPluginActive($slug);
        } catch (\Throwable) {
            return true; // err on the safe side — refuse the destructive action
        }
    }

    private function activeThemeSlugSafe(): ?string
    {
        try {
            // ThemeManager::active() is robust (returns null only when no valid
            // theme exists) and does not throw; the catch is belt-and-braces.
            return $this->themes->active()?->slug;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Best-effort cache clear so freshly installed extensions are discoverable.
     * Discovery itself reads disk per request, so this never blocks the install.
     */
    private function refreshDiscovery(): void
    {
        try {
            settings()->clearCache();
            app('cms.theme')->flushRegistry();
            app('cms.extension')->flushRegistry();
        } catch (\Throwable) {
            // best-effort only
        }
    }

    private function makeTempDir(): string
    {
        $base = storage_path('app' . DIRECTORY_SEPARATOR . 'tncms-installer');

        if (! File::isDirectory($base)) {
            File::makeDirectory($base, 0755, true, true);
        }

        $dir = $base . DIRECTORY_SEPARATOR . Str::random(16);
        File::makeDirectory($dir, 0755, true, true);

        return $dir;
    }
}
