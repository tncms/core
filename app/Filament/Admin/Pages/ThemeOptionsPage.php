<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Components\MediaPicker;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ThemeCustomCssManager;
use TheNguyen\CMS\Services\ThemeOptionManager;
use TheNguyen\CMS\Support\Theme;
use TheNguyen\CMS\Support\ThemeOptionGroup;

/**
 * Appearance → Theme Options (v0.9.9).
 *
 * Renders a dynamic form from the *active theme's* declared option schema (read
 * from its functions.php via the ThemeManager) and persists the values into
 * cms_settings through the ThemeOptionManager. The schema — not the manifest
 * flag — decides whether options are shown: a theme that declares no valid
 * sections gets a friendly empty state, and no active theme at all shows a
 * warning. Neither path ever crashes the admin.
 */
class ThemeOptionsPage extends Page
{
    protected static ?string $slug = 'theme-options';

    protected static ?string $navigationLabel = 'Theme Options';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    // Sort after Themes (which is 20).
    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Theme Options';

    protected string $view = 'filament.admin.pages.theme-options-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Theme Options');
    }

    public function getTitle(): string
    {
        return tn_trans('Theme Options');
    }

    public static function canAccess(): bool
    {
        return cms_can('theme_options.manage');
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Per-request memo of the effective active theme (private so it is not
     * serialized into Livewire state — it is re-resolved each request, which is
     * always safe and cheap).
     */
    private ?Theme $activeThemeMemo = null;

    private bool $activeThemeResolved = false;

    /**
     * Memoised normalised schema sections for the active theme.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $sectionsCache = null;

    public function mount(): void
    {
        $theme = $this->activeTheme();

        // Custom CSS is a core feature available to every active theme, even one
        // that declares no option schema — so load whenever a theme is active.
        if ($theme === null) {
            return;
        }

        $data = $this->hasOptions() ? $this->options()->all($theme->slug) : [];

        $css = $this->customCss();
        $data[ThemeCustomCssManager::FRONTEND_KEY] = $css->frontendCss($theme->slug);
        $data[ThemeCustomCssManager::ADMIN_KEY] = $css->adminCss($theme->slug);

        $this->data = $data;
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        // CORE-THEME-OPTIONS-UX-1: render the schema as canonical tabs (Brand,
        // Colors, Layout, Media, SEO & Social, Custom CSS & Code, Advanced, with
        // General as the fallback), mirroring CMS Settings. The grouping comes
        // from the Core contract; this page only lays it out.
        return $schema
            ->components([
                Tabs::make('theme_options')
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs($this->buildTabs()),
            ])
            ->statePath('data');
    }

    /**
     * Build one Filament Tab per canonical group that has sections, always
     * ending with the Custom CSS editors inside the "Custom CSS & Code" tab.
     *
     * @return array<int, Tab>
     */
    private function buildTabs(): array
    {
        $tabs = [];
        $codeTabHasCss = false;

        foreach ($this->groupedSchema() as $group) {
            $sections = array_map(
                fn (array $section): Section => $this->sectionComponent($section),
                $group['sections'],
            );

            // The always-present Custom CSS editors live in the Code tab.
            if ($group['group'] === ThemeOptionGroup::CUSTOM_CSS) {
                $sections[] = $this->customCssSection();
                $codeTabHasCss = true;
            }

            $tabs[] = Tab::make(tn_trans($group['label']))
                ->icon($group['icon'])
                ->schema($sections);
        }

        // Custom CSS is a core feature for every active theme — even one that
        // declares no option schema or no code-group section gets the tab.
        if (! $codeTabHasCss) {
            $tabs[] = Tab::make(tn_trans(ThemeOptionGroup::label(ThemeOptionGroup::CUSTOM_CSS)))
                ->icon(ThemeOptionGroup::icon(ThemeOptionGroup::CUSTOM_CSS))
                ->schema([$this->customCssSection()]);
        }

        return $tabs;
    }

    /**
     * Render one normalised schema section as a Filament Section of fields.
     *
     * @param  array<string, mixed>  $section
     */
    private function sectionComponent(array $section): Section
    {
        return Section::make($section['label'])
            ->description($section['description'] !== '' ? $section['description'] : null)
            ->schema(array_map(fn (array $field): Component => $this->fieldComponent($field), $section['fields']))
            ->columns(1);
    }

    public function save(): void
    {
        $theme = $this->activeTheme();

        if ($theme === null) {
            return;
        }

        $state = $this->form->getState();
        $slug = $theme->slug;

        if ($this->hasOptions()) {
            $options = $this->options();

            foreach ($this->schemaSections() as $section) {
                foreach ($section['fields'] as $field) {
                    $key = $field['key'];
                    $options->set($key, $state[$key] ?? null, $slug);
                }
            }
        }

        // Store Custom CSS through the manager, which validates each field and
        // returns reasons for any it refused to save (invalid CSS never persists
        // and never renders).
        $cssErrors = $this->customCss()->save(
            (string) ($state[ThemeCustomCssManager::FRONTEND_KEY] ?? ''),
            (string) ($state[ThemeCustomCssManager::ADMIN_KEY] ?? ''),
            $slug,
        );

        settings()->clearCache();

        if ($cssErrors !== []) {
            Notification::make()
                ->title(tn_trans('Theme options saved, but some Custom CSS was rejected'))
                ->body(implode(' ', $cssErrors))
                ->warning()
                ->send();

            return;
        }

        Notification::make()->title(tn_trans('Theme options saved'))->success()->send();
    }

    /**
     * The always-present Custom CSS section (Frontend + Admin editors). Both are
     * plain multiline editors; values are validated and rendered through the
     * Asset Registry, never echoed from this page.
     */
    private function customCssSection(): Section
    {
        return Section::make(tn_trans('Custom CSS'))
            ->description(tn_trans('Inject custom CSS into the site and the admin panel. CSS only — scripts and unsafe patterns are rejected. Max 256 KB per field.'))
            ->schema([
                Textarea::make(ThemeCustomCssManager::FRONTEND_KEY)
                    ->label(tn_trans('Frontend CSS'))
                    ->helperText(tn_trans('Rendered only on the public site.'))
                    ->rows(10)
                    ->maxLength(ThemeCustomCssManager::MAX_BYTES),
                Textarea::make(ThemeCustomCssManager::ADMIN_KEY)
                    ->label(tn_trans('Admin CSS'))
                    ->helperText(tn_trans('Rendered only inside the admin panel.'))
                    ->rows(10)
                    ->maxLength(ThemeCustomCssManager::MAX_BYTES),
            ])
            ->columns(1)
            ->collapsible();
    }

    public function hasActiveTheme(): bool
    {
        return $this->activeTheme() !== null;
    }

    public function activeThemeName(): ?string
    {
        return $this->activeTheme()?->name;
    }

    public function hasOptions(): bool
    {
        return $this->schemaSections() !== [];
    }

    /**
     * Resolve (and memoise for the request) the effective active theme.
     */
    private function activeTheme(): ?Theme
    {
        if (! $this->activeThemeResolved) {
            $this->activeThemeMemo = theme()->active();
            $this->activeThemeResolved = true;
        }

        return $this->activeThemeMemo;
    }

    /**
     * Map a normalised schema field to its Filament component.
     */
    private function fieldComponent(array $field): Component
    {
        $key = $field['key'];
        $label = $field['label'];

        // The image picker is a self-contained Group (own label + actions).
        if ($field['type'] === 'image') {
            return MediaPicker::make($key, $label);
        }

        $component = match ($field['type']) {
            'textarea' => Textarea::make($key)->rows(3),
            'boolean' => Toggle::make($key),
            'number' => $this->numberField($key, $field),
            'select' => Select::make($key)
                ->options($this->selectOptions($field['options'] ?? []))
                ->native(false),
            'color' => ColorPicker::make($key),
            'page' => $this->pageField($key),
            default => TextInput::make($key), // text
        };

        $component = $component->label($label);

        if (($field['helper'] ?? null) !== null && method_exists($component, 'helperText')) {
            $component = $component->helperText($field['helper']);
        }

        if (($field['placeholder'] ?? null) !== null && method_exists($component, 'placeholder')) {
            $component = $component->placeholder($field['placeholder']);
        }

        return $component;
    }

    private function numberField(string $key, array $field): TextInput
    {
        $component = TextInput::make($key)->numeric();

        if (($field['min'] ?? null) !== null) {
            $component = $component->minValue($field['min']);
        }

        if (($field['max'] ?? null) !== null) {
            $component = $component->maxValue($field['max']);
        }

        return $component;
    }

    /**
     * The `page` field: a searchable picker over CMS Pages (never Posts) that
     * stores the stable canonical page id (CORE-THEME-PAGE-REFERENCE-1).
     *
     * - Options come from a bounded search query (localized title, limit 50) — no
     *   full-table load, no N+1.
     * - The currently stored id always resolves to a label (including an
     *   unpublished/soft-deleted page, flagged "(unavailable)", or a bare
     *   "#id — (unavailable)" when hard-deleted), so an unrelated save never
     *   silently drops a stale selection.
     * - Server-side validation (not UI-only) rejects a malformed id or one that
     *   points at a non-page (a post), while accepting empty / a page / a
     *   now-missing id.
     */
    private function pageField(string $key): Select
    {
        return Select::make($key)
            ->native(false)
            ->searchable()
            ->placeholder(tn_trans('No page selected'))
            ->getSearchResultsUsing(fn (string $search): array => $this->pageSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => $this->pageOptionLabel($value))
            ->rules([
                static function (): \Closure {
                    return static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! app('cms.theme_page_reference')->isPersistable($value)) {
                            $fail(tn_trans('Select a CMS Page (not a post).'));
                        }
                    };
                },
            ]);
    }

    /**
     * Bounded page search for the picker: up to 50 `type=page` records whose
     * title in the editing locale matches the term (empty term lists the first
     * pages), keyed by id => localized label.
     *
     * @return array<int, string>
     */
    private function pageSearchResults(string $search): array
    {
        $locale = editing_locale();

        $query = Content::query()
            ->where('type', 'page')
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)]);

        $search = trim($search);

        if ($search !== '') {
            $query->whereHas('translations', fn ($q) => $q->where('locale', $locale)->where('title', 'like', '%'.$search.'%'));
        }

        return $query
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Content $page): array => [$page->id => $this->pageLabel($page, $locale)])
            ->all();
    }

    /**
     * Resolve the display label for a stored page id, preserving (and flagging)
     * unavailable selections. Returns null only for an empty/malformed value.
     */
    private function pageOptionLabel(mixed $value): ?string
    {
        $resolver = app('cms.theme_page_reference');
        $id = $resolver->normalizeId($value);

        if ($id === null) {
            return null;
        }

        $page = $resolver->anyPage($id);

        if ($page === null) {
            return '#'.$id.' — '.tn_trans('(unavailable)');
        }

        return $this->pageLabel($page, editing_locale());
    }

    /**
     * Localized page title, suffixed with "(unavailable)" when the page would not
     * produce a public link (soft-deleted or not published).
     */
    private function pageLabel(Content $page, string $locale): string
    {
        $title = $page->translatedTitle($locale);

        if ($page->trashed() || $page->status !== 'published') {
            $title .= ' — '.tn_trans('(unavailable)');
        }

        return $title;
    }

    /**
     * Coerce a declared select options array into a Filament value=>label map.
     *
     * @param  array<mixed>  $options
     * @return array<string, string>
     */
    private function selectOptions(array $options): array
    {
        $result = [];

        // A JSON array (['a','b']) is a value list; a JSON object — including one
        // whose keys look numeric ({"280":"280px"}, which PHP decodes to int keys)
        // — is a value=>label map. Distinguishing by list-ness (not per-key
        // is_int) keeps numeric-keyed option maps selectable by their real value.
        if (array_is_list($options)) {
            foreach ($options as $label) {
                $result[(string) $label] = (string) $label;
            }

            return $result;
        }

        foreach ($options as $value => $label) {
            $result[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function schemaSections(): array
    {
        if ($this->sectionsCache !== null) {
            return $this->sectionsCache;
        }

        $slug = $this->activeTheme()?->slug;

        return $this->sectionsCache = $this->options()->schema($slug)['sections'];
    }

    /**
     * The active theme's schema sections grouped into canonical admin tabs.
     *
     * @return array<int, array{group: string, label: string, icon: string, sections: array<int, array<string, mixed>>}>
     */
    private function groupedSchema(): array
    {
        return $this->options()->groupedSchema($this->activeTheme()?->slug);
    }

    private function options(): ThemeOptionManager
    {
        /** @var ThemeOptionManager $manager */
        $manager = app('cms.theme_option');

        return $manager;
    }

    private function customCss(): ThemeCustomCssManager
    {
        /** @var ThemeCustomCssManager $manager */
        $manager = app('cms.theme_custom_css');

        return $manager;
    }
}
