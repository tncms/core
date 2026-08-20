<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheNguyen\CMS\Models\Widget;
use TheNguyen\CMS\Models\WidgetArea;
use TheNguyen\CMS\Services\WidgetManager;

/**
 * Appearance → Widgets (WordPress-like UI, v1.0.0-beta.7.1).
 *
 * This page lives in cms-core so the widget admin travels with the package —
 * the host app needs no `app/Filament/.../WidgetsPage.php`. It is registered on
 * the admin panel by {@see \TheNguyen\CMS\Filament\PluginResourceRegistrar} and
 * renders the `cms::filament.pages.widgets-page` view.
 *
 * Layout: a two-column board — searchable, grouped "Available Widgets" on the
 * left; collapsible, drag-sortable "Widget Areas" on the right. Editing is
 * inline (no modals); reordering and moving widgets between areas is drag & drop
 * persisted through {@see reorderWidgets()} / {@see moveWidget()}. Presets and
 * JSON export/import round it out.
 */
class WidgetsPage extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'widgets';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 40;

    protected string $view = 'cms::filament.pages.widgets-page';

    /** The locale whose title/localized settings are being edited. */
    public string $editLocale = '';

    /** The id of the widget instance open in the inline editor, or null. */
    public ?int $editingId = null;

    /** Editor: the (localized) title for the current edit locale. */
    public string $editTitle = '';

    /** @var array<string, mixed> */
    public array $editFields = [];

    /** Free-text filter for the Available Widgets list. */
    public string $widgetSearch = '';

    /** The area new widgets / presets are added to (shared "Add to" target). */
    public ?int $targetAreaId = null;

    /** Pasted JSON for import. */
    public string $importJson = '';

    /** Optional uploaded .json file for import. */
    public ?TemporaryUploadedFile $importFile = null;

    /** Import mode: 'append' (merge) or 'replace'. */
    public string $importMode = 'append';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Widgets');
    }

    public function getTitle(): string
    {
        return tn_trans('Widgets');
    }

    public static function canAccess(): bool
    {
        return cms_can('widgets.manage');
    }

    public function mount(): void
    {
        $this->widgets()->syncAreas();
        // Open the widget editor in the admin's content EDITING language
        // (v1.0.0-beta.7.1.10.2) — widget titles/text are localized content, so
        // they follow editing_locale(), not the admin UI language. The editLocale
        // switcher can still change it. The global-sync check below intentionally
        // keeps comparing against default_locale().
        $this->editLocale = editing_locale();
        $this->targetAreaId = WidgetArea::query()->where('is_active', true)->orderBy('sort_order')->value('id');
    }

    // ---------------------------------------------------------------------
    // Read model for the view
    // ---------------------------------------------------------------------

    /**
     * @return \Illuminate\Support\Collection<int, WidgetArea>
     */
    public function getAreasProperty()
    {
        return WidgetArea::query()
            ->where('is_active', true)
            ->with(['widgets' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Registered widgets bucketed by group, filtered by the search box.
     *
     * @return array<string, array<int, array{type: string, name: string, description: string, icon: ?string, group: string}>>
     */
    public function getGroupedWidgetsProperty(): array
    {
        $search = Str::lower(trim($this->widgetSearch));
        $groups = $this->widgets()->groupedAvailable();

        if ($search === '') {
            return $groups;
        }

        $filtered = [];

        foreach ($groups as $group => $widgets) {
            $matches = array_values(array_filter(
                $widgets,
                static fn (array $w): bool => str_contains(Str::lower($w['name']), $search)
                    || str_contains(Str::lower($w['description']), $search)
                    || str_contains(Str::lower($w['type']), $search),
            ));

            if ($matches !== []) {
                $filtered[$group] = $matches;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, string>
     */
    public function getLocalesProperty(): array
    {
        $languages = language()->active();

        if ($languages->isEmpty()) {
            return [default_locale() => strtoupper(default_locale())];
        }

        return $languages->mapWithKeys(static fn ($l) => [$l->code => $l->displayName()])->all();
    }

    /**
     * @return array<int, array{slug: string, name: string, widgets: array<int, array<string, mixed>>}>
     */
    public function getPresetsProperty(): array
    {
        return $this->widgets()->presets();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEditSchemaProperty(): array
    {
        $widget = $this->editingWidget();

        return $widget !== null ? $this->widgets()->schemaFor($widget->widget_type) : [];
    }

    public function widgetTypeName(string $type): string
    {
        $class = $this->widgets()->find($type);

        return $class !== null ? $class::name() : $type;
    }

    // ---------------------------------------------------------------------
    // Widget CRUD + inline editor
    // ---------------------------------------------------------------------

    public function addWidget(int $areaId, string $type): void
    {
        if ($this->widgets()->find($type) === null || WidgetArea::query()->whereKey($areaId)->doesntExist()) {
            return;
        }

        $nextOrder = (int) Widget::query()->where('area_id', $areaId)->max('sort_order') + 1;

        $widget = Widget::query()->create([
            'area_id' => $areaId,
            'widget_type' => $type,
            'sort_order' => $nextOrder,
            'is_active' => true,
            'settings' => [],
        ]);

        $this->openEditor($widget->id);
    }

    public function openEditor(int $widgetId): void
    {
        $widget = Widget::query()->with('translations')->find($widgetId);

        if ($widget === null) {
            return;
        }

        $this->editingId = $widgetId;
        $this->loadEditorState($widget);
    }

    public function setEditLocale(string $locale): void
    {
        $this->editLocale = $locale;

        $widget = $this->editingWidget();

        if ($widget === null) {
            return;
        }

        // Reload ONLY the localized title + localized field values for the newly
        // selected locale. Global (non-localized) fields stay in $editFields as
        // edited, so switching language never discards unsaved global changes.
        $translation = $widget->translations->firstWhere('locale', $this->editLocale);
        $localized = ($translation !== null && is_array($translation->settings)) ? $translation->settings : [];

        foreach ($this->widgets()->schemaFor($widget->widget_type) as $field) {
            if (($field['localized'] ?? false) !== true) {
                continue;
            }

            $key = $field['key'];
            $this->editFields[$key] = $localized[$key] ?? $field['default'] ?? null;
        }

        $this->editTitle = (string) ($translation?->title ?? $widget->title ?? '');
    }

    public function save(): void
    {
        $widget = $this->editingWidget();

        if ($widget === null) {
            return;
        }

        $schema = $this->widgets()->schemaFor($widget->widget_type);

        $global = is_array($widget->settings) ? $widget->settings : [];
        $localized = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key)) {
                continue;
            }

            $value = $this->normalizeFieldValue($field, $this->editFields[$key] ?? null);

            if (($field['localized'] ?? false) === true) {
                $localized[$key] = $value;
            } else {
                $global[$key] = $value;
            }
        }

        $widget->settings = $global;

        if ($this->editLocale === default_locale()) {
            $widget->title = $this->editTitle !== '' ? $this->editTitle : null;
        }

        $widget->save();

        $widget->translations()->updateOrCreate(
            ['locale' => $this->editLocale],
            [
                'title' => $this->editTitle !== '' ? $this->editTitle : null,
                'settings' => $localized,
            ],
        );

        Notification::make()->title(tn_trans('Widget saved'))->success()->send();
    }

    public function deleteWidget(int $id): void
    {
        $widget = Widget::query()->find($id);

        if ($widget !== null) {
            $widget->delete();
        }

        if ($this->editingId === $id) {
            $this->closeEditor();
        }

        Notification::make()->title(tn_trans('Widget deleted'))->success()->send();
    }

    public function duplicateWidget(int $id): void
    {
        $copy = $this->widgets()->duplicate($id);

        Notification::make()
            ->title($copy !== null ? tn_trans('Widget duplicated') : tn_trans('Could not duplicate widget'))
            ->{$copy !== null ? 'success' : 'danger'}()
            ->send();
    }

    public function toggleWidget(int $id): void
    {
        $widget = Widget::query()->find($id);

        if ($widget !== null) {
            $widget->is_active = ! $widget->is_active;
            $widget->save();
        }
    }

    public function closeEditor(): void
    {
        $this->editingId = null;
        $this->editTitle = '';
        $this->editFields = [];
    }

    // ---------------------------------------------------------------------
    // Drag & drop (called from the Alpine sortable in the view)
    // ---------------------------------------------------------------------

    /**
     * Persist a new within-area order. $orderedIds is the area's widget ids in
     * their new visual order.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderWidgets(int $areaId, array $orderedIds): void
    {
        $this->applyOrder($areaId, $orderedIds);
    }

    /**
     * Move a widget into another area and persist the target area's new order.
     * Handles same-area drops too (the target area is just the current one).
     *
     * @param  array<int, int|string>  $orderedIds  the target area's ids in order
     */
    public function moveWidget(int $widgetId, int $toAreaId, array $orderedIds): void
    {
        $widget = Widget::query()->find($widgetId);

        if ($widget === null || WidgetArea::query()->whereKey($toAreaId)->doesntExist()) {
            return;
        }

        if ($widget->area_id !== $toAreaId) {
            $widget->area_id = $toAreaId;
            $widget->save();
        }

        $this->applyOrder($toAreaId, $orderedIds);
    }

    /**
     * @param  array<int, int|string>  $orderedIds
     */
    private function applyOrder(int $areaId, array $orderedIds): void
    {
        $position = 0;

        foreach ($orderedIds as $id) {
            Widget::query()
                ->where('id', (int) $id)
                ->where('area_id', $areaId)
                ->update(['sort_order' => $position++]);
        }
    }

    // ---------------------------------------------------------------------
    // Presets + Export / Import
    // ---------------------------------------------------------------------

    public function applyPreset(string $slug): void
    {
        if ($this->targetAreaId === null) {
            Notification::make()->title(tn_trans('Choose an area first'))->warning()->send();

            return;
        }

        $count = $this->widgets()->applyPreset($slug, $this->targetAreaId);

        Notification::make()
            ->title(tn_trans(':count widget(s) added from preset', ['count' => $count]))
            ->success()
            ->send();
    }

    public function exportWidgets(): StreamedResponse
    {
        $json = json_encode(
            $this->widgets()->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            'widgets-export.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * The current widget configuration as pretty JSON, for clipboard copy.
     */
    public function getExportJsonProperty(): string
    {
        return (string) json_encode(
            $this->widgets()->export(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function importWidgets(): void
    {
        // An uploaded .json file takes precedence over the pasted textarea.
        $raw = $this->importJson;

        if ($this->importFile !== null) {
            try {
                $raw = (string) $this->importFile->get();
            } catch (\Throwable) {
                Notification::make()->title(tn_trans('Could not read the uploaded file'))->danger()->send();

                return;
            }
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            Notification::make()->title(tn_trans('Invalid JSON'))->danger()->send();

            return;
        }

        $mode = $this->importMode === 'replace' ? 'replace' : 'merge';
        $count = $this->widgets()->import($data, $mode);

        $this->reset(['importJson', 'importFile']);

        Notification::make()
            ->title(tn_trans(':count widget(s) imported', ['count' => $count]))
            ->success()
            ->send();
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function editingWidget(): ?Widget
    {
        if ($this->editingId === null) {
            return null;
        }

        return Widget::query()->with('translations')->find($this->editingId);
    }

    private function loadEditorState(Widget $widget): void
    {
        $schema = $this->widgets()->schemaFor($widget->widget_type);
        $global = is_array($widget->settings) ? $widget->settings : [];

        $translation = $widget->translations->firstWhere('locale', $this->editLocale);
        $localized = ($translation !== null && is_array($translation->settings)) ? $translation->settings : [];

        $fields = [];

        foreach ($schema as $field) {
            $key = $field['key'] ?? null;

            if (! is_string($key)) {
                continue;
            }

            if (($field['localized'] ?? false) === true) {
                $fields[$key] = $localized[$key] ?? $field['default'] ?? null;
            } else {
                $fields[$key] = $global[$key] ?? $field['default'] ?? null;
            }
        }

        $this->editFields = $fields;
        $this->editTitle = (string) ($translation?->title ?? $widget->title ?? '');
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeFieldValue(array $field, mixed $value): mixed
    {
        return match ($field['type'] ?? 'text') {
            'toggle' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => $this->clampNumber($field, $value),
            default => $value === null ? '' : (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function clampNumber(array $field, mixed $value): int
    {
        $number = (int) $value;

        if (isset($field['min']) && is_numeric($field['min'])) {
            $number = max((int) $field['min'], $number);
        }

        if (isset($field['max']) && is_numeric($field['max'])) {
            $number = min((int) $field['max'], $number);
        }

        return $number;
    }

    private function widgets(): WidgetManager
    {
        /** @var WidgetManager $manager */
        $manager = app('cms.widget');

        return $manager;
    }
}
