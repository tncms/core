<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Str;

/**
 * Extension Translation Framework (v1.0.0-beta.5).
 *
 * A JSON translation framework for INTERFACE strings of the CMS core, the active
 * theme, and active plugins — separate from content translations (pages/posts/
 * categories/tags, which live in the *_translations tables).
 *
 * Standard locations (Laravel-style JSON, keyed by source string):
 *   - core:   lang/{locale}.json
 *   - theme:  themes/{slug}/lang/{locale}.json
 *   - plugin: plugins/{slug}/lang/{locale}.json
 *             (or plugins/{slug}/resources/lang/{locale}.json for compatibility;
 *              the bare lang/ path is preferred when both exist)
 *
 * Everything is defensive: a missing folder/file or invalid JSON never throws —
 * the lookup simply falls through to the next candidate and finally returns the
 * key itself. No auto/AI translation; missing files are created as empty {}.
 */
class ExtensionTranslationManager
{
    /**
     * Per-request cache of decoded JSON files, keyed by absolute path.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    public function __construct(
        private readonly LanguageManager $languages,
        private readonly ThemeManager $themes,
        private readonly ExtensionManager $extensions,
    ) {
    }

    // ---------------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------------

    /**
     * Core string: lang/{locale} → lang/{default} → key.
     *
     * @param  array<string, mixed>  $replace
     */
    public function core(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->resolveLocale($locale);
        $default = $this->defaultLocale();

        foreach ($this->coreCandidates($locale, $default) as $file) {
            $value = $this->lookup($file, $key);

            if ($value !== null) {
                return $this->makeReplacements($value, $replace);
            }
        }

        return $this->makeReplacements($key, $replace);
    }

    /**
     * Theme string: active theme {locale} → theme {default} → core {locale} →
     * core {default} → key.
     *
     * @param  array<string, mixed>  $replace
     */
    public function theme(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->resolveLocale($locale);
        $default = $this->defaultLocale();

        $candidates = [];
        $themeDir = $this->activeThemeLangDir();

        if ($themeDir !== null) {
            $candidates[] = $themeDir . DIRECTORY_SEPARATOR . $locale . '.json';

            if ($locale !== $default) {
                $candidates[] = $themeDir . DIRECTORY_SEPARATOR . $default . '.json';
            }
        }

        $candidates = array_merge($candidates, $this->coreCandidates($locale, $default));

        foreach ($candidates as $file) {
            $value = $this->lookup($file, $key);

            if ($value !== null) {
                return $this->makeReplacements($value, $replace);
            }
        }

        return $this->makeReplacements($key, $replace);
    }

    /**
     * Plugin string: plugin lang/{locale} → plugin resources/lang/{locale} →
     * plugin lang/{default} → plugin resources/lang/{default} → core {locale} →
     * core {default} → key.
     *
     * @param  array<string, mixed>  $replace
     */
    public function plugin(string $plugin, string $key, array $replace = [], ?string $locale = null): string
    {
        $locale = $this->resolveLocale($locale);
        $default = $this->defaultLocale();

        $candidates = [];
        $base = $this->pluginRoot($plugin);

        if ($base !== null) {
            $candidates[] = $base . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale . '.json';
            $candidates[] = $base . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $locale . '.json';

            if ($locale !== $default) {
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $default . '.json';
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . $default . '.json';
            }
        }

        $candidates = array_merge($candidates, $this->coreCandidates($locale, $default));

        foreach ($candidates as $file) {
            $value = $this->lookup($file, $key);

            if ($value !== null) {
                return $this->makeReplacements($value, $replace);
            }
        }

        return $this->makeReplacements($key, $replace);
    }

    // ---------------------------------------------------------------------
    // Boot: register JSON paths so Laravel __() also sees theme/plugin strings
    // ---------------------------------------------------------------------

    /**
     * Register the active theme + active plugin lang directories with Laravel's
     * translator (additive — never breaks core __()). Best-effort and fully
     * guarded so it is safe at boot before the DB/settings exist.
     */
    public function registerActiveTranslationPaths(): void
    {
        try {
            $translator = app('translator');

            if (! method_exists($translator, 'addJsonPath')) {
                return;
            }

            $themeDir = $this->activeThemeLangDir();

            if ($themeDir !== null && is_dir($themeDir)) {
                $translator->addJsonPath($themeDir);
            }

            foreach ($this->activePluginLangDirs() as $dir) {
                if (is_dir($dir)) {
                    $translator->addJsonPath($dir);
                }
            }
        } catch (\Throwable) {
            // Translation path registration is best-effort; never break boot.
        }
    }

    // ---------------------------------------------------------------------
    // Sync
    // ---------------------------------------------------------------------

