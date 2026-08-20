<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Components\MediaPicker;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use TheNguyen\CMS\Registries\SectionRegistry;
use TheNguyen\CMS\Services\EditorialOptions;
use TheNguyen\CMS\Services\LayoutEditor;
use TheNguyen\CMS\Support\LocalizedValue;

/**
 * Appearance → Homepage Layout.
 *
 * A button-based (no drag-drop) editor for the active theme's homepage layout
 * JSON. All structural operations and persistence go through the theme-agnostic
 * {@see LayoutEditor}; the per-section edit form is generated from the
 * {@see SectionRegistry} schema — there are no per-preset special cases, so the
 * same editor serves Company, Blog, Magazine, and future presets.
 */
class HomepageLayoutPage extends Page
{
    protected static ?string $slug = 'homepage-layout';

    protected static ?string $navigationLabel = 'Homepage Layout';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 35;

    protected static ?string $title = 'Homepage Layout';

    protected string $view = 'filament.admin.pages.homepage-layout-page';

    /** @var array{version: int, sections: array<int, array<string, mixed>>} */
    public array $document = ['version' => 1, 'sections' => []];

    public ?string $editingId = null;

    public ?string $addType = null;

    /** @var array<string, mixed> */
    public array $editData = [];

    /** The locale whose content is being edited (Phase 4F-B). */
    public string $editLocale = '';

    /** The locale the in-memory form values currently represent. */
    public string $formLocale = '';

