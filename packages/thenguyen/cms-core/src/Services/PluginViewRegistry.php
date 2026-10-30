<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use TheNguyen\CMS\Support\Plugin;
use TheNguyen\CMS\Support\PluginViewException;

/**
 * Theme-first plugin views (CORE-PLATFORM-SEAMS-R1), bound as `cms.plugin_views`.
 *
 * An active plugin's `{slug}::` Blade namespace resolves, in order:
 *
 *   1. themes/{active}/views/plugins/{slug}/…   (then each declared parent theme)
 *   2. plugins/{slug}/resources/views/…         (the plugin's own views)
 *   3. Laravel's "View [x] not found"          (controlled failure, themed 500)
 *
 * so `view('lottery::public.region')` is overridable per view by the active
 * theme with no plugin code. The hints are recomputed whenever the active theme
 * namespace is (re)registered, and removed when the plugin is deactivated, so
 * an inactive plugin's namespace — and any theme override under it — never
 * resolves. Override directories must resolve inside the theme's views
 * directory (no symlink or `..` escape).
 */
class PluginViewRegistry
{
    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Dot-notation view name: no slashes, traversal, wrappers, namespaces or control bytes. */
    private const VIEW = '/^[a-z0-9][a-z0-9_-]{0,63}(?:\.[a-z0-9][a-z0-9_-]{0,63}){0,15}$/';

    /** Framework / Core namespaces a plugin slug never takes over (theme overrides off). */
    private const RESERVED = ['theme', 'cms', 'mail', 'errors', 'pagination', 'notifications', 'livewire', 'filament', 'laravel-exceptions', 'laravel-exceptions-renderer'];

    /** @var array<string, list<string>> slug => the plugin's own view directories */
    private array $plugins = [];

    /** @var array<string, list<string>> slug => override directories last applied */
    private array $applied = [];

    /** @var array<string, string> slug => views dir added the legacy way (no overrides) */
    private array $legacy = [];

    public function __construct(private readonly ThemeManager $themes) {}

    /** Register an active plugin's namespace (ExtensionManager boot, pass 1). */
    public function register(Plugin $plugin): void
    {
        $slug = $plugin->slug;

        if (preg_match(self::SLUG, $slug) !== 1) {
            return;
        }

        $own = $this->isReserved($slug) ? null : $this->ownHints($plugin);

        // A hint outside the plugin (another owner, e.g. a slug "theme") is never
        // replaced: keep the exact pre-R1 behaviour and skip theme overrides.
        if ($own === null) {
            Log::warning('TN CMS plugin view namespace already in use; theme overrides disabled', ['plugin' => $slug]);

            if ($plugin->viewsPath() !== null) {
                View::addNamespace($slug, $plugin->viewsPath());
                $this->legacy[$slug] = $plugin->viewsPath();
            }

            return;
        }

        $this->plugins[$slug] = $own;
        $this->apply($slug);
    }

    /** Drop a deactivated plugin's namespace so neither its views nor overrides resolve. */
    public function forget(string $slug): void
    {
        $finder = View::getFinder();

        if (isset($this->legacy[$slug])) {
            // Legacy registration: drop only the plugin's own directory.
            $keep = array_values(array_filter($this->hints($slug), fn (string $h): bool => $h !== $this->legacy[$slug]));
            unset($this->legacy[$slug]);
            $finder->replaceNamespace($slug, $keep);
            $finder->flush();

            return;
        }

        if (! array_key_exists($slug, $this->plugins)) {
            return;
        }

        unset($this->plugins[$slug], $this->applied[$slug]);
        $finder->replaceNamespace($slug, []);
        $finder->flush();
    }

    /**
     * The public rendering contract: validate, then resolve `{plugin}::{view}` theme-first.
     * Throws {@see PluginViewException} (invalid_plugin, invalid_view, inactive_plugin,
     * not_found); never selects an unrelated view.
     */
    public function resolve(string $plugin, string $view): string
    {
        $this->assertValid($plugin, $view);

        // Worker-local namespace AND the persisted activation state (shared cache):
        // a deactivation in another process is honoured here (CORE-PLATFORM-SEAMS-R2).
        if (! $this->isActive($plugin) || ! app('cms.extension')->isPluginActive($plugin)) {
            throw new PluginViewException('inactive_plugin', $plugin, $view);
        }

        $name = $plugin.'::'.$view;

        if (! View::exists($name)) {
            throw new PluginViewException('not_found', $plugin, $view);
        }

        return $name;
    }