    /**
     * Ensure a {locale}.json file exists (as empty {}) for every target — core,
     * the active theme, and each ACTIVE plugin — across the given locales (or all
     * active languages when null). Existing files are never overwritten; missing
     * parent folders are created. Returns a summary of relative paths.
     *
     * @param  array<int, string>|null  $locales
     * @return array{created: array<int, string>, skipped: array<int, string>, errors: array<int, string>}
     */
    public function syncTranslationFiles(?array $locales = null): array
    {
        $result = ['created' => [], 'skipped' => [], 'errors' => []];

        foreach ($this->normalizeLocales($locales) as $locale) {
            foreach ($this->syncTargetDirs() as $dir) {
                $this->ensureLocaleFile($dir, $locale, $result);
            }
        }

        return $result;
    }

    /**
     * Translation-file counts for the health endpoint.
     *
     * @return array{core: int, theme: int, plugin: int}
     */
    public function fileCounts(): array
    {
        return [
            'core' => $this->countJson($this->coreLangDir()),
            'theme' => $this->countJson($this->activeThemeLangDir()),
            'plugin' => $this->countActivePluginJson(),
        ];
    }

    /**
     * Number of keys in the core {locale}.json (the default locale when null).
     * Returns 0 when the file is missing or invalid. Never throws.
     */
    public function coreKeyCount(?string $locale = null): int
    {
        try {
            $locale = $locale !== null && trim($locale) !== ''
                ? $this->languages->normalizeCode($locale)
                : $this->defaultLocale();

            return count($this->load($this->coreFile($locale)));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Whether the core admin translation dictionary is in place: the default
     * locale's core JSON exists and is non-empty. Never throws — used by
     * /cms-health (admin_translation_ready).
     */
    public function adminTranslationReady(): bool
    {
        return $this->coreKeyCount() > 0;
    }

    /**
     * Count of core interface keys NOT yet translated across the active public
     * locales: keys present in the default-locale core file but missing or blank
     * in another active locale's core file. 0 means every active locale is
     * complete (the healthy state). Never throws — used by /cms-health
     * (core_translation_keys). Exposes only a count, never paths or values.
     */
    public function untranslatedCoreKeyCount(): int
    {
        try {
            $default = $this->defaultLocale();
            $base = $this->load($this->coreFile($default));

            if ($base === []) {
                return 0;
            }

            $missing = 0;

            foreach ($this->languages->getPublicLocales() as $code) {
                $locale = $this->languages->normalizeCode($code);

                if ($locale === $default) {
                    continue;
                }

                $target = $this->load($this->coreFile($locale));

                foreach (array_keys($base) as $key) {
                    $value = $target[$key] ?? null;

                    if (! is_string($value) || $value === '') {
                        $missing++;
                    }
                }
            }

            return $missing;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Core interface translation statistics for /cms-health. Counts only — never
     * paths, file contents, or translated values. Never throws.
     *
     * - keys_count: number of keys in the default-locale core JSON file. Falls
     *   back to lang/en.json when the configured default file is missing/empty.
     * - missing_keys_count: keys present in the default file but ENTIRELY absent
     *   from a non-default active locale's core file (summed across locales).
     * - untranslated_keys_count: keys whose value in a non-default active locale's
     *   core file is blank, or still equal to the source key (summed across
     *   locales). The source key language (English) is exempt from the
     *   equal-to-key rule — identity values there are the source, not a gap.
     *
     * @return array{keys_count: int, missing_keys_count: int, untranslated_keys_count: int}
     */
    public function coreTranslationStats(): array
    {
        $empty = ['keys_count' => 0, 'missing_keys_count' => 0, 'untranslated_keys_count' => 0];

        try {
            $default = $this->defaultLocale();
            $base = $this->load($this->coreFile($default));

            // Prefer the configured default; otherwise fall back to en.json.
            if ($base === [] && $default !== 'en') {
                $base = $this->load($this->coreFile('en'));
            }

            $stats = $empty;
            $stats['keys_count'] = count($base);

            if ($base === []) {
                return $stats;
            }

            $baseKeys = array_keys($base);

            foreach ($this->languages->getPublicLocales() as $code) {
                $locale = $this->languages->normalizeCode($code);

                if ($locale === $default) {
                    continue;
                }

                $target = $this->load($this->coreFile($locale));
                $isSource = $locale === 'en';

                foreach ($baseKeys as $key) {
                    if (! array_key_exists($key, $target)) {
                        $stats['missing_keys_count']++;

                        continue;
                    }

                    $value = $target[$key];

                    if (! is_string($value) || $value === '' || (! $isSource && $value === $key)) {
                        $stats['untranslated_keys_count']++;
                    }
                }
            }

            return $stats;
        } catch (\Throwable) {
            return $empty;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function coreCandidates(string $locale, string $default): array
    {
        $candidates = [$this->coreFile($locale)];

        if ($locale !== $default) {
            $candidates[] = $this->coreFile($default);
        }

        return $candidates;
    }

    private function resolveLocale(?string $locale): string
    {
        if ($locale !== null && trim($locale) !== '') {
            return $this->languages->normalizeCode($locale);
        }

        return current_locale();
    }

    private function defaultLocale(): string
    {
        return $this->languages->defaultCode();
    }

    private function coreLangDir(): string
    {
        return lang_path();
    }

    private function coreFile(string $locale): string
    {
        return lang_path($locale . '.json');
    }

    private function activeThemeLangDir(): ?string
    {
        try {
            $theme = $this->themes->active();
        } catch (\Throwable) {
            return null;
        }

        if ($theme === null) {
            return null;
        }

        return $theme->path . DIRECTORY_SEPARATOR . 'lang';
    }

    /**
     * Absolute path to plugins/{slug} when the folder exists, else null. Built
     * directly from the (sanitised) slug so it works even for a plugin with an
     * invalid manifest that still ships lang files.
     */
    private function pluginRoot(string $slug): ?string
    {
        $slug = (string) preg_replace('/[^a-z0-9_-]/i', '', $slug);

        if ($slug === '') {
            return null;
        }

        $root = rtrim((string) config('cms.paths.plugins', base_path('plugins')), '/\\');
        $dir = $root . DIRECTORY_SEPARATOR . $slug;

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Lang directories of all ACTIVE plugins (both lang/ and resources/lang/).
     *
     * @return array<int, string>
     */
    private function activePluginLangDirs(): array
    {
        $dirs = [];

        try {
            foreach ($this->extensions->activePlugins() as $plugin) {
                $dirs[] = $plugin->path . DIRECTORY_SEPARATOR . 'lang';
                $dirs[] = $plugin->path . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'lang';
            }
        } catch (\Throwable) {
            return [];
        }

        return $dirs;
    }

    /**
     * Directories to sync: core, active theme, and the preferred lang/ dir of
     * each active plugin.
     *
     * @return array<int, string>
     */
    private function syncTargetDirs(): array
    {
        $dirs = [$this->coreLangDir()];

        $themeDir = $this->activeThemeLangDir();

        if ($themeDir !== null) {
            $dirs[] = $themeDir;
        }

        try {
            foreach ($this->extensions->activePlugins() as $plugin) {
                $dirs[] = $plugin->path . DIRECTORY_SEPARATOR . 'lang';
            }
        } catch (\Throwable) {
            // Ignore plugin discovery failures during sync.
        }

        return $dirs;
    }

    /**
     * @param  array<int, string>|null  $locales
     * @return array<int, string>
     */
    private function normalizeLocales(?array $locales): array
    {
        if ($locales !== null) {
            $codes = array_map(
                fn ($l): string => $this->languages->normalizeCode(is_string($l) ? $l : ''),
                $locales,
            );
        } else {
            $codes = $this->languages->getPublicLocales();
        }

        $codes = array_values(array_filter($codes, static fn (string $c): bool => $c !== ''));

        if ($codes === []) {
            $codes = [$this->defaultLocale()];
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  array{created: array<int, string>, skipped: array<int, string>, errors: array<int, string>}  $result
     */
    private function ensureLocaleFile(string $dir, string $locale, array &$result): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . $locale . '.json';
        $relative = $this->relativePath($file);

        try {
            if (is_file($file)) {
                $result['skipped'][] = $relative;

                return;
            }

            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $result['errors'][] = $relative;

                return;
            }

            $json = json_encode(new \stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (@file_put_contents($file, $json . PHP_EOL) === false) {
                $result['errors'][] = $relative;

                return;
            }

            $result['created'][] = $relative;
        } catch (\Throwable) {
            $result['errors'][] = $relative;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $path): array
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        $data = [];

        try {
            if (is_file($path)) {
                $raw = file_get_contents($path);

                if ($raw !== false && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);

                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                }
            }
        } catch (\Throwable) {
            $data = [];
        }

        return $this->cache[$path] = $data;
    }

    private function lookup(string $path, string $key): ?string
    {
        $value = $this->load($path)[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Laravel-style placeholder replacement, including :key / :Key / :KEY casing.
     *
     * @param  array<string, mixed>  $replace
     */
    private function makeReplacements(string $line, array $replace): string
    {
        if ($replace === []) {
            return $line;
        }

        // Longer keys first so ":name" does not clobber ":namespace".
        uksort($replace, static fn ($a, $b): int => mb_strlen((string) $b) <=> mb_strlen((string) $a));

        foreach ($replace as $key => $value) {
            $value = (string) $value;

            $line = str_replace(
                [':' . $key, ':' . Str::ucfirst((string) $key), ':' . Str::upper((string) $key)],
                [$value, Str::ucfirst($value), Str::upper($value)],
                $line,
            );
        }

        return $line;
    }

    private function countJson(?string $dir): int
    {
        if ($dir === null || ! is_dir($dir)) {
            return 0;
        }

        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];

        return count($files);
    }

    private function countActivePluginJson(): int
    {
        $count = 0;

        foreach ($this->activePluginLangDirs() as $dir) {
            $count += $this->countJson($dir);
        }

        return $count;
    }

    /**
     * A base-relative, forward-slash path (never an absolute filesystem path) for
     * safe reporting in notifications and /cms-health.
     */
    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), '/\\');
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', $base);

        if (str_starts_with($normalized, $base . '/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return basename($normalized);
    }
}
