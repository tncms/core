<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Setting;
use TheNguyen\CMS\Models\SettingTranslation;
use Throwable;

class SettingsManager
{
    private const CACHE_KEY = 'cms.settings.autoload';

    private const TRANSLATIONS_CACHE_KEY = 'cms.settings.translations';

    /**
     * Memoized cms_settings availability (v1.0.0-beta.6.3). Once the table is
     * known to exist, every subsequent get()/has() on this (singleton) instance
     * reuses the cached `true` instead of issuing an information_schema query on
     * the hot frontend path. Only the affirmative result is memoized: a `false`
     * (pre-install / mid-migration) is re-checked so a freshly migrated table is
     * picked up within the same process. Reset by {@see clearCache()}.
     */
    private ?bool $settingsTableExists = null;

    /**
     * Memoized affirmative existence of the `cms_settings_translate` table, so
     * localized reads (setting_localized()) do not run a schema introspection on
     * every call. Only `true` is cached; reset by {@see clearCache()}.
     */
    private ?bool $translationsTableExists = null;

    /**
     * Settings whose value may differ per language. Everything else is global
     * (stored only in cms_settings). Permalink bases are deliberately NOT here:
     * localized bases affect routing/sitemap/hreflang and belong to a separate
     * phase. Keep this list in sync with the SettingsPage UI.
     *
     * @var list<string>
     */
    public const LOCALIZED_KEYS = [
        'general.site_name',
        'general.site_tagline',
        'general.site_description',
        'seo.default_meta_title',
        'seo.default_meta_description',
        'maintenance.title',
        'maintenance.message',
    ];

