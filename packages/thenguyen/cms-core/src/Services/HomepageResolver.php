<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\View\ResolvedLayout;

/**
 * Resolves the homepage section layout for the active theme
 * (theme-architecture `18` §5, `20` Rules 4, 12, 13).
 *
 * Precedence:
 *   1. the live/edited layout in cms_settings `theme.{slug}.homepage_layout`
 *      (a pagebuilder/06 document);
 *   2. otherwise the active preset's blueprint layout, where the preset id is
 *      cms_settings `theme.{slug}.homepage_preset`;
 *   3. otherwise null — the caller falls back to its legacy homepage behavior.
 *
 * The active preset id comes only from settings — never hardcoded — so the
 * resolver is theme/preset agnostic. All fallbacks are safe (invalid JSON or a
 * missing preset file → treated as absent), so the homepage never 500s.
 */
class HomepageResolver
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly PresetRepository $presets,
        private readonly SectionResolver $sections,
        private readonly SettingsManager $settings,
    ) {}

    /**
     * Resolve the homepage sections, or null when no sections layout applies
     * (so the frontend keeps its existing homepage behavior).
     */
    public function resolve(?string $locale = null): ?ResolvedLayout
    {
        $slug = $this->themes->active()?->slug;

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $document = $this->storedLayout($slug) ?? $this->presetLayout($slug);

        if ($document === null) {
            return null;
        }

        return $this->sections->resolve($document, $locale);
    }

    /**
     * The stored/edited homepage layout as a valid pagebuilder/06 document, or
     * null when unset/empty/invalid. A stored value may be an array (settings
     * type `array`) or a JSON string; either way it must decode to an array with
     * a `sections` list, otherwise it is treated as absent so the caller falls
     * back to the preset blueprint (theme-implementation 04 §4.5).
     *
     * @return array<string, mixed>|null
     */
    private function storedLayout(string $slug): ?array
    {
        $value = $this->settings->get($this->key($slug, 'homepage_layout'));

        if (is_string($value)) {
            if (trim($value) === '') {
                return null;
            }

            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($value) || ! isset($value['sections']) || ! is_array($value['sections'])) {
            return null;
        }

        return $value;
    }

    /**
     * The active preset's blueprint layout, or null when no preset is set or
     * the preset file is missing/invalid.
     *
     * @return array<string, mixed>|null
     */
    private function presetLayout(string $slug): ?array
    {
        $preset = $this->settings->get($this->key($slug, 'homepage_preset'));

        if (! is_string($preset) || $preset === '') {
            return null;
        }

        return $this->presets->layout($preset, $slug);
    }

    private function key(string $slug, string $name): string
    {
        return 'theme.'.$slug.'.'.$name;
    }
}
