<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Widget as WidgetModel;
use TheNguyen\CMS\Models\WidgetArea;
use TheNguyen\CMS\Models\WidgetTranslation;
use TheNguyen\CMS\Widgets\Widget;

/**
 * Widget registry + renderer (Widget Foundation, v1.0.0-beta.7).
 *
 * Holds the in-memory catalogue of widget *types* (classes registered by core,
 * themes and plugins) and widget *areas* (named slots), mirrors the areas into
 * cms_widget_areas, and renders an area's assigned widget instances to HTML.
 *
 * Safety is the contract: an invalid widget class is skipped (reported), a
 * duplicate type is last-wins (reported), and any failure while rendering a
 * single widget is caught and reported so the frontend never 500s — at most a
 * debug-only HTML comment is emitted in its place.
 */
class WidgetManager
{
    /**
     * Registered widget classes, keyed by type().
     *
     * @var array<string, class-string<Widget>>
     */
    private array $widgets = [];

    /**
     * Registered area definitions, keyed by slug.
     *
     * @var array<string, array{slug: string, name: string, description: ?string, source: ?string, source_slug: ?string, is_active: bool, sort_order: int}>
     */
    private array $areas = [];

    /**
     * Registered presets, keyed by slug.
     *
     * @var array<string, array{slug: string, name: string, widgets: array<int, array<string, mixed>>}>
     */
    private array $presets = [];

    // ---------------------------------------------------------------------
    // Widget type registry
    // ---------------------------------------------------------------------

    /**
     * Register a widget class. Invalid classes are skipped (reported); a
     * duplicate type replaces the earlier one (last wins, reported).
     *
     * @param  class-string<Widget>|string  $class
     */
    public function register(string $class): void
    {
        if (! class_exists($class) || ! is_subclass_of($class, Widget::class)) {
            report(new \InvalidArgumentException('TN CMS: invalid widget class skipped: '.$class));

            return;
        }

        $type = $class::type();

        if ($type === '') {
            report(new \InvalidArgumentException('TN CMS: widget class has an empty type(): '.$class));

            return;
        }

        if (isset($this->widgets[$type]) && $this->widgets[$type] !== $class) {
            report(new \RuntimeException(
                'TN CMS: duplicate widget type "'.$type.'" — '.$class.' overrides '.$this->widgets[$type].'.'
            ));
        }

        $this->widgets[$type] = $class;
    }

    /**
     * All registered widget classes, keyed by type.
     *
     * @return array<string, class-string<Widget>>
     */
    public function registered(): array
    {
        return $this->widgets;
    }

    /**
     * The registered class for a widget type, or null.
     *
     * @return class-string<Widget>|null
     */
    public function find(string $type): ?string
    {
        return $this->widgets[$type] ?? null;
    }