    /**
     * Get a setting by full key (group.key or key).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->tableExists()) {
            return $default;
        }

        $autoload = $this->loadAutoload();

        if (array_key_exists($key, $autoload)) {
            return $autoload[$key];
        }

        [$group, $name] = $this->parseKey($key);

        $record = Setting::query()
            ->where('group', $group)
            ->where('key', $name)
            ->first();

        if (! $record) {
            return $default;
        }

        return $this->castValue($record->value, $record->type);
    }

    /**
     * Set a setting by full key.
     *
     * @param  array{is_public?: bool, autoload?: bool, description?: string|null}  $options
     */
    public function set(string $key, mixed $value, ?string $type = null, array $options = []): void
    {
        if (! $this->tableExists()) {
            return;
        }

        [$group, $name] = $this->parseKey($key);
        $resolvedType = $type ?? $this->detectType($value);
        $serialized = $this->serializeValue($value, $resolvedType);

        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $name],
            [
                'value' => $serialized,
                'type' => $resolvedType,
                'is_public' => $options['is_public'] ?? false,
                'autoload' => $options['autoload'] ?? true,
                'description' => $options['description'] ?? null,
            ],
        );

        $this->clearCache();
    }

    public function has(string $key): bool
    {
        if (! $this->tableExists()) {
            return false;
        }

        $autoload = $this->loadAutoload();
        if (array_key_exists($key, $autoload)) {
            return true;
        }

        [$group, $name] = $this->parseKey($key);

        return Setting::query()
            ->where('group', $group)
            ->where('key', $name)
            ->exists();
    }

    public function forget(string $key): void
    {
        if (! $this->tableExists()) {
            return;
        }

        [$group, $name] = $this->parseKey($key);

        Setting::query()
            ->where('group', $group)
            ->where('key', $name)
            ->delete();

        $this->clearCache();
    }

    /**
     * Return all settings keyed by full key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return Setting::query()
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [
                $setting->fullKey() => $this->castValue($setting->value, $setting->type),
            ])
            ->all();
    }

    /**
     * Return all settings in a single group.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return Setting::query()
            ->where('group', $group)
            ->get()
            ->mapWithKeys(fn (Setting $setting) => [
                $setting->key => $this->castValue($setting->value, $setting->type),
            ])
            ->all();
    }

    // ---------------------------------------------------------------------
    // Localized settings (v1.0.0-beta.6.2)
    // ---------------------------------------------------------------------

    /**
     * Whether a key is configured as localizable (per-language value).
     */
    public function isLocalized(string $key): bool
    {
        return in_array($key, self::LOCALIZED_KEYS, true);
    }

    /**
     * Resolve a setting for a locale with graceful fallback. Never throws.
     *
     * Lookup order:
     *   1. localized value in the requested locale
     *   2. localized value in the CMS default locale
     *   3. global cms_settings value
     *   4. provided default
     *
     * @param  string|null  $locale  Defaults to the current request locale.
     */
    public function getLocalized(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        $locale = $this->resolveLocale($locale);

        $value = $this->rawLocalized($key, $locale);
        if ($value !== null) {
            return $value;
        }

        $defaultLocale = $this->defaultLocale();
        if ($defaultLocale !== $locale) {
            $value = $this->rawLocalized($key, $defaultLocale);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->get($key, $default);
    }

    /**
     * Alias of {@see getLocalized()} (settings()->localized($key)).
     */
    public function localized(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        return $this->getLocalized($key, $locale, $default);
    }

    /**
     * The raw stored value for a (key, locale) with NO fallback — null when the
     * locale has no translation row. Used by the admin editor so each language
     * shows exactly what is saved for it.
     */
    public function rawLocalizedValue(string $key, string $locale): mixed
    {
        return $this->rawLocalized($key, $this->resolveLocale($locale));
    }

    /**
     * Store a per-locale value for a setting. Never throws when the translation
     * table is absent (it simply does nothing).
     */
    public function setLocalized(string $key, string $locale, mixed $value, ?string $type = null): void
    {
        if (! $this->translationsTableExists()) {
            return;
        }

        $locale = $this->resolveLocale($locale);
        $resolvedType = $type ?? $this->detectType($value);
        $serialized = $this->serializeValue($value, $resolvedType);

        SettingTranslation::query()->updateOrCreate(
            ['key' => $key, 'locale' => $locale],
            ['value' => $serialized, 'type' => $resolvedType],
        );

        $this->clearTranslationsCache();
    }

    /**
     * Remove a single per-locale value. Never throws.
     */
    public function forgetLocalized(string $key, string $locale): void
    {
        if (! $this->translationsTableExists()) {
            return;
        }

        SettingTranslation::query()
            ->where('key', $key)
            ->where('locale', $this->resolveLocale($locale))
            ->delete();

        $this->clearTranslationsCache();
    }

    /**
     * Copy the global cms_settings value of every localizable key into the
     * given locale's translation row, when a global value exists and no
     * translation is present yet. Global values are never removed — they remain
     * the fallback forever. Idempotent; returns the keys that were backfilled.
     *
     * @return list<string>
     */
    public function backfillLocalizedDefaults(?string $locale = null): array
    {
        if (! $this->translationsTableExists()) {
            return [];
        }

        $locale = $this->resolveLocale($locale);
        $backfilled = [];

        foreach (self::LOCALIZED_KEYS as $key) {
            if ($this->rawLocalized($key, $locale) !== null) {
                continue;
            }

            if (! $this->has($key)) {
                continue;
            }

            [$group, $name] = $this->parseKey($key);
            $record = Setting::query()->where('group', $group)->where('key', $name)->first();

            if ($record === null) {
                continue;
            }

            SettingTranslation::query()->updateOrCreate(
                ['key' => $key, 'locale' => $locale],
                ['value' => $record->value, 'type' => $record->type],
            );

            $backfilled[] = $key;
        }

        if ($backfilled !== []) {
            $this->clearTranslationsCache();
        }

        return $backfilled;
    }

    /**
     * Count-only health snapshot for /cms-health. Never exposes keys, locales,
     * paths, or values. Never throws.
     *
     * @return array{ready: bool, keys_count: int, locales_count: int}
     */
    public function localizedHealth(): array
    {
        if (! $this->translationsTableExists()) {
            return ['ready' => false, 'keys_count' => 0, 'locales_count' => 0];
        }

        try {
            return [
                'ready' => true,
                'keys_count' => SettingTranslation::query()->distinct()->count('key'),
                'locales_count' => SettingTranslation::query()->distinct()->count('locale'),
            ];
        } catch (Throwable) {
            return ['ready' => false, 'keys_count' => 0, 'locales_count' => 0];
        }
    }

    public function clearCache(): void
    {
        Cache::forget($this->cacheKey());
        $this->clearTranslationsCache();
        $this->settingsTableExists = null;
        $this->translationsTableExists = null;
    }

    /**
     * Force-reload autoload cache and return the cached map.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->clearCache();

        return $this->loadAutoload();
    }

    public function isCached(): bool
    {
        return Cache::has($this->cacheKey());
    }

    /**
     * Parse a dot key into [group, key]. If no dot, group is null.
     *
     * @return array{0: string|null, 1: string}
     */
    protected function parseKey(string $key): array
    {
        if (! str_contains($key, '.')) {
            return [null, $key];
        }

        [$group, $name] = explode('.', $key, 2);

        return [$group, $name];
    }

    protected function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'array',
            $value instanceof \JsonSerializable, is_object($value) => 'json',
            default => 'string',
        };
    }

    protected function serializeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) (int) $value,
            'float' => (string) (float) $value,
            'array', 'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            default => (string) $value,
        };
    }

    protected function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === '1' || strtolower($value) === 'true',
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'json' => json_decode($value, true),
            default => $value,
        };
    }

    protected function cacheKey(): string
    {
        return self::CACHE_KEY;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadAutoload(): array
    {
        return Cache::rememberForever($this->cacheKey(), function (): array {
            return Setting::query()
                ->where('autoload', true)
                ->get()
                ->mapWithKeys(fn (Setting $setting) => [
                    $setting->fullKey() => $this->castValue($setting->value, $setting->type),
                ])
                ->all();
        });
    }

    /**
     * Whether the cms_settings table is available. Memoizes the affirmative
     * result so the hot read path (get()/has()) does not perform schema
     * introspection on every call — see {@see $settingsTableExists}.
     */
    private function tableExists(): bool
    {
        if ($this->settingsTableExists === true) {
            return true;
        }

        $exists = $this->resolveTableExists();

        if ($exists) {
            $this->settingsTableExists = true;
        }

        return $exists;
    }

    /**
     * Determine cms_settings availability without memoization. After install we
     * trust the install lock (a filesystem/env check, no DB round-trip) and skip
     * the information_schema query entirely; before install we fall back to a
     * guarded Schema::hasTable so seeding/migrations still work. Never throws.
     */
    private function resolveTableExists(): bool
    {
        try {
            $installer = app('cms.installer');

            // Trust the cheap install lock ONLY when the database config is
            // actually usable. An installed marker/env flag sitting next to an
            // incomplete or empty DB config (e.g. DB_CONNECTION=mysql with no
            // DB_DATABASE, or a missing SQLite file) must not send the hot path
            // into a query that throws — fall through to the guarded check,
            // which returns false and lets the CMS serve safe defaults.
            if ($installer->isInstalled() && $installer->hasUsableDatabaseConfig()) {
                return true;
            }
        } catch (Throwable) {
            // Installer unavailable (early boot / tests) — fall through to the
            // schema check below.
        }

        try {
            return Schema::hasTable('cms_settings');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the settings hot path avoids per-call schema introspection. A
     * static readiness flag for /cms-health (v1.0.0-beta.6.3).
     */
    public function hotPathOptimized(): bool
    {
        return true;
    }

    /**
     * The raw, casted translation value for a (key, locale), or null when no
     * translation row exists. No fallback — callers compose the lookup order.
     */
    private function rawLocalized(string $key, string $locale): mixed
    {
        if (! $this->translationsTableExists()) {
            return null;
        }

        $map = $this->loadTranslations();
        $row = $map[$locale][$key] ?? null;

        if ($row === null) {
            return null;
        }

        return $this->castValue($row['value'], $row['type']);
    }

    /**
     * @return array<string, array<string, array{value: string|null, type: string}>>
     */
    private function loadTranslations(): array
    {
        return Cache::rememberForever($this->translationsCacheKey(), function (): array {
            $map = [];

            foreach (SettingTranslation::query()->get() as $row) {
                $map[$row->locale][$row->key] = [
                    'value' => $row->value,
                    'type' => $row->type,
                ];
            }

            return $map;
        });
    }

    private function clearTranslationsCache(): void
    {
        Cache::forget($this->translationsCacheKey());
    }

    private function translationsCacheKey(): string
    {
        return self::TRANSLATIONS_CACHE_KEY;
    }

    /**
     * Normalize a locale, defaulting to the current request locale. Guarded so
     * it never throws before the language layer/DB is available.
     */
    private function resolveLocale(?string $locale): string
    {
        try {
            $language = app('cms.language');

            if (is_string($locale) && trim($locale) !== '') {
                return $language->normalizeCode($locale);
            }

            return $language->currentCode();
        } catch (Throwable) {
            return is_string($locale) && trim($locale) !== '' ? trim($locale) : 'en';
        }
    }

    private function defaultLocale(): string
    {
        try {
            return app('cms.language')->defaultCode();
        } catch (Throwable) {
            return 'en';
        }
    }

    private function translationsTableExists(): bool
    {
        if ($this->translationsTableExists === true) {
            return true;
        }

        $exists = false;

        try {
            $exists = Schema::hasTable('cms_settings_translate');
        } catch (Throwable) {
            $exists = false;
        }

        if ($exists) {
            $this->translationsTableExists = true;
        }

        return $exists;
    }
}
