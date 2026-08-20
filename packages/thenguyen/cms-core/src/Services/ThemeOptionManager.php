<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * Theme Options storage layer (v0.9.9).
 *
 * Reads the option *schema* from the active theme (via {@see ThemeManager}) and
 * persists option *values* in the existing cms_settings table — no new table.
 *
 * Storage format: option values are stored under the full setting key
 * `theme_options.{theme_slug}.{option_key}`. The SettingsManager splits a key on
 * its first dot only, so this lands as group `theme_options`, key
 * `{slug}.{option_key}` — globally unique per (group, key). Reads fall back to
 * the schema `default` when no value is stored, then to the caller's default.
 */
class ThemeOptionManager
{
    private const GROUP = 'theme_options';

    public function __construct(private readonly ThemeManager $themes)
    {
    }

    /**
     * Resolve an option value: stored value → schema default → caller default.
     */
    public function get(string $key, mixed $default = null, ?string $theme = null): mixed
    {
        $slug = $this->resolveSlug($theme);

        if ($slug === null) {
            return $default;
        }

        $fullKey = $this->settingKey($slug, $key);

        if (settings()->has($fullKey)) {
            return settings()->get($fullKey);
        }

        $schemaDefault = $this->schemaDefault($slug, $key);

        return $schemaDefault ?? $default;
    }

    /**
     * Persist an option value for a theme (defaults to the active theme).
     */
    public function set(string $key, mixed $value, ?string $theme = null): void
    {
        $slug = $this->resolveSlug($theme);

        if ($slug === null) {
            return;
        }

        settings()->set($this->settingKey($slug, $key), $value, null, [
            'is_public' => true,
            'autoload' => true,
            'description' => 'Theme option',
        ]);
        // SettingsManager::set() already clears the autoload cache.

        // The homepage_preset option mirrors to the authoritative core setting the
        // HomepageResolver reads (theme-architecture 18 §4, Rule 13). Other options
        // never leak into the theme.* namespace.
        if ($key === 'homepage_preset') {
            $this->syncHomepagePreset($slug, $value);
        }
    }

    /**
     * Keep `theme.{slug}.homepage_preset` (the authoritative value HomepageResolver
     * reads) in step with the homepage_preset theme option. An empty selection
     * forgets the authoritative setting so the resolver falls back cleanly.
     */
    private function syncHomepagePreset(string $slug, mixed $value): void
    {
        $presetKey = 'theme.' . $slug . '.homepage_preset';

        if (is_string($value) && $value !== '') {
            settings()->set($presetKey, $value, 'string', [
                'is_public' => true,
                'autoload' => true,
                'description' => 'Active homepage preset',
            ]);

            return;
        }

        settings()->forget($presetKey);
    }

    /**
     * All resolved option values for a theme: every schema field key mapped to
     * its stored value (or schema default when unset).
     *
     * @return array<string, mixed>
     */
    public function all(?string $theme = null): array
    {
        $slug = $this->resolveSlug($theme);

        if ($slug === null) {
            return [];
        }

        $values = [];

        // Seed with schema defaults so every declared option is present.
        foreach ($this->schema($slug)['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $values[$field['key']] = $field['default'] ?? null;
            }
        }

        // Overlay stored values for this theme (group `theme_options`, keys
        // prefixed `{slug}.`). Only keys still declared in the schema are kept,
        // so stale values from removed/renamed fields are not leaked.
        $prefix = $slug . '.';

        foreach (settings()->group(self::GROUP) as $storedKey => $storedValue) {
            if (! str_starts_with($storedKey, $prefix)) {
                continue;
            }

            $optionKey = substr($storedKey, strlen($prefix));

            if (array_key_exists($optionKey, $values)) {
                $values[$optionKey] = $storedValue;
            }
        }

        return $values;
    }

    /**
     * The validated option schema for a theme (defaults to the active theme).
     *
     * @return array{sections: array<int, array<string, mixed>>}
     */
    public function schema(?string $theme = null): array
    {
        return $this->themes->themeOptionsSchema($theme);
    }

    /**
     * The active theme has at least one option section (used by the admin page
     * and /cms-health).
     */
    public function hasOptions(?string $theme = null): bool
    {
        return $this->themes->hasThemeOptions($theme);
    }

    private function settingKey(string $slug, string $key): string
    {
        return self::GROUP . '.' . $slug . '.' . $key;
    }

    private function resolveSlug(?string $theme): ?string
    {
        if (is_string($theme) && $theme !== '') {
            return $theme;
        }

        return $this->themes->active()?->slug;
    }

    /**
     * Look up a field's declared default in the theme schema, or null.
     */
    private function schemaDefault(string $slug, string $key): mixed
    {
        foreach ($this->schema($slug)['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['key'] ?? null) === $key) {
                    return $field['default'] ?? null;
                }
            }
        }

        return null;
    }
}
