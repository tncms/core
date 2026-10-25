<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use ReflectionClass;
use TheNguyen\CMS\Support\Plugin;
use Throwable;

/**
 * Server-derived extension identity (CORE-EXTENSION-TRUST S1, ADR D-10).
 *
 * Answers "which ACTIVE plugin owns this code?" from the filesystem, never from a
 * value the caller declares. A class is owned by plugin `slug` only when:
 *
 *   1. the class is user-defined and its file resolves through realpath();
 *   2. `slug` is an active, valid plugin whose directory basename equals its
 *      manifest slug (a folder cannot claim another plugin's slug);
 *   3. that plugin directory's realpath lives directly under the realpath of the
 *      configured plugins root (a symlink/junction that points the plugin folder
 *      elsewhere is not trusted); and
 *   4. the class file's realpath is inside that plugin directory's realpath,
 *      compared on whole path segments (no "plugins/foo" ⊂ "plugins/foobar").
 *
 * Anything else resolves to null (fail-closed). Self-declared labels — hook
 * `meta`, asset `source_name`, PHP namespaces — are ignored entirely.
 *
 * Limits: this defends against misattribution, forged labels, inactive or
 * removed plugins and cross-plugin collisions. It does NOT sandbox an active
 * plugin's PHP, which can already do anything; installing and activating a
 * plugin is the trust decision.
 *
 * Stateless apart from the per-call snapshot of active plugins, so it is safe
 * under persistent workers.
 */
class ExtensionIdentityResolver
{
    public function __construct(private readonly ExtensionManager $extensions) {}

    /** Owning active plugin slug of an object or class name, or null. */
    public function ownerOf(object|string $classOrObject): ?string
    {
        try {
            $class = is_object($classOrObject) ? $classOrObject::class : $classOrObject;

            if (! class_exists($class, false)) {
                return null;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isInternal()) {
                return null;
            }

            $file = $reflection->getFileName();

            return is_string($file) ? $this->ownerOfFile($file) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Owning active plugin slug of a PHP file on disk, or null. */
    public function ownerOfFile(string $file): ?string
    {
        try {
            $real = $this->canonical($file);

            if ($real === null) {
                return null;
            }

            foreach ($this->trustedPluginDirectories() as $slug => $directory) {
                if (str_starts_with($real, $directory.'/')) {
                    return $slug;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Whether a file lives anywhere under the plugins root (active or not). Used
     * to tell plugin call sites apart from Core, theme and compiled-view frames.
     */
    public function isUnderPluginsRoot(string $file): bool
    {
        $root = $this->pluginsRoot();
        $real = $this->canonical($file);

        return $root !== null && $real !== null && str_starts_with($real, $root.'/');
    }

    /**
     * Active plugin slug => canonical directory, for plugins that pass rules 2–3.
     *
     * @return array<string, string>
     */
    private function trustedPluginDirectories(): array
    {
        $root = $this->pluginsRoot();

        if ($root === null) {
            return [];
        }

        $out = [];

        /** @var Plugin $plugin */
        foreach ($this->extensions->activePlugins() as $plugin) {
            $directory = $this->canonical($plugin->path);

            $slug = DIRECTORY_SEPARATOR === '\\' ? strtolower($plugin->slug) : $plugin->slug;

            if ($directory === null || basename($directory) !== $slug) {
                continue;
            }

            if (dirname($directory) !== $root) {
                continue;
            }

            $out[$plugin->slug] = $directory;
        }

        return $out;
    }

    private function pluginsRoot(): ?string
    {
        return $this->canonical((string) config('cms.paths.plugins', base_path('plugins')));
    }

    /** realpath() with forward slashes and no trailing slash; null when missing. */
    private function canonical(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $real = realpath($path);

        if ($real === false) {
            return null;
        }

        $normalized = rtrim(str_replace('\\', '/', $real), '/');

        // Windows paths compare case-insensitively.
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
