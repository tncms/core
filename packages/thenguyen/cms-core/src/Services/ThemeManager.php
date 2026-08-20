<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use TheNguyen\CMS\Support\ExtensionDiscovery;
use TheNguyen\CMS\Support\Theme;

/**
 * Discovers themes on disk, tracks the active theme via cms_settings
 * ("theme.active"), and publishes theme assets into the public directory
 * (copy, never symlink — shared-hosting friendly).
 */
class ThemeManager
{
    private const ACTIVE_KEY = 'theme.active';

    /**
     * Field types a theme option schema may declare (v0.9.9). Anything else is
     * skipped with a logged warning.
     */
    public const THEME_OPTION_FIELD_TYPES = ['text', 'textarea', 'boolean', 'number', 'select', 'image', 'color'];

    /**
     * Per-request memo of a theme's functions.php return value, keyed by slug.
     * Avoids repeated disk reads and re-`require` redeclaration risk.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $configCache = [];

    /**
     * Per-request registry memo for the directory scans (valid + invalid
     * themes). The themes directory only changes on install/delete/activate,
     * so a single scan per request is enough; reset by {@see flushRegistry()}.
     *
     * @var array<int, \TheNguyen\CMS\Support\Theme>|null
     */
    private ?array $allCache = null;

    /** @var array<int, array<string, string>>|null */
    private ?array $invalidCache = null;

    /**
     * All discovered themes, keyed numerically and ordered by slug.
     *
     * @return array<int, Theme>
     */
    public function all(): array
    {
        if ($this->allCache !== null) {
            return $this->allCache;
        }

        $root = $this->themesRoot();

        if (! File::isDirectory($root)) {
            return $this->allCache = [];
        }

        $themes = [];

        foreach (File::directories($root) as $path) {
            if (ExtensionDiscovery::isIgnored(basename($path))) {
                continue;
            }

            $theme = $this->buildFromPath($path);

            if ($theme !== null) {
                $themes[$theme->slug] = $theme;
            }
        }

        ksort($themes);

        return $this->allCache = array_values($themes);
    }

    /**
     * Drop the per-request registry memo. Call after any change to the themes
     * directory (install / delete / activate) so the next scan sees fresh state.
     */
    public function flushRegistry(): void
    {
        $this->allCache = null;
        $this->invalidCache = null;
        $this->configCache = [];
    }

    public function find(string $slug): ?Theme
    {
        if ($slug === '' || ExtensionDiscovery::isIgnored($slug)) {
            return null;
        }

        $path = $this->themePath($slug);

        if (! File::isDirectory($path)) {
            return null;
        }

        return $this->buildFromPath($path);
    }

