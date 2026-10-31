<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TheNguyen\CMS\Support\ExtensionDiscovery;
use TheNguyen\CMS\Support\Plugin;
use TheNguyen\CMS\Support\Theme;

/**
 * Extension Framework Core (v0.9.8).
 *
 * The orchestration layer over TN CMS extensions — themes (delegated to the
 * existing ThemeManager) and plugins (discovered here). It provides plugin
 * discovery + manifest validation, the active-plugin registry (stored in
 * cms_settings under "extensions.active_plugins"), and safe booting of active
 * plugins (PSR-4 autoload, service providers, web routes, view namespace, and
 * migration discovery). A broken plugin is caught/logged/skipped and never
 * crashes the CMS. This is the foundation for the future Plugin Manager, Theme
 * Options, Marketplace and ZIP installer — none of which exist yet.
 *
 * Plugin namespace convention: a plugin's PHP classes live under
 * `Plugins\{StudlySlug}\` mapped to `plugins/{slug}/src/`.
 */
class ExtensionManager
{
    private const ACTIVE_PLUGINS_KEY = 'extensions.active_plugins';

    /**
     * Required plugin.json keys (non-empty strings).
     */
    private const REQUIRED_MANIFEST_KEYS = ['name', 'slug', 'version', 'author'];

    /**
     * Slugs whose PSR-4 autoloader has already been registered this request.
     *
     * @var array<string, true>
     */
    private array $autoloaded = [];

    /**
     * Active plugins whose runtime could not be loaded by bootActivePlugins()
     * this request: registration threw, or a declared provider class does
     * not exist (CORE-38-TOPLIST-SEAMS-R1).
     *
     * @var array<string, true>
     */
    private array $unloadable = [];

    /**
     * Per-request registry memo for the plugins directory scan (valid +
     * invalid). The directory only changes on install/delete, so one scan per
     * request is enough; reset by {@see flushRegistry()}.
     *
     * @var array<int, \TheNguyen\CMS\Support\Plugin>|null
     */
    private ?array $pluginsCache = null;

    /** @var array<int, array<string, string>>|null */
    private ?array $invalidPluginsCache = null;

    public function __construct(private readonly ThemeManager $themes) {}

    // ---------------------------------------------------------------------
    // Themes (delegated to ThemeManager)
    // ---------------------------------------------------------------------

    /**
     * @return array<int, Theme>
     */
    public function themes(): array
    {
        return $this->themes->all();
    }

    /**
     * @return array<int, array{slug: string, reason: string}>
     */
    public function invalidThemes(): array
    {
        return $this->themes->invalidThemes();
    }

    // ---------------------------------------------------------------------
    // Plugin discovery
    // ---------------------------------------------------------------------

    /**
     * All valid discovered plugins, ordered by slug.
     *
     * @return array<int, Plugin>
     */
    public function plugins(): array
    {
        if ($this->pluginsCache !== null) {
            return $this->pluginsCache;
        }

        $root = $this->pluginsRoot();

        if (! File::isDirectory($root)) {
            return $this->pluginsCache = [];
        }

        $plugins = [];

        foreach (File::directories($root) as $path) {
            if (ExtensionDiscovery::isIgnored(basename($path))) {
                continue;
            }

            $plugin = $this->buildFromPath($path);

            if ($plugin !== null) {
                $plugins[$plugin->slug] = $plugin;
            }
        }

        ksort($plugins);

        return $this->pluginsCache = array_values($plugins);
    }

    /**
     * Drop the per-request plugin registry memo. Call after any change to the
     * plugins directory (install / delete) so the next scan sees fresh state.
     */
    public function flushRegistry(): void
    {
        $this->pluginsCache = null;
        $this->invalidPluginsCache = null;
    }

    public function findPlugin(string $slug): ?Plugin
    {
        if ($slug === '' || ExtensionDiscovery::isIgnored($slug)) {
            return null;
        }

        $path = $this->pluginsRoot().DIRECTORY_SEPARATOR.$slug;

        if (! File::isDirectory($path)) {
            return null;
        }

        return $this->buildFromPath($path);
    }