    /** Which layer serves the view: 'theme' (an override) or 'plugin'. */
    public function source(string $plugin, string $view): string
    {
        $file = View::getFinder()->find($this->resolve($plugin, $view));

        foreach ($this->overrideDirectories($plugin) as $dir) {
            if (str_starts_with($this->normalize((string) realpath($file)), $this->normalize((string) realpath($dir)).'/')) {
                return 'theme';
            }
        }

        return 'plugin';
    }

    /**
     * The documented override location, relative to a theme's `views` directory:
     * `plugins/{plugin}/{view with dots as slashes}.blade.php`. Consumers use this
     * instead of reproducing the convention.
     */
    public function overridePath(string $plugin, string $view): string
    {
        $this->assertValid($plugin, $view);

        return 'plugins/'.$plugin.'/'.str_replace('.', '/', $view).'.blade.php';
    }

    public function isActive(string $plugin): bool
    {
        return array_key_exists($plugin, $this->plugins) || isset($this->legacy[$plugin]);
    }

    private function assertValid(string $plugin, string $view): void
    {
        if (preg_match(self::SLUG, $plugin) !== 1 || $this->isReserved($plugin)) {
            throw new PluginViewException('invalid_plugin', $plugin, $view);
        }

        if (preg_match(self::VIEW, $view) !== 1) {
            throw new PluginViewException('invalid_view', $plugin, $view);
        }
    }

    /** Recompute every registered namespace for the current active theme chain. */
    public function refresh(): void
    {
        foreach (array_keys($this->plugins) as $slug) {
            $this->apply($slug);
        }
    }

    /**
     * The ordered hints for a registered plugin, or [] when it is not active.
     *
     * @return list<string>
     */
    public function paths(string $slug): array
    {
        if (! array_key_exists($slug, $this->plugins)) {
            return [];
        }

        return [...$this->overrideDirectories($slug), ...$this->plugins[$slug]];
    }

    /**
     * The plugin's own hints: any the plugin's provider already registered for its
     * slug (e.g. loadViewsFrom()) followed by resources/views — or null when an
     * existing hint lies outside the plugin directory.
     *
     * @return list<string>|null
     */
    private function ownHints(Plugin $plugin): ?array
    {
        if (isset($this->plugins[$plugin->slug])) {
            return $this->plugins[$plugin->slug];
        }

        $root = realpath($plugin->path);
        $existing = $this->hints($plugin->slug);
        $own = [];

        foreach ($existing as $hint) {
            $real = realpath($hint);

            if ($root === false || $real === false
                || ! str_starts_with($this->normalize($real).'/', $this->normalize($root).'/')) {
                return null;
            }

            $own[$this->normalize($real)] = $hint;
        }

        $views = $plugin->viewsPath();

        if ($views !== null && ($real = realpath($views)) !== false) {
            $own[$this->normalize($real)] ??= $views;
        }

        return array_values($own);
    }

    private function apply(string $slug): void
    {
        $overrides = $this->overrideDirectories($slug);
        $own = $this->plugins[$slug];

        // Keep hints added to the namespace after registration (e.g. a lazy
        // loadViewsFrom()); only the previously applied overrides are replaced.
        $later = array_values(array_diff(
            $this->hints($slug),
            $this->applied[$slug] ?? [],
            $own,
        ));

        $paths = array_values(array_unique([...$overrides, ...$own, ...$later]));
        $this->applied[$slug] = $overrides;

        if ($paths === []) {
            return;
        }

        View::getFinder()->replaceNamespace($slug, $paths);
        View::getFinder()->flush();
    }

    /**
     * Current hints for a namespace. Only Laravel's file finder exposes them; a
     * replaced finder reports none, so ownership checks fail closed to legacy.
     *
     * @return list<string>
     */
    private function hints(string $namespace): array
    {
        $finder = View::getFinder();

        return $finder instanceof FileViewFinder ? array_values($finder->getHints()[$namespace] ?? []) : [];
    }

    private function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true) || str_starts_with($slug, 'filament');
    }

    /** @return list<string> */
    private function overrideDirectories(string $slug): array
    {
        $out = [];

        try {
            $themeViews = $this->themes->viewNamespacePaths();
        } catch (\Throwable) {
            return [];
        }

        foreach ($themeViews as $root) {
            $candidate = $root.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.$slug;

            if (! File::isDirectory($candidate)) {
                continue;
            }

            $realRoot = realpath($root);
            $realCandidate = realpath($candidate);

            if ($realRoot === false || $realCandidate === false
                || ! str_starts_with($this->normalize($realCandidate), $this->normalize($realRoot).'/')) {
                continue;
            }

            $out[] = $candidate;
        }

        return $out;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