    /**
     * The raw stored active-theme slug (cms_settings "theme.active"), or null
     * when the setting is unset/empty. This is the *explicit* selection, which
     * may not resolve to a theme on disk — use active() for the effective theme.
     */
    public function activeSlug(): ?string
    {
        $slug = settings(self::ACTIVE_KEY);

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * The effective active theme. Resolution order:
     *   1. the explicitly stored "theme.active" theme, if it exists on disk;
     *   2. otherwise the "default" theme, if present;
     *   3. otherwise the first discovered (valid) theme;
     *   4. null only when no valid theme exists at all.
     *
     * This guarantees there is always an effective active theme as long as one
     * valid theme folder exists, so the frontend never silently renders with no
     * theme just because "theme.active" was cleared. See CMS_ARCHITECTURE.md §10.
     */
    public function active(): ?Theme
    {
        $slug = $this->activeSlug();

        if ($slug !== null) {
            $theme = $this->find($slug);

            if ($theme !== null) {
                return $theme;
            }
        }

        return $this->effectiveFallback();
    }

    /**
     * The fallback theme used when no valid theme is explicitly active: the
     * "default" theme, then the first discovered theme, else null.
     */
    private function effectiveFallback(): ?Theme
    {
        $default = $this->find('default');

        if ($default !== null) {
            return $default;
        }

        return $this->all()[0] ?? null;
    }

    /**
     * True when at least one valid theme exists and an effective active theme
     * is resolvable. Used by /cms-health (never throws upstream).
     */
    public function themeSystemReady(): bool
    {
        try {
            return $this->active() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Standalone deactivation is not offered: TN CMS always requires exactly one
     * effective active theme, and the admin only ever *switches* the active
     * theme via activate(). This therefore always returns false in the normal
     * admin context (there is no Deactivate button). See CMS_ARCHITECTURE.md §10.
     */
    public function canDeactivate(): bool
    {
        return false;
    }

    /**
     * Core views a theme must be able to render (resolved through the theme's
     * own views, falling back to the default theme).
     */
    private const REQUIRED_VIEWS = [
        'layouts/master',
        'pages/page',
        'posts/post',
        'archives/index',
    ];

    /**
     * Activate (switch to) a theme by slug — transactional and safe.
     *
     * Steps, in order; on any failure the previously active theme is left
     * unchanged and the method returns false (the caller surfaces an error and
     * the frontend keeps using the old theme):
     *   1. remember the current active slug;
     *   2. validate the target manifest (find() returns null when invalid);
     *   3. verify the required views resolve (target → default fallback);
     *   4. publish the target theme's assets;
     *   5. set theme.active = target slug;
     *   6. clear the settings cache and re-register the view namespace.
     *
     * Nothing is mutated until step 5, so steps 2–4 act as a pre-flight that
     * cannot corrupt the live active theme.
     */
    public function activate(string $slug): bool
    {
        // 1. Remember the current active theme (for clarity / safety).
        $previousSlug = $this->activeSlug();

        // 2. Validate the target theme manifest.
        if ($this->find($slug) === null) {
            return false;
        }

        // 3. Verify the theme can safely render the required views.
        if (! $this->requiredViewsResolvable($slug)) {
            return false;
        }

        // 4. Publish the target theme's assets (failure leaves active unchanged).
        try {
            $this->publishAssets($slug);
        } catch (\Throwable) {
            return false;
        }

        // 5. Commit the switch. If persisting the setting fails, the previous
        //    active theme remains in place.
        try {
            settings()->set(self::ACTIVE_KEY, $slug, 'string', [
                'is_public' => true,
                'autoload' => true,
            ]);
        } catch (\Throwable) {
            return false;
        }

        // 6. Clear caches and re-register views so the switch takes effect.
        try {
            settings()->clearCache();
            $this->flushRegistry();
            $this->registerViews();
        } catch (\Throwable) {
            // The switch already succeeded; cache refresh is best-effort and
            // self-heals on the next request boot.
        }

        unset($previousSlug);

        return true;
    }

    /**
     * Switch the active theme to another valid theme (programmatic/internal).
     *
     * TN CMS always requires one active theme, so this never clears
     * theme.active: it switches to the first other valid theme and returns true,
     * or returns false when there is no other valid theme. The admin UI does not
     * expose a Deactivate action (see canDeactivate()); this is kept for
     * programmatic callers only. See CMS_ARCHITECTURE.md §10.
     */
    public function deactivate(): bool
    {
        $currentSlug = $this->active()?->slug;

        foreach ($this->all() as $theme) {
            if ($theme->slug !== $currentSlug) {
                return $this->activate($theme->slug);
            }
        }

        // No other valid theme to fall back to — refuse (never sets null).
        return false;
    }

    /**
     * True when every required view resolves for the given theme through the
     * "theme → default" fallback chain (i.e. the file exists in the target
     * theme's own views directory, or in the default theme's). This mirrors how
     * registerViews() resolves the "theme::" namespace, so a theme that passes
     * here is safe to render. Checks files on disk because the target theme is
     * not the active namespace yet.
     */
    public function requiredViewsResolvable(string $slug): bool
    {
        foreach (self::REQUIRED_VIEWS as $view) {
            $relative = str_replace('/', DIRECTORY_SEPARATOR, $view) . '.blade.php';

            $inTarget = File::exists($this->themePath($slug) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $relative);
            $inDefault = File::exists($this->themePath('default') . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $relative);

            if (! $inTarget && ! $inDefault) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register the "theme::" Blade namespace WITHOUT touching the database.
     *
     * Resolving the active theme requires a settings (DB) read; that is not
     * available on the pre-install boot hot path or in the test harness before
     * migrations run. This variant registers the namespace purely from the
     * filesystem — an optional explicit theme slug first, then always the
     * default theme as a fallback — so "theme::" views resolve deterministically
     * regardless of install state. The installed boot path additionally calls
     * {@see registerViews()} to layer the DB-resolved active theme on top.
     *
     * Safe to call during boot — performs zero queries and never throws.
     */
    public function registerViewNamespace(?string $themeSlug = null): void
    {
        try {
            $paths = [];

            foreach ([$themeSlug, 'default'] as $slug) {
                if (! is_string($slug) || $slug === '') {
                    continue;
                }

                $dir = $this->themePath($slug).DIRECTORY_SEPARATOR.'views';

                if (File::isDirectory($dir) && ! in_array($dir, $paths, true)) {
                    $paths[] = $dir;
                }
            }

            if ($paths !== []) {
                View::addNamespace('theme', $paths);
            }
        } catch (\Throwable) {
            // Never break application boot over theme view registration.
        }
    }

    /**
     * Register the "theme::" Blade namespace for the active theme, falling
     * back to the default theme. Safe to call during boot — never throws.
     *
     * View::addNamespace accepts multiple hint paths and resolves the first
     * that contains the requested view, so a partially-overridden active
     * theme transparently inherits missing views from the default theme.
     */
    public function registerViews(): void
    {
        try {
            $paths = $this->viewNamespacePaths();

            if ($paths !== []) {
                View::addNamespace('theme', $paths);
            }
        } catch (\Throwable) {
            // Never break application boot over theme view registration.
        }
    }

    /**
     * Existing "views" directories for the active theme (then default).
     *
     * @return array<int, string>
     */
    public function viewNamespacePaths(): array
    {
        $paths = [];

        $activeSlug = null;
        try {
            $activeSlug = $this->active()?->slug;
        } catch (\Throwable) {
            $activeSlug = null;
        }

        foreach ([$activeSlug, 'default'] as $slug) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $dir = $this->themePath($slug) . DIRECTORY_SEPARATOR . 'views';

            if (File::isDirectory($dir) && ! in_array($dir, $paths, true)) {
                $paths[] = $dir;
            }
        }

        return $paths;
    }

    // ---------------------------------------------------------------------
    // Theme Options (v0.9.9) — functions.php loading + option schema
    // ---------------------------------------------------------------------

    /**
     * Safely load a theme's functions.php and return its array.
     *
     * functions.php is treated as a *config-returning* file: it must `return`
     * an array. A missing file returns []; a file that does not return an array,
     * or that throws, is caught/logged and returns [] — it must never crash the
     * admin or frontend. The result is memoised per slug for the request.
     *
     * @return array<string, mixed>
     */
    public function themeConfig(?string $slug = null): array
    {
        $slug = $this->resolveSlug($slug);

        if ($slug === null) {
            return [];
        }

        if (isset($this->configCache[$slug])) {
            return $this->configCache[$slug];
        }

        $file = $this->themePath($slug) . DIRECTORY_SEPARATOR . 'functions.php';

        if (! File::exists($file)) {
            return $this->configCache[$slug] = [];
        }

        try {
            // Include in an isolated scope so the file cannot touch $this/locals.
            $data = (static fn (): mixed => require $file)();
        } catch (\Throwable $e) {
            Log::warning('TN CMS: theme functions.php failed for "' . $slug . '": ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return $this->configCache[$slug] = [];
        }

        return $this->configCache[$slug] = is_array($data) ? $data : [];
    }

    /**
     * The validated, normalised theme option schema for a theme.
     *
     * Reads functions.php → `options.sections`, validates each section/field,
     * and returns `['sections' => [...]]` (empty when the theme declares none or
     * the declaration is invalid). Invalid sections/fields and unsupported field
     * types are skipped (logged); duplicate field keys keep the first definition.
     *
     * @return array{sections: array<int, array<string, mixed>>}
     */
    public function themeOptionsSchema(?string $slug = null): array
    {
        $slug = $this->resolveSlug($slug);

        if ($slug === null) {
            return ['sections' => []];
        }

        // The declarative theme.options.json is authoritative (theme-architecture
        // 07/14); fall back to functions.php `options` for themes that still inline
        // their schema there.
        $options = $this->readThemeOptionsJson($slug);

        if (! is_array($options)) {
            $config = $this->themeConfig($slug);
            $options = is_array($config['options'] ?? null) ? $config['options'] : null;
        }

        if (! is_array($options)) {
            return ['sections' => []];
        }

        return ['sections' => $this->normalizeSections($options['sections'] ?? null)];
    }

    /**
     * Read a theme's declarative options schema from theme.options.json, or null
     * when the file is missing/invalid or declares no `options` object.
     *
     * @return array<string, mixed>|null
     */
    private function readThemeOptionsJson(string $slug): ?array
    {
        $file = $this->themePath($slug) . DIRECTORY_SEPARATOR . 'theme.options.json';

        if (! File::exists($file)) {
            return null;
        }

        $data = json_decode((string) File::get($file), true);

        return is_array($data['options'] ?? null) ? $data['options'] : null;
    }

    /**
     * True when the theme declares at least one valid option section with fields.
     */
    public function hasThemeOptions(?string $slug = null): bool
    {
        return $this->themeOptionsSchema($slug)['sections'] !== [];
    }

    /**
     * Resolve a slug argument to a concrete theme slug, defaulting to the
     * effective active theme. Returns null when no theme can be resolved.
     */
    private function resolveSlug(?string $slug): ?string
    {
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        try {
            return $this->active()?->slug;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate + normalise the declared sections. Field keys are deduplicated
     * globally across the whole schema (first definition wins).
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSections(mixed $sections): array
    {
        if (! is_array($sections)) {
            return [];
        }

        $result = [];
        $seenFieldKeys = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = $section['key'] ?? null;
            $label = $section['label'] ?? null;
            $fields = $section['fields'] ?? null;

            if (! $this->isSlugLike($key) || ! is_string($label) || $label === '' || ! is_array($fields)) {
                continue;
            }

            $normalizedFields = [];

            foreach ($fields as $field) {
                $normalized = $this->normalizeField($field, $seenFieldKeys);

                if ($normalized !== null) {
                    $seenFieldKeys[$normalized['key']] = true;
                    $normalizedFields[] = $normalized;
                }
            }

            if ($normalizedFields === []) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $label,
                'description' => is_string($section['description'] ?? null) ? $section['description'] : '',
                'fields' => $normalizedFields,
            ];
        }

        return $result;
    }

    /**
     * Validate + normalise a single field definition, or null when invalid.
     *
     * @param  array<string, true>  $seenFieldKeys
     * @return array<string, mixed>|null
     */
    private function normalizeField(mixed $field, array $seenFieldKeys): ?array
    {
        if (! is_array($field)) {
            return null;
        }

        $key = $field['key'] ?? null;
        $label = $field['label'] ?? null;
        $type = $field['type'] ?? null;

        if (! $this->isSlugLike($key) || ! is_string($label) || $label === '') {
            return null;
        }

        if (! is_string($type) || ! in_array($type, self::THEME_OPTION_FIELD_TYPES, true)) {
            Log::warning('TN CMS: unsupported theme option field type "'
                . (is_string($type) ? $type : gettype($type)) . '" for field "' . $key . '" — skipped.');

            return null;
        }

        if (isset($seenFieldKeys[$key])) {
            Log::warning('TN CMS: duplicate theme option field key "' . $key . '" — ignored (first definition wins).');

            return null;
        }

        $normalized = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'default' => $field['default'] ?? null,
            'helper' => is_string($field['helper'] ?? null) ? $field['helper'] : null,
            'placeholder' => is_string($field['placeholder'] ?? null) ? $field['placeholder'] : null,
        ];

        if ($type === 'select') {
            $normalized['options'] = is_array($field['options'] ?? null) ? $field['options'] : [];
        }

        if ($type === 'number') {
            $normalized['min'] = is_numeric($field['min'] ?? null) ? $field['min'] : null;
            $normalized['max'] = is_numeric($field['max'] ?? null) ? $field['max'] : null;
        }

        return $normalized;
    }

    private function isSlugLike(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match('/^[a-z0-9_-]+$/i', $value) === 1;
    }

    /**
     * Absolute path to a theme's source directory.
     */
    public function themePath(string $slug): string
    {
        return $this->themesRoot() . DIRECTORY_SEPARATOR . $slug;
    }

    /**
     * Absolute path to a theme's source assets directory.
     */
    public function themeAssetsPath(string $slug): string
    {
        return $this->themePath($slug) . DIRECTORY_SEPARATOR . 'assets';
    }

    /**
     * Copy themes/{slug}/assets into public/themes/{slug}. Creates the
     * destination if needed; does nothing harmful when there are no assets.
     */
    public function publishAssets(string $slug): void
    {
        $destination = $this->publicThemePath($slug);

        if (! File::isDirectory($destination)) {
            File::makeDirectory($destination, 0755, true, true);
        }

        $source = $this->themeAssetsPath($slug);

        if (File::isDirectory($source)) {
            File::copyDirectory($source, $destination);
        }
    }

    private function publicThemePath(string $slug): string
    {
        $base = (string) config('cms.paths.theme_assets', public_path('themes'));

        return $base . DIRECTORY_SEPARATOR . $slug;
    }

    private function themesRoot(): string
    {
        return (string) config('cms.paths.themes', base_path('themes'));
    }

    /**
     * Required theme.json keys (must be present and non-empty strings).
     */
    private const REQUIRED_MANIFEST_KEYS = ['name', 'slug', 'version', 'author'];

    /**
     * Discover directories whose theme.json is missing or fails validation, so
     * the admin can surface them without breaking the page. Valid themes are
     * returned by all(); invalid ones only appear here.
     *
     * @return array<int, array{slug: string, reason: string}>
     */
    public function invalidThemes(): array
    {
        if ($this->invalidCache !== null) {
            return $this->invalidCache;
        }

        $root = $this->themesRoot();

        if (! File::isDirectory($root)) {
            return $this->invalidCache = [];
        }

        $invalid = [];

        foreach (File::directories($root) as $path) {
            $dir = basename($path);

            if (ExtensionDiscovery::isIgnored($dir)) {
                continue;
            }

            $data = $this->readManifest($path);

            if ($data === null) {
                $invalid[] = ['slug' => $dir, 'reason' => 'Missing or invalid theme.json'];

                continue;
            }

            $missing = $this->manifestErrors($data);

            if ($missing !== []) {
                $invalid[] = [
                    'slug' => is_string($data['slug'] ?? null) && $data['slug'] !== '' ? $data['slug'] : $dir,
                    'reason' => 'Missing required field(s): ' . implode(', ', $missing),
                ];
            }
        }

        return $this->invalidCache = $invalid;
    }

    private function buildFromPath(string $path): ?Theme
    {
        $data = $this->readManifest($path);

        if ($data === null || $this->manifestErrors($data) !== []) {
            return null;
        }

        $screenshotFile = $path . DIRECTORY_SEPARATOR . 'screenshot.png';
        $screenshot = File::exists($screenshotFile) ? $screenshotFile : null;

        return new Theme(
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            version: (string) $data['version'],
            author: (string) $data['author'],
            description: (string) ($data['description'] ?? ''),
            path: $path,
            screenshot: $screenshot,
            supports: is_array($data['supports'] ?? null) ? $data['supports'] : [],
            authorUri: $this->stringOrNull($data['author_uri'] ?? null),
            supportEmail: $this->stringOrNull($data['support_email'] ?? null),
        );
    }

    /**
     * Read and JSON-decode a theme's theme.json, or null when missing/invalid.
     *
     * @return array<string, mixed>|null
     */
    private function readManifest(string $path): ?array
    {
        $jsonFile = $path . DIRECTORY_SEPARATOR . 'theme.json';

        if (! File::exists($jsonFile)) {
            return null;
        }

        $data = json_decode((string) File::get($jsonFile), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Required manifest keys that are missing or empty.
     *
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

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