    /** Whether the working layout has changes not yet saved. */
    public bool $dirty = false;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Homepage Layout');
    }

    public function getTitle(): string
    {
        return tn_trans('Homepage Layout');
    }

    public static function canAccess(): bool
    {
        return cms_can('theme_options.manage');
    }

    public function mount(): void
    {
        $this->document = $this->editor()->load($this->themeSlug());
        $this->editLocale = $this->defaultEditLocale();
        $this->formLocale = $this->editLocale;
        $this->dirty = false;
    }

    // ---------------------------------------------------------------------
    // Structural actions (delegate to LayoutEditor)
    // ---------------------------------------------------------------------

    public function addSection(): void
    {
        if (! is_string($this->addType) || $this->addType === '') {
            return;
        }

        $this->document = $this->editor()->addSection($this->document, $this->addType);
        $this->addType = null;
        $this->dirty = true;
    }

    public function removeSection(string $id): void
    {
        $this->document = $this->editor()->removeSection($this->document, $id);
        $this->dirty = true;

        if ($this->editingId === $id) {
            $this->cancelEdit();
        }
    }

    public function duplicateSection(string $id): void
    {
        $this->document = $this->editor()->duplicateSection($this->document, $id);
        $this->dirty = true;
    }

    public function moveUp(string $id): void
    {
        $this->document = $this->editor()->moveSection($this->document, $id, 'up');
        $this->dirty = true;
    }

    public function moveDown(string $id): void
    {
        $this->document = $this->editor()->moveSection($this->document, $id, 'down');
        $this->dirty = true;
    }

    public function toggleEnabled(string $id): void
    {
        $section = $this->editor()->getSection($this->document, $id);

        if ($section !== null) {
            $this->document = $this->editor()->setEnabled($this->document, $id, ! ($section['enabled'] ?? true));
            $this->dirty = true;
        }
    }

    public function save(): void
    {
        $result = $this->editor()->save($this->themeSlug(), $this->document);

        if (! $result['success']) {
            Notification::make()->title(tn_trans('Could not save layout'))->body(implode("\n", $result['errors']))->danger()->send();

            return;
        }

        $this->document = $result['document'];
        $this->dirty = false;

        $notification = Notification::make()->title(tn_trans('Homepage layout saved'))->success();

        if ($result['warnings'] !== []) {
            $notification->body(implode("\n", $result['warnings']));
        }

        $notification->send();
    }

    public function resetToPreset(): void
    {
        $this->document = $this->editor()->resetToPreset($this->themeSlug());
        $this->cancelEdit();
        $this->dirty = false;
        Notification::make()->title(tn_trans('Homepage layout reset to preset'))->success()->send();
    }

    // ---------------------------------------------------------------------
    // Section editing (schema-driven form)
    // ---------------------------------------------------------------------

    public function editSection(string $id): void
    {
        $section = $this->editor()->getSection($this->document, $id);

        if ($section === null) {
            return;
        }

        $this->editingId = $id;
        $this->formLocale = $this->editLocale;
        $this->editData = $this->buildEditData($section);
        $this->form->fill($this->editData);
    }

    /**
     * Switch the locale being edited. Localized values reload for the new locale;
     * section order/settings/structure are untouched. Any in-progress edits for
     * the previous locale are merged into the working document first so nothing
     * is lost when toggling languages (Phase 4F-B §B3).
     */
    public function updatedEditLocale(): void
    {
        if ($this->editingId === null) {
            $this->formLocale = $this->editLocale;

            return;
        }

        $state = $this->form->getState();
        $this->document = $this->editor()->updateSection(
            $this->document,
            $this->editingId,
            is_array($state['settings'] ?? null) ? $state['settings'] : [],
            is_array($state['fields'] ?? null) ? $state['fields'] : [],
            $this->writeLocale(),
        );

        $section = $this->editor()->getSection($this->document, $this->editingId);

        if ($section !== null) {
            $this->editData = $this->buildEditData($section);
            $this->form->fill($this->editData);
        }

        $this->formLocale = $this->editLocale;
    }

    public function applyEdit(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $state = $this->form->getState();
        $this->document = $this->editor()->updateSection(
            $this->document,
            $this->editingId,
            is_array($state['settings'] ?? null) ? $state['settings'] : [],
            is_array($state['fields'] ?? null) ? $state['fields'] : [],
            $this->writeLocale(),
        );

        $this->dirty = true;
        $this->cancelEdit();
        Notification::make()->title(tn_trans('Section updated'))->success()->send();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editData = [];
    }

    public function form(Schema $schema): Schema
    {
        $type = $this->editingType();

        if ($type === null) {
            return $schema->components([])->statePath('editData');
        }

        $registry = $this->registry();
        $components = [];

        $settings = $registry->settingsSchema($type);
        if ($settings !== []) {
            $components[] = FormSection::make(tn_trans('Settings'))
                ->schema($this->buildComponents($settings, 'settings'))
                ->columns(2);
        }

        $fields = $registry->fieldsSchema($type);
        if ($fields !== []) {
            $components[] = FormSection::make(tn_trans('Content'))
                ->schema($this->buildComponents($fields, 'fields'))
                ->columns(1);
        }

        return $schema->components($components)->statePath('editData');
    }

    // ---------------------------------------------------------------------
    // View helpers
    // ---------------------------------------------------------------------

    /**
     * The registered section types for the "add section" dropdown (type => title).
     *
     * @return array<string, string>
     */
    public function sectionTypeOptions(): array
    {
        $options = [];

        foreach ($this->registry()->all() as $type => $entry) {
            $options[$type] = is_string($entry['title'] ?? null) ? $entry['title'] : $type;
        }

        asort($options);

        return $options;
    }

    /**
     * The registered section types grouped by category for the "add section"
     * picker (category => [type => title]), so related sections cluster together
     * instead of exposing one flat list (Phase 4F-B §A3).
     *
     * @return array<string, array<string, string>>
     */
    public function sectionTypeGroups(): array
    {
        $groups = [];

        foreach ($this->registry()->all() as $type => $entry) {
            $category = is_string($entry['category'] ?? null) && $entry['category'] !== '' ? $entry['category'] : 'other';
            $title = is_string($entry['title'] ?? null) ? $entry['title'] : $type;
            $groups[$category][$type] = $title;
        }

        foreach ($groups as &$titles) {
            asort($titles);
        }
        unset($titles);

        ksort($groups);

        return $groups;
    }

    /**
     * The sections in the working layout, decorated for the card list: display
     * title, type, enabled flag, and a human-friendly, locale-aware preview.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sectionRows(): array
    {
        $rows = [];

        foreach ($this->document['sections'] as $section) {
            $type = (string) ($section['type'] ?? '');
            $entry = $this->registry()->get($type);
            $id = (string) ($section['id'] ?? '');

            $preview = $this->sectionPreview(is_array($section) ? $section : []);

            $rows[] = [
                'id' => $id,
                'type' => $type,
                'title' => is_array($entry) && is_string($entry['title'] ?? null) ? $entry['title'] : $type,
                'enabled' => ($section['enabled'] ?? true) !== false,
                'preview' => $preview !== '' ? $preview : trim($type.' · '.$id),
            ];
        }

        return $rows;
    }

    // ---------------------------------------------------------------------
    // Localization (Phase 4F-B)
    // ---------------------------------------------------------------------

    /**
     * Active locales for the editor selector (code => label).
     *
     * @return array<string, string>
     */
    public function localeOptions(): array
    {
        return app('cms.language')->optionList();
    }

    /**
     * Whether more than one active locale exists (controls whether the language
     * selector is shown at all — §B3).
     */
    public function hasMultipleLocales(): bool
    {
        return count($this->localeOptions()) > 1;
    }

    /**
     * The locale to merge an edit under: only when multiple locales exist do we
     * write the localized object format; a single-language site keeps plain
     * strings (simpler, and existing single-locale layouts are unchanged).
     */
    private function writeLocale(): ?string
    {
        return $this->hasMultipleLocales() ? $this->formLocale : null;
    }

    public function editingType(): ?string
    {
        if ($this->editingId === null) {
            return null;
        }

        $section = $this->editor()->getSection($this->document, $this->editingId);

        return is_array($section) ? (string) $section['type'] : null;
    }

    // ---------------------------------------------------------------------
    // Schema → Filament components
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<int, Component>
     */
    private function buildComponents(array $schema, string $prefix): array
    {
        $controllers = $this->controllerKeys($schema);
        $components = [];

        foreach ($schema as $key => $spec) {
            $components[] = $this->buildComponent((string) $key, is_array($spec) ? $spec : [], $prefix.'.'.$key, $controllers);
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, true>  $controllers  keys that other fields key their
     *                                            visibility on (made reactive)
     */
    private function buildComponent(string $key, array $spec, string $statePath, array $controllers = []): Component
    {
        $label = $this->schemaLabel($key, $spec);
        $type = $spec['type'] ?? 'text';

        $component = match ($type) {
            'textarea', 'richtext' => Textarea::make($statePath)->rows(3),
            'boolean' => Toggle::make($statePath),
            'number' => $this->numberField($statePath, $spec),
            'enum' => Select::make($statePath)
                ->options($this->enumOptions(
                    is_array($spec['values'] ?? null) ? $spec['values'] : [],
                    is_array($spec['labels'] ?? null) ? $spec['labels'] : [],
                ))->native(false),
            'terms' => Select::make($statePath)
                ->multiple()
                ->searchable()
                ->options($this->termOptions(is_string($spec['taxonomy'] ?? null) ? $spec['taxonomy'] : 'category')),
            'content' => Select::make($statePath)
                ->multiple()
                ->searchable()
                ->options($this->contentOptions()),
            'authors' => Select::make($statePath)
                ->multiple()
                ->searchable()
                ->options($this->authorOptions()),
            'media' => MediaPicker::make($statePath, $label),
            'link' => $this->linkField($statePath, $label),
            'repeater' => $this->repeaterField($statePath, $label, $spec),
            default => TextInput::make($statePath),
        };

        // MediaPicker / Fieldset / Repeater set their own label.
        if (! in_array($type, ['media', 'link', 'repeater'], true)) {
            $component = $component->label($label);
        }

        // A field other components watch must push live updates so their reactive
        // show/hide re-evaluates as soon as it changes.
        if (isset($controllers[$key])) {
            $component = $component->live();
        }

        // Reactive show/hide driven by sibling settings (Phase 9C-B query builder).
        // dehydrated(true) keeps the value in saved state even while hidden, so a
        // section's stored config is never silently dropped.
        if (is_array($spec['visible_when'] ?? null) && $spec['visible_when'] !== []) {
            $base = Str::beforeLast($statePath, '.');
            $conditions = $spec['visible_when'];

            $component = $component
                ->visible(fn (Get $get): bool => $this->conditionsMet($get, $base, $conditions))
                ->dehydrated(true);
        }

        return $component;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function numberField(string $statePath, array $spec): TextInput
    {
        $field = TextInput::make($statePath)->numeric();

        if (($spec['min'] ?? null) !== null) {
            $field = $field->minValue($spec['min']);
        }
        if (($spec['max'] ?? null) !== null) {
            $field = $field->maxValue($spec['max']);
        }

        return $field;
    }

    private function linkField(string $statePath, string $label): Fieldset
    {
        return Fieldset::make($label)
            ->statePath($statePath)
            ->schema([
                TextInput::make('label')->label(tn_trans('Label')),
                TextInput::make('url')->label(tn_trans('URL')),
                Select::make('variant')->label(tn_trans('Style'))
                    ->options(['solid' => 'Solid', 'outline' => 'Outline', 'pill' => 'Pill'])
                    ->native(false),
            ])
            ->columns(3);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function repeaterField(string $statePath, string $label, array $spec): Repeater
    {
        $item = is_array($spec['item'] ?? null) ? $spec['item'] : [];

        $schema = [];
        foreach ($item as $subKey => $subSpec) {
            $schema[] = $this->buildComponent((string) $subKey, is_array($subSpec) ? $subSpec : [], (string) $subKey);
        }

        $repeater = Repeater::make($statePath)->label($label)->schema($schema)->collapsible();

        if (is_int($spec['max'] ?? null)) {
            $repeater = $repeater->maxItems($spec['max']);
        }

        return $repeater;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    /**
     * Build a Select's option map. When the schema supplies a `labels` map
     * (value => English label), each label is run through the admin translation
     * system so it renders in the current admin locale; otherwise the raw value
     * doubles as its own label (unchanged historic behavior).
     *
     * @param  array<int, mixed>  $values
     * @param  array<int|string, string>  $labels
     * @return array<string, string>
     */
    private function enumOptions(array $values, array $labels = []): array
    {
        $options = [];

        foreach ($values as $value) {
            $key = (string) $value;
            $options[$key] = isset($labels[$value]) && is_string($labels[$value])
                ? tn_trans($labels[$value])
                : $key;
        }

        return $options;
    }

    /**
     * Option resolvers for the term / content / author pickers. These are the
     * page's UI entry points; the actual query + admin-locale label logic lives in
     * {@see EditorialOptions} (cms-core) so it stays reusable and out of the UI
     * layer. Stored config keeps ids only — never these resolved labels.
     *
     * @return array<int, string>
     */
    public function termOptions(string $taxonomyType): array
    {
        return app(EditorialOptions::class)->termOptions($taxonomyType);
    }

    /**
     * @return array<int, string>
     */
    public function contentOptions(): array
    {
        return app(EditorialOptions::class)->contentOptions();
    }

    /**
     * @return array<int|string, string>
     */
    public function authorOptions(): array
    {
        return app(EditorialOptions::class)->authorOptions();
    }

    /**
     * Keys that at least one field keys its `visible_when` on — these must be
     * reactive so the editor re-evaluates visibility as they change.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, true>
     */
    private function controllerKeys(array $schema): array
    {
        $keys = [];

        foreach ($schema as $spec) {
            if (is_array($spec) && is_array($spec['visible_when'] ?? null)) {
                foreach (array_keys($spec['visible_when']) as $controller) {
                    $keys[(string) $controller] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Evaluate a `visible_when` condition map (controlling key => allowed values),
     * AND-ing every condition, against sibling state under $base.
     *
     * @param  array<string, mixed>  $conditions
     */
    private function conditionsMet(Get $get, string $base, array $conditions): bool
    {
        foreach ($conditions as $controller => $allowed) {
            $value = $get($base.'.'.$controller);

            if (! is_array($allowed) || ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The label for a schema field: an explicit (translatable) `label` runs
     * through the admin translation system; otherwise the key is headlined.
     *
     * @param  array<string, mixed>  $spec
     */
    private function schemaLabel(string $key, array $spec): string
    {
        if (isset($spec['label']) && is_string($spec['label']) && $spec['label'] !== '') {
            return tn_trans($spec['label']);
        }

        return $this->labelFor($key);
    }

    private function labelFor(string $key): string
    {
        return Str::headline($key);
    }

    // ---------------------------------------------------------------------
    // Localization & preview internals
    // ---------------------------------------------------------------------

    /**
     * Build the edit-form state for a section, projected onto the current edit
     * locale: localized text → that locale's string, media → URL.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function buildEditData(array $section): array
    {
        return [
            'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
            'fields' => $this->editor()->toEditorFields(
                (string) ($section['type'] ?? ''),
                is_array($section['fields'] ?? null) ? $section['fields'] : [],
                $this->editLocale,
            ),
        ];
    }

    /**
     * The locale the editor opens in: the content EDITING locale
     * (v1.0.0-beta.7.1.10.2) — homepage section content is localized, so it
     * follows editing_locale(), not the admin UI language. The in-page locale
     * switcher can still change it.
     */
    private function defaultEditLocale(): string
    {
        return editing_locale();
    }

    /**
     * A short, human-friendly, locale-aware preview of a section's content for
     * the card list. Null-safe: missing fields contribute nothing.
     *
     * @param  array<string, mixed>  $section
     */
    private function sectionPreview(array $section): string
    {
        $type = (string) ($section['type'] ?? '');
        $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];

        $text = fn (string $key): string => $this->previewString($fields[$key] ?? null);
        $count = fn (string $key): int => is_array($fields[$key] ?? null) ? count($fields[$key]) : 0;

        $parts = match ($type) {
            'hero' => [$text('title'), $this->excerpt($text('subtitle'))],
            'feature-grid' => [$text('heading'), $this->countLabel($count('features'), 'feature', 'features')],
            'feature-split' => [$text('heading'), $this->excerpt($this->previewString($fields['body'] ?? null))],
            'faq' => [$text('heading'), $this->countLabel($count('items'), 'question', 'questions')],
            'cta' => [$text('title'), $this->previewLink($fields['button'] ?? null)],
            'testimonials' => [$text('heading'), $this->countLabel($count('items'), 'testimonial', 'testimonials')],
            'logo-strip' => [$text('heading'), $this->countLabel($count('logos'), 'logo', 'logos')],
            'stats-band' => [$text('heading'), $this->countLabel($count('stats'), 'stat', 'stats')],
            'pricing' => [$text('heading'), $this->countLabel($count('plans'), 'plan', 'plans')],
            'media-band' => [$text('caption')],
            'rich-text' => [$this->excerpt(strip_tags($text('body')))],
            'newsletter' => [$text('heading'), $text('subtitle')],
            default => [$text('title'), $text('heading')],
        };

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        return implode(' — ', $parts);
    }

    /**
     * Resolve a possibly-localized field value to a display string for the
     * current edit locale (with default-locale fallback). Non-strings → ''.
     */
    private function previewString(mixed $value): string
    {
        $resolved = LocalizedValue::resolve($value, $this->previewChain());

        return is_string($resolved) ? trim($resolved) : '';
    }

    private function previewLink(mixed $link): string
    {
        if (! is_array($link)) {
            return '';
        }

        return $this->previewString($link['label'] ?? null);
    }

    /**
     * The locale fallback chain used for preview rendering.
     *
     * @return array<int, string>
     */
    private function previewChain(): array
    {
        $default = app('cms.language')->defaultCode();

        return array_values(array_unique(array_filter([$this->editLocale, $default])));
    }

    private function excerpt(string $text, int $length = 80): string
    {
        return Str::limit(trim($text), $length);
    }

    private function countLabel(int $count, string $singular, string $plural): string
    {
        if ($count <= 0) {
            return '';
        }

        return $count.' '.tn_trans($count === 1 ? $singular : $plural);
    }

    private function themeSlug(): string
    {
        return theme()->active()?->slug ?? 'default';
    }

    private function editor(): LayoutEditor
    {
        return app('cms.layout_editor');
    }

    private function registry(): SectionRegistry
    {
        return app('cms.section_registry');
    }
}