    /**
     * Plugin folders with a missing/invalid manifest (never crashes the system).
     *
     * @return array<int, array{slug: string, reason: string}>
     */
    public function invalidPlugins(): array
    {
        if ($this->invalidPluginsCache !== null) {
            return $this->invalidPluginsCache;
        }

        $root = $this->pluginsRoot();

        if (! File::isDirectory($root)) {
            return $this->invalidPluginsCache = [];
        }

        $invalid = [];

        foreach (File::directories($root) as $path) {
            $dir = basename($path);

            if (ExtensionDiscovery::isIgnored($dir)) {
                continue;
            }

            $data = $this->readManifest($path);

            if ($data === null) {
                $invalid[] = ['slug' => $dir, 'reason' => 'Missing or invalid plugin.json'];

                continue;
            }

            $missing = $this->manifestErrors($data);

            if ($missing !== []) {
                $invalid[] = [
                    'slug' => is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : $dir,
                    'reason' => 'Missing required field(s): '.implode(', ', $missing),
                ];
            }
        }

        return $this->invalidPluginsCache = $invalid;
    }

    // ---------------------------------------------------------------------
    // Active plugin registry (cms_settings: extensions.active_plugins)
    // ---------------------------------------------------------------------

    /**
     * Raw active plugin slugs from settings (may include slugs whose folder was
     * since removed). Use activePlugins() for resolved, valid Plugin objects.
     *
     * @return array<int, string>
     */
    public function activePluginSlugs(): array
    {
        $value = settings(self::ACTIVE_PLUGINS_KEY, []);

        if (! is_array($value)) {
            return [];
        }

        $slugs = array_map(static fn ($v): string => is_string($v) ? $v : '', $value);

        return array_values(array_filter($slugs, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Valid, discovered plugins that are currently active.
     *
     * @return array<int, Plugin>
     */
    public function activePlugins(): array
    {
        $active = $this->activePluginSlugs();

        if ($active === []) {
            return [];
        }

        return array_values(array_filter(
            $this->plugins(),
            static fn (Plugin $p): bool => in_array($p->slug, $active, true),
        ));
    }

    public function isPluginActive(string $slug): bool
    {
        return in_array($slug, $this->activePluginSlugs(), true);
    }

    /**
     * Configured active but its runtime failed to load this request (see
     * $unloadable). Configured-active alone does not mean the plugin's code is
     * running; owner-sensitive callers such as content ownership check this.
     */
    public function isPluginUnloadable(string $slug): bool
    {
        // Deactivated since the failed boot: it is simply inactive now.
        return isset($this->unloadable[$slug]) && $this->isPluginActive($slug);
    }

    /**
     * Activate a plugin. The plugin must exist with a valid manifest. Idempotent.
     * Booting happens on the next request (or call bootActivePlugins()).
     */
    public function activatePlugin(string $slug): bool
    {
        if ($this->findPlugin($slug) === null) {
            return false;
        }

        $active = $this->activePluginSlugs();

        if (! in_array($slug, $active, true)) {
            $active[] = $slug;
            $this->storeActive($active);
            $this->invalidateRouteCache();
        }

        return true;
    }

    /**
     * Deactivate a plugin (remove it from the active registry). Idempotent —
     * succeeds even if the plugin folder was already removed.
     */
    public function deactivatePlugin(string $slug): bool
    {
        $active = $this->activePluginSlugs();

        if (! in_array($slug, $active, true)) {
            return false;
        }

        $this->storeActive(array_values(array_filter(
            $active,
            static fn (string $s): bool => $s !== $slug,
        )));

        $this->revokeTrustedScriptApprovals($slug);
        $this->forgetPluginViews($slug);
        $this->invalidateRouteCache();

        return true;
    }

    /**
     * A cached route table (`route:cache`) bakes in the active plugins' routes and
     * the localized segments they were generated from; drop it so the next boot
     * rebuilds from the new active set (CORE-LOTTERY-P05-SEAMS-1).
     */
    private function invalidateRouteCache(): void
    {
        try {
            if (app()->routesAreCached()) {
                File::delete(app()->getCachedRoutesPath());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** An inactive plugin's views and theme overrides stop resolving immediately. */
    private function forgetPluginViews(string $slug): void
    {
        try {
            if (app()->bound('cms.plugin_views')) {
                app('cms.plugin_views')->forget($slug);
            }
        } catch (\Throwable) {
            // Best-effort: the namespace is not registered on the next boot anyway.
        }
    }

    /**
     * Deactivation revokes every trusted external script approval the plugin
     * holds (CORE-EXTENSION-TRUST S1, ADR D-2), so reactivating it can never
     * silently restore a third-party script: an administrator must approve
     * again. Best-effort — a failure here must not block deactivation, and the
     * scripts stop rendering anyway because only ACTIVE plugins are consulted.
     */
    private function revokeTrustedScriptApprovals(string $slug): void
    {
        try {
            if (app()->bound('cms.trusted_script_approvals')) {
                app('cms.trusted_script_approvals')->revokeAllForOwner($slug);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ---------------------------------------------------------------------
    // Booting active plugins
    // ---------------------------------------------------------------------

    /**
     * Boot every active, valid plugin: register PSR-4 autoload, its service
     * providers, view namespace, migrations, and web routes. Each plugin is
     * isolated in a try/catch so a single broken plugin is logged and skipped
     * without crashing the CMS. Call this from the service provider BEFORE the
     * frontend catch-all route is registered, so plugin routes take precedence.
     */
    public function bootActivePlugins(): void
    {
        $plugins = $this->activePlugins();

        // Two passes (P6.3). Pass 1 registers every active plugin's providers, views, and
        // migrations; pass 2 loads their web routes. Splitting the passes guarantees that ALL
        // plugin providers have registered their contributions — including build-time Route
        // Dictionary sources — BEFORE any plugin's routes are generated. Plugin routes are the
        // earliest boot-time consumer of the localized Route Dictionary (e.g. ecommerce's
        // reverse-route generation), so the source set must be complete first. For a single
        // active plugin the two passes are identical to the old per-plugin order.
        // CORE-PLATFORM-SEAMS-R2: pass 1 is the dictionary registration window; a
        // plugin that reads the dictionary here fails alone (lifecycle exception).
        try {
            if (app()->bound('cms.localized_routes')) {
                app('cms.localized_routes')->openRegistration();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $failed = [];

        foreach ($plugins as $plugin) {
            try {
                $this->registerPlugin($plugin);
            } catch (\Throwable $e) {
                $failed[$plugin->slug] = true;
                $this->unloadable[$plugin->slug] = true;
                Log::error('TN CMS: failed to register plugin "'.$plugin->slug.'": '.$e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        // Every source is registered: compose the Route Dictionary once and run
        // routes deferred by earlier providers, ahead of plugin routes
        // (CORE-PLATFORM-SEAMS-R1).
        try {
            if (app()->bound('cms.localized_routes')) {
                app('cms.localized_routes')->finalize();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($plugins as $plugin) {
            // A plugin whose registration failed serves no routes (no half-booted plugin).
            if (isset($failed[$plugin->slug])) {
                Log::warning('TN CMS: routes skipped for plugin "'.$plugin->slug.'": its registration failed.');

                continue;
            }

            try {
                $this->loadPluginRoutes($plugin);
            } catch (\Throwable $e) {
                Log::error('TN CMS: failed to load routes for plugin "'.$plugin->slug.'": '.$e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }
    }

    private function registerPlugin(Plugin $plugin): void
    {
        // 1. PSR-4 autoload for the plugin's src/ directory.
        $this->ensurePluginAutoload($plugin);

        // 2. Service providers declared in plugin.json.
        foreach ($plugin->providers as $providerClass) {
            if ($providerClass !== '' && class_exists($providerClass)) {
                app()->register($providerClass);
            } elseif ($providerClass !== '') {
                // A declared runtime that is not there: the plugin is not running.
                $this->unloadable[$plugin->slug] = true;
                Log::warning('TN CMS: provider class missing for plugin "'.$plugin->slug.'".');
            }
        }

        // 3. View namespace: view('{slug}::name'), theme override first
        //    (themes/{theme}/views/plugins/{slug}/), then the plugin's views.
        app('cms.plugin_views')->register($plugin);

        // 4. Migration discovery: make every configured (or conventional)
        // migration directory visible to the migrator, so `php artisan migrate`
        // sees them too. Config-aware via plugin.json "database.migrations".
        foreach ($plugin->databaseMigrationPaths() as $migrations) {
            $this->loadMigrationsPath($migrations);
        }
    }

    private function loadPluginRoutes(Plugin $plugin): void
    {
        // A plugin whose route-segment source Core refused serves no routes: its
        // localized URLs would be wrong, so it fails closed alone (CORE-LOTTERY-P05-SEAMS-1).
        try {
            if (app(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry::class)->isRefused('plugin:'.$plugin->slug)) {
                Log::warning('TN CMS: routes skipped for plugin "'.$plugin->slug.'": its route dictionary source was refused.');

                return;
            }
        } catch (\Throwable) {
            // No registry (pre-install boot): load routes as before.
        }

        // 5. Web routes (wrapped in the web middleware group).
        $routes = $plugin->webRoutesFile();
        if ($routes !== null) {
            // cms.plugin-active: a route table that outlives a deactivation (route cache,
            // a long-running worker) answers 404 for this plugin (CORE-PLATFORM-SEAMS-R2).
            Route::middleware(['web', 'cms.plugin-active:'.$plugin->slug])->group($routes);
        }
    }

    /**
     * Ensure the PSR-4 autoloader mapping `Plugins\{StudlySlug}\` → the plugin's
     * src/ directory is registered, so its classes load without a Composer dump.
     *
     * Idempotent per request (guarded by $this->autoloaded). This is the SAME
     * autoload logic the runtime boot uses (registerPlugin step 1), exposed publicly
     * so callers that inspect plugin classes outside the boot path — e.g. the
     * admin Plugin Manager checking provider availability right after activating a
     * plugin that was inactive at boot — register the loader before
     * class_exists(). Without this, class_exists() can falsely fail for a
     * just-activated plugin whose autoloader was never registered this request.
     */
    public function ensurePluginAutoload(Plugin $plugin): void
    {
        if (isset($this->autoloaded[$plugin->slug])) {
            return;
        }

        $this->autoloaded[$plugin->slug] = true;

        $prefix = 'Plugins\\'.Str::studly($plugin->slug).'\\';
        $baseDir = $plugin->srcPath().DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }

    /**
     * Whether a plugin provider class is actually loadable, using the SAME
     * autoload logic as runtime boot: the plugin's PSR-4 loader is ensured first,
     * then class_exists() is checked. This avoids false negatives for a plugin
     * that was inactive when the request booted (so bootActivePlugins did not
     * register its autoloader).
     */
    public function providerExists(Plugin $plugin, string $provider): bool
    {
        if ($provider === '') {
            return false;
        }

        $this->ensurePluginAutoload($plugin);

        return class_exists($provider);
    }

    /**
     * Warnings for any declared provider class that cannot be loaded (after
     * ensuring the plugin's autoloader). Empty when every provider resolves —
     * including for a plugin whose provider boots correctly at runtime.
     *
     * @return array<int, string>
     */
    public function pluginProviderWarnings(Plugin $plugin): array
    {
        $missing = [];

        foreach ($plugin->providers as $provider) {
            if ($provider !== '' && ! $this->providerExists($plugin, $provider)) {
                $missing[] = $provider;
            }
        }

        if ($missing === []) {
            return [];
        }

        return ['Provider class not found: '.implode(', ', $missing)];
    }

    /**
     * Make a plugin's migrations discoverable by the migrator (mirrors
     * ServiceProvider::loadMigrationsFrom without needing a provider instance).
     */
    private function loadMigrationsPath(string $path): void
    {
        app()->afterResolving('migrator', static function ($migrator) use ($path): void {
            $migrator->path($path);
        });
    }

    // ---------------------------------------------------------------------
    // Filament integration (generic — applies to every active plugin)
    // ---------------------------------------------------------------------

    /**
     * Fully-qualified Filament Resource classes declared by active plugins
     * (manifest "filament.resources"). Each plugin's PSR-4 autoloader is ensured
     * first, and only loadable classes are returned, so the admin panel can
     * register plugin resources without a Composer dump. Best-effort: a missing
     * DB or broken plugin yields fewer results, never an error.
     *
     * @return array<int, string>
     */
    public function activeFilamentResources(): array
    {
        return $this->collectActiveFilamentClasses(static fn (Plugin $p): array => $p->filamentResources);
    }

    /**
     * Fully-qualified Filament Page classes declared by active plugins
     * (manifest "filament.pages"). See activeFilamentResources().
     *
     * @return array<int, string>
     */
    public function activeFilamentPages(): array
    {
        return $this->collectActiveFilamentClasses(static fn (Plugin $p): array => $p->filamentPages);
    }

    /**
     * @param  callable(Plugin): array<int, string>  $extract
     * @return array<int, string>
     */
    private function collectActiveFilamentClasses(callable $extract): array
    {
        $classes = [];

        try {
            foreach ($this->activePlugins() as $plugin) {
                $this->ensurePluginAutoload($plugin);

                foreach ($extract($plugin) as $class) {
                    if (class_exists($class)) {
                        $classes[$class] = true;
                    }
                }
            }
        } catch (\Throwable) {
            // Best-effort: never break panel construction over a plugin issue.
        }

        return array_keys($classes);
    }

    // ---------------------------------------------------------------------
    // Health
    // ---------------------------------------------------------------------

    /**
     * True when the framework is bound and plugin discovery runs without error.
     */
    public function extensionFrameworkReady(): bool
    {
        try {
            $this->plugins();

            return app()->bound('cms.extension');
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function storeActive(array $slugs): void
    {
        settings()->set(self::ACTIVE_PLUGINS_KEY, array_values(array_unique($slugs)), 'array', [
            'is_public' => false,
            'autoload' => true,
            'description' => 'Active plugin slugs',
        ]);
        // SettingsManager::set() already clears the autoload cache.
    }

    private function buildFromPath(string $path): ?Plugin
    {
        $data = $this->readManifest($path);

        if ($data === null || $this->manifestErrors($data) !== []) {
            return null;
        }

        $providers = [];
        if (is_array($data['providers'] ?? null)) {
            foreach ($data['providers'] as $provider) {
                if (is_string($provider) && $provider !== '') {
                    $providers[] = $provider;
                }
            }
        }

        $filament = is_array($data['filament'] ?? null) ? $data['filament'] : [];

        return new Plugin(
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            version: (string) $data['version'],
            author: (string) $data['author'],
            description: (string) ($data['description'] ?? ''),
            path: $path,
            providers: $providers,
            requires: is_array($data['requires'] ?? null) ? $data['requires'] : [],
            homepage: $this->optionalString($data, 'homepage'),
            authorUri: $this->optionalString($data, 'author_uri'),
            supportEmail: $this->optionalString($data, 'support_email'),
            filamentResources: $this->stringList($filament['resources'] ?? null),
            filamentPages: $this->stringList($filament['pages'] ?? null),
            database: is_array($data['database'] ?? null) ? $data['database'] : [],
        );
    }

    /**
     * Normalise a manifest value into a list of non-empty FQCN strings.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * Read an optional non-empty string field from a manifest, or null.
     *
     * @param  array<string, mixed>  $data
     */
    private function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $path): ?array
    {
        $jsonFile = $path.DIRECTORY_SEPARATOR.'plugin.json';

        if (! File::exists($jsonFile)) {
            return null;
        }

        $data = json_decode((string) File::get($jsonFile), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function manifestErrors(array $data): array
    {
        $missing = [];

        foreach (self::REQUIRED_MANIFEST_KEYS as $key) {
            $value = $data[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function pluginsRoot(): string
    {
        return (string) config('cms.paths.plugins', base_path('plugins'));
    }
}