    /**
     * Picker metadata for every registered widget type, ordered by name.
     *
     * @return array<int, array{type: string, name: string, description: string, icon: ?string}>
     */
    public function available(): array
    {
        $meta = [];

        foreach ($this->widgets as $type => $class) {
            $meta[] = [
                'type' => $type,
                'name' => $class::name(),
                'description' => $class::description(),
                'icon' => $class::icon(),
                'group' => $class::group(),
            ];
        }

        usort($meta, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $meta;
    }

    /**
     * The available widget metadata bucketed by group, for the admin picker.
     * Group order follows first-seen registration; widgets within a group are
     * ordered by name.
     *
     * @return array<string, array<int, array{type: string, name: string, description: string, icon: ?string, group: string}>>
     */
    public function groupedAvailable(): array
    {
        $groups = [];

        foreach ($this->available() as $widget) {
            $groups[$widget['group']][] = $widget;
        }

        return $groups;
    }

    /**
     * The (schema) field definitions for a widget type, or [] when unknown.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schemaFor(string $type): array
    {
        $class = $this->find($type);

        return $class !== null ? $class::normalizedSchema() : [];
    }

    // ---------------------------------------------------------------------
    // Area registry
    // ---------------------------------------------------------------------

    /**
     * Register (or overwrite) a widget area definition.
     *
     * @param  array{description?: ?string, source?: ?string, source_slug?: ?string, is_active?: bool, sort_order?: int}  $options
     */
    public function registerArea(string $slug, string $name, array $options = []): void
    {
        if ($slug === '') {
            return;
        }

        $this->areas[$slug] = [
            'slug' => $slug,
            'name' => $name,
            'description' => $options['description'] ?? null,
            'source' => $options['source'] ?? 'core',
            'source_slug' => $options['source_slug'] ?? null,
            'is_active' => $options['is_active'] ?? true,
            'sort_order' => $options['sort_order'] ?? count($this->areas),
        ];
    }

    /**
     * All registered area definitions, ordered by sort_order.
     *
     * @return array<int, array{slug: string, name: string, description: ?string, source: ?string, source_slug: ?string, is_active: bool, sort_order: int}>
     */
    public function areas(): array
    {
        $areas = array_values($this->areas);

        usort($areas, static fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return $areas;
    }

    /**
     * Mirror the registered area definitions into cms_widget_areas. Idempotent
     * (updateOrCreate by slug); never overwrites the admin's is_active toggle on
     * an existing row, and never deletes unknown rows. Guarded — never throws.
     */
    public function syncAreas(): void
    {
        try {
            if (! Schema::hasTable('cms_widget_areas')) {
                return;
            }

            foreach ($this->areas as $area) {
                WidgetArea::query()->updateOrCreate(
                    ['slug' => $area['slug']],
                    [
                        'name' => $area['name'],
                        'description' => $area['description'],
                        'source' => $area['source'],
                        'source_slug' => $area['source_slug'],
                        'sort_order' => $area['sort_order'],
                    ],
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ---------------------------------------------------------------------
    // Presets (v1.0.0-beta.7.1)
    // ---------------------------------------------------------------------

    /**
     * Register a reusable preset: a named bundle of widget definitions that an
     * admin can stamp into an area in one click. Each widget definition is
     * `['type' => string, 'title' => ?string, 'settings' => array, 'translations' => array]`
     * (only `type` is required).
     *
     * @param  array{name?: string, widgets?: array<int, array<string, mixed>>}  $definition
     */
    public function registerPreset(string $slug, array $definition): void
    {
        if ($slug === '') {
            return;
        }

        $this->presets[$slug] = [
            'slug' => $slug,
            'name' => is_string($definition['name'] ?? null) ? $definition['name'] : $slug,
            'widgets' => array_values(array_filter(
                (array) ($definition['widgets'] ?? []),
                static fn ($w): bool => is_array($w) && isset($w['type']) && is_string($w['type']),
            )),
        ];
    }

    /**
     * All registered presets.
     *
     * @return array<int, array{slug: string, name: string, widgets: array<int, array<string, mixed>>}>
     */
    public function presets(): array
    {
        return array_values($this->presets);
    }

    /**
     * @return array{slug: string, name: string, widgets: array<int, array<string, mixed>>}|null
     */
    public function findPreset(string $slug): ?array
    {
        return $this->presets[$slug] ?? null;
    }

    /**
     * Stamp a preset's widgets into an area, appending after any existing
     * widgets. Never overwrites or removes existing widgets. Returns the number
     * of widget instances created.
     */
    public function applyPreset(string $slug, int $areaId): int
    {
        $preset = $this->findPreset($slug);

        if ($preset === null) {
            return 0;
        }

        return $this->createWidgets($areaId, $preset['widgets']);
    }

    // ---------------------------------------------------------------------
    // Export / Import (v1.0.0-beta.7.1)
    // ---------------------------------------------------------------------

    /**
     * Export widget areas, their widget instances, settings, and per-locale
     * translations to a portable array (JSON-encodable). Optionally limit to a
     * set of area slugs. Never throws.
     *
     * @param  array<int, string>|null  $areaSlugs
     * @return array{version: string, areas: array<string, array<int, array<string, mixed>>>}
     */
    public function export(?array $areaSlugs = null): array
    {
        $payload = ['version' => '1.0.0-beta.7.1', 'areas' => []];

        try {
            if (! Schema::hasTable('cms_widget_areas') || ! Schema::hasTable('cms_widgets')) {
                return $payload;
            }

            $query = WidgetArea::query()->with(['widgets' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'widgets.translations']);

            if ($areaSlugs !== null) {
                $query->whereIn('slug', $areaSlugs);
            }

            foreach ($query->get() as $area) {
                $widgets = [];

                foreach ($area->widgets as $widget) {
                    $translations = [];

                    foreach ($widget->translations as $t) {
                        $translations[$t->locale] = [
                            'title' => $t->title,
                            'settings' => is_array($t->settings) ? $t->settings : [],
                        ];
                    }

                    $widgets[] = [
                        'type' => $widget->widget_type,
                        'title' => $widget->title,
                        'settings' => is_array($widget->settings) ? $widget->settings : [],
                        'is_active' => (bool) $widget->is_active,
                        'translations' => $translations,
                    ];
                }

                $payload['areas'][$area->slug] = $widgets;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $payload;
    }

    /**
     * Import widgets from an export payload. An area is matched by slug (unknown
     * areas are created). Mode 'merge'/'append' (default) appends widgets and
     * never removes existing ones; mode 'replace' first clears the target area's
     * widgets, then imports. Returns the number of widget instances created.
     * Never throws.
     *
     * @param  array<string, mixed>  $data
     */
    public function import(array $data, string $mode = 'merge'): int
    {
        $created = 0;

        try {
            if (! Schema::hasTable('cms_widget_areas') || ! Schema::hasTable('cms_widgets')) {
                return 0;
            }

            $areas = is_array($data['areas'] ?? null) ? $data['areas'] : [];
            $replace = $mode === 'replace';

            foreach ($areas as $slug => $widgets) {
                if (! is_string($slug) || ! is_array($widgets)) {
                    continue;
                }

                $area = WidgetArea::query()->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => ucwords(str_replace(['-', '_'], ' ', $slug)), 'source' => 'core', 'is_active' => true],
                );

                // 'replace' clears the target area's existing widgets first;
                // 'merge'/'append' (default) leaves them untouched.
                if ($replace) {
                    WidgetModel::query()->where('area_id', $area->id)->get()->each->delete();
                }

                $created += $this->createWidgets($area->id, $widgets);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $created;
    }

    /**
     * Create widget instances (with optional translations) inside an area,
     * appending after the current max sort_order. Unknown/garbage rows and
     * unregistered types are skipped. Shared by presets + import.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     */
    private function createWidgets(int $areaId, array $widgets): int
    {
        $created = 0;
        $order = (int) WidgetModel::query()->where('area_id', $areaId)->max('sort_order');

        foreach ($widgets as $definition) {
            if (! is_array($definition) || ! isset($definition['type']) || ! is_string($definition['type'])) {
                continue;
            }

            // Skip types no widget class is registered for, so imports can't
            // create permanently-broken instances.
            if ($this->find($definition['type']) === null) {
                continue;
            }

            $widget = WidgetModel::query()->create([
                'area_id' => $areaId,
                'widget_type' => $definition['type'],
                'title' => is_string($definition['title'] ?? null) ? $definition['title'] : null,
                'settings' => is_array($definition['settings'] ?? null) ? $definition['settings'] : [],
                'is_active' => (bool) ($definition['is_active'] ?? true),
                'sort_order' => ++$order,
            ]);

            foreach ((array) ($definition['translations'] ?? []) as $locale => $translation) {
                if (! is_string($locale) || ! is_array($translation)) {
                    continue;
                }

                WidgetTranslation::query()->create([
                    'widget_id' => $widget->id,
                    'locale' => $locale,
                    'title' => is_string($translation['title'] ?? null) ? $translation['title'] : null,
                    'settings' => is_array($translation['settings'] ?? null) ? $translation['settings'] : [],
                ]);
            }

            $created++;
        }

        return $created;
    }

    /**
     * Duplicate a widget instance in place: a copy in the same area with the
     * same global settings and every per-locale translation, placed immediately
     * after the original. The copy's parent title gains a " (copy)" suffix.
     * Returns the new widget, or null when the source is missing. Never throws.
     */
    public function duplicate(int $widgetId): ?WidgetModel
    {
        try {
            $source = WidgetModel::query()->with('translations')->find($widgetId);

            if ($source === null) {
                return null;
            }

            // Make room directly after the source, preserving relative order.
            WidgetModel::query()
                ->where('area_id', $source->area_id)
                ->where('sort_order', '>', $source->sort_order)
                ->increment('sort_order');

            $copy = WidgetModel::query()->create([
                'area_id' => $source->area_id,
                'widget_type' => $source->widget_type,
                'title' => $source->title !== null ? $source->title.' (copy)' : null,
                'settings' => is_array($source->settings) ? $source->settings : [],
                'is_active' => $source->is_active,
                'sort_order' => $source->sort_order + 1,
            ]);

            foreach ($source->translations as $translation) {
                WidgetTranslation::query()->create([
                    'widget_id' => $copy->id,
                    'locale' => $translation->locale,
                    'title' => $translation->title,
                    'settings' => is_array($translation->settings) ? $translation->settings : [],
                ]);
            }

            return $copy;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * Render every active widget assigned to an area, in order, for the locale.
     * Returns '' for an empty/unknown/inactive area. Never throws.
     */
    public function renderArea(string $slug, ?string $locale = null): string
    {
        try {
            if (! Schema::hasTable('cms_widget_areas') || ! Schema::hasTable('cms_widgets')) {
                return '';
            }

            $area = WidgetArea::query()->where('slug', $slug)->where('is_active', true)->first();

            if ($area === null) {
                return '';
            }

            $locale ??= $this->currentLocale();

            $widgets = $area->widgets()
                ->where('is_active', true)
                ->with('translations')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            $out = '';

            foreach ($widgets as $widget) {
                $out .= $this->renderWidget($widget, $locale);
            }

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Render a single widget instance for the locale. Catches and reports any
     * failure, returning a debug-only comment (or '') instead of crashing.
     */
    public function renderWidget(WidgetModel $widget, ?string $locale = null): string
    {
        $type = $widget->widget_type;

        try {
            $class = $this->find($type);

            if ($class === null) {
                // Unknown/unregistered type (e.g. a plugin was removed).
                return $this->failureComment($type);
            }

            $locale ??= $this->currentLocale();
            $settings = $this->resolveSettings($widget, $class, $locale);

            $instance = new $class;
            $output = $instance->render($settings, $locale);

            if ($output instanceof View) {
                $output = $output->render();
            }

            return (string) $output;
        } catch (\Throwable $e) {
            report($e);

            return $this->failureComment($type);
        }
    }

    /**
     * Resolve the effective settings for a widget instance + locale.
     *
     * Overlay order (later wins): schema defaults → parent global settings →
     * the resolved translation's localized settings. The translation is the
     * requested locale's row, then the CMS default locale's row — so a present
     * translation is never bypassed and one locale never leaks into another.
     * 'title' follows the same fallback chain (translation → parent → default).
     *
     * @param  class-string<Widget>  $class
     * @return array<string, mixed>
     */
    private function resolveSettings(WidgetModel $widget, string $class, string $locale): array
    {
        $defaults = $class::defaults();
        $global = is_array($widget->settings) ? $widget->settings : [];

        $defaultLocale = $this->defaultLocale();

        $translation = $widget->translationFor($locale)
            ?? ($defaultLocale !== $locale ? $widget->translationFor($defaultLocale) : null);

        $localized = ($translation !== null && is_array($translation->settings)) ? $translation->settings : [];

        $merged = array_merge($defaults, $global, $localized);

        $merged['title'] = $translation?->title
            ?? $widget->title
            ?? ($defaults['title'] ?? null);

        return $merged;
    }

    /**
     * A render-failure placeholder: a comment in debug, nothing in production.
     */
    private function failureComment(string $type): string
    {
        return config('app.debug') === true
            ? '<!-- Widget render failed: '.e($type).' -->'
            : '';
    }

    private function currentLocale(): string
    {
        try {
            return app('cms.language')->currentCode();
        } catch (\Throwable) {
            return (string) config('app.locale', 'en');
        }
    }

    private function defaultLocale(): string
    {
        try {
            return app('cms.language')->defaultCode();
        } catch (\Throwable) {
            return (string) config('app.locale', 'en');
        }
    }
}
