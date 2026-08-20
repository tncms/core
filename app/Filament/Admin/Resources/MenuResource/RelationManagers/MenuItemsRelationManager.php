<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MenuResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\MenuItem;
use TheNguyen\CMS\Models\Taxonomy;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\EditorialOptions;

class MenuItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Menu Items';

    /**
     * Render inline during the parent edit page's full GET request so the
     * `?locale=` query param is captured in mount() (a lazy relation manager
     * would mount in a Livewire request that no longer carries it).
     */
    protected static bool $isLazy = false;

    /**
     * Menu edit locale, captured from the parent edit page's ?locale= query
     * param at mount and persisted across the relation manager's Livewire
     * requests. All item title reads/writes use this locale. Locked so the
     * client cannot forge a different locale into the Livewire payload.
     */
    #[Locked]
    public ?string $editLocale = null;

    /**
     * Item types and whether they reference content/terms or a custom URL.
     */
    private const REFERENCE_TYPES = ['page', 'post', 'category', 'tag'];

    public function mount(): void
    {
        parent::mount();

        $this->editLocale = $this->resolveSelectedLocale();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('locale')->default(fn (): string => $this->selectedLocale()),

                TextInput::make('title')
                    ->label(tn_trans('Title'))
                    ->required()
                    ->maxLength(255)
                    ->helperText(fn (): string => tn_trans('Editing menu item title for: :locale', ['locale' => $this->localeLabel()])),

                Select::make('type')
                    ->label(tn_trans('Type'))
                    ->options([
                        'custom' => tn_trans('Custom link'),
                        'page' => tn_trans('Page'),
                        'post' => tn_trans('Post'),
                        'category' => tn_trans('Category'),
                        'tag' => tn_trans('Tag'),
                    ])
                    ->default('custom')
                    ->required()
                    ->live(),

                Select::make('reference_id')
                    ->label(tn_trans('Linked content'))
                    ->options(fn (Get $get): array => self::referenceOptions((string) ($get('type') ?? 'custom')))
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => in_array($get('type'), self::REFERENCE_TYPES, true))
                    ->required(fn (Get $get): bool => in_array($get('type'), self::REFERENCE_TYPES, true))
                    ->helperText(tn_trans('Pick the page/post/category/tag this item links to.')),

                TextInput::make('url')
                    ->label(tn_trans('URL'))
                    ->placeholder(tn_trans('https://example.com or /custom-path'))
                    ->visible(fn (Get $get): bool => ($get('type') ?? 'custom') === 'custom')
                    ->required(fn (Get $get): bool => ($get('type') ?? 'custom') === 'custom')
                    ->helperText(fn (): string => tn_trans('Menu item title and URL are language-specific (editing: :locale).', ['locale' => $this->localeLabel()]))
                    ->maxLength(255),

                Select::make('target')
                    ->label(tn_trans('Open in'))
                    ->options([
                        '_self' => tn_trans('Same tab'),
                        '_blank' => tn_trans('New tab'),
                    ])
                    ->default('_self')
                    ->required(),

                Select::make('parent_id')
                    ->label(tn_trans('Parent item'))
                    ->options(fn (?MenuItem $record): array => $this->parentOptions($record))
                    ->placeholder(tn_trans('None (top level)'))
                    ->searchable(),

                TextInput::make('css_class')
                    ->label(tn_trans('CSS class'))
                    ->maxLength(255),

                TextInput::make('sort_order')
                    ->label(tn_trans('Sort order'))
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label(tn_trans('Active'))
                    ->default(true),

                // Phase 11B — presentational metadata. Mega-only controls hide
                // unless Display type is "mega"; icon/badge/description apply to
                // any item type. Stored in the additive meta JSON (icon keeps
                // its own column) and normalized safely on read.
                Section::make(tn_trans('Appearance'))
                    ->schema([
                        Select::make('display')
                            ->label(tn_trans('Display type'))
                            ->options([
                                'normal' => tn_trans('Normal'),
                                'dropdown' => tn_trans('Dropdown'),
                                'mega' => tn_trans('Mega menu'),
                            ])
                            ->default('normal')
                            ->selectablePlaceholder(false)
                            ->live()
                            ->helperText(tn_trans('Mega renders children in a multi-column panel.')),

                        Select::make('mega_columns')
                            ->label(tn_trans('Mega columns'))
                            ->options([2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'])
                            ->default(4)
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => $get('display') === 'mega'),

                        Select::make('mega_width')
                            ->label(tn_trans('Mega width'))
                            ->options([
                                'content' => tn_trans('Content'),
                                'wide' => tn_trans('Wide'),
                                'full' => tn_trans('Full'),
                            ])
                            ->default('wide')
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => $get('display') === 'mega'),

                        Select::make('mega_source')
                            ->label(tn_trans('Mega source'))
                            ->options([
                                'children' => tn_trans('Children'),
                                'widget_area' => tn_trans('Widget Area'),
                                'latest_posts' => tn_trans('Latest Posts'),
                                'categories' => tn_trans('By Categories'),
                                'tags' => tn_trans('By Tags'),
                                'manual_posts' => tn_trans('Manual Posts'),
                            ])
                            ->default('children')
                            ->selectablePlaceholder(false)
                            ->live()
                            ->visible(fn (Get $get): bool => $get('display') === 'mega'),

                        TextInput::make('mega_widget_area')
                            ->label(tn_trans('Widget area key'))
                            ->placeholder('menu.topics')
                            ->helperText(tn_trans('Use a registered widget area slug.'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && $get('mega_source') === 'widget_area'),

                        // Dynamic mega sources (Phase 11D). Options come from the
                        // shared EditorialOptions service so query logic is not
                        // duplicated in the page; values are ids stored in meta.
                        Select::make('mega_category_ids')
                            ->label(tn_trans('Categories'))
                            ->multiple()
                            ->options(fn (): array => app(EditorialOptions::class)->termOptions('category'))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && $get('mega_source') === 'categories'),

                        Select::make('mega_tag_ids')
                            ->label(tn_trans('Tags'))
                            ->multiple()
                            ->options(fn (): array => app(EditorialOptions::class)->termOptions('tag'))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && $get('mega_source') === 'tags'),

                        Select::make('mega_post_ids')
                            ->label(tn_trans('Posts'))
                            ->multiple()
                            ->options(fn (): array => app(EditorialOptions::class)->contentOptions())
                            ->searchable()
                            ->helperText(tn_trans('Selection order is preserved.'))
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && $get('mega_source') === 'manual_posts'),

                        TextInput::make('mega_limit')
                            ->label(tn_trans('Number of posts'))
                            ->numeric()
                            ->default(MenuItem::MEGA_LIMIT_DEFAULT)
                            ->minValue(MenuItem::MEGA_LIMIT_MIN)
                            ->maxValue(MenuItem::MEGA_LIMIT_MAX)
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && in_array($get('mega_source'), ['latest_posts', 'categories', 'tags'], true)),

                        Select::make('mega_order_by')
                            ->label(tn_trans('Order by'))
                            ->options([
                                'latest' => tn_trans('Latest'),
                                'oldest' => tn_trans('Oldest'),
                                'most_viewed' => tn_trans('Most viewed'),
                                'most_commented' => tn_trans('Most commented'),
                                'random' => tn_trans('Random'),
                                'title' => tn_trans('Title'),
                            ])
                            ->default('latest')
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => $get('display') === 'mega' && in_array($get('mega_source'), ['latest_posts', 'categories', 'tags'], true)),

                        TextInput::make('icon')
                            ->label(tn_trans('Icon'))
                            ->maxLength(255),

                        // Badge/description are localized (Phase 11D): one input per
                        // active locale, saved into meta as a locale map.
                        ...$this->localizedInputs('badge', tn_trans('Badge'), 50),
                        ...$this->localizedInputs('description', tn_trans('Description'), 255, true),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('translations'))
            ->columns([
                TextColumn::make('title')
                    ->label(tn_trans('Title'))
                    ->state(fn (MenuItem $record): string => $this->tableTitle($record)),

                BadgeColumn::make('type')
                    ->label(tn_trans('Type')),

                TextColumn::make('url')
                    ->label(tn_trans('URL'))
                    ->state(fn (MenuItem $record): string => $record->resolvedUrl($this->selectedLocale()))
                    ->limit(40),

                IconColumn::make('is_active')
                    ->label(tn_trans('Active'))
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label(tn_trans('Order'))
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(tn_trans('Add Item'))
                    ->using(fn (array $data): Model => app('cms.menu')->createItem($this->getOwnerRecord(), $this->withLocalizedMeta($data))),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, MenuItem $record): array {
                        $locale = $this->selectedLocale();
                        $translation = $record->translations()->where('locale', $locale)->first();

                        // Fill the selected locale's title/url, or leave empty so
                        // the user can safely create the missing translation.
                        $data['title'] = $translation?->title ?? '';
                        $data['locale'] = $locale;

                        // Custom URLs are per-locale; show this locale's URL only
                        // (falling back to the legacy shared column for old data).
                        if (($record->type) === 'custom') {
                            $data['url'] = $translation?->url ?? $record->url ?? '';
                        }

                        // Hydrate the Appearance fields from normalized metadata
                        // (icon comes from its own column via resolvedMeta()).
                        $meta = $record->resolvedMeta($locale);
                        $data['display'] = $meta['display'];
                        $data['mega_columns'] = $meta['mega_columns'];
                        $data['mega_width'] = $meta['mega_width'];
                        $data['mega_source'] = $meta['mega_source'];
                        $data['mega_widget_area'] = $meta['mega_widget_area'];
                        $data['mega_limit'] = $meta['mega_limit'];
                        $data['mega_order_by'] = $meta['mega_order_by'];
                        $data['mega_category_ids'] = $meta['mega_category_ids'];
                        $data['mega_tag_ids'] = $meta['mega_tag_ids'];
                        $data['mega_post_ids'] = $meta['mega_post_ids'];

                        // Badge/description are localized: hydrate the per-locale
                        // inputs from the RAW stored value (a legacy scalar seeds
                        // the default locale so a re-save localizes it).
                        $rawMeta = is_array($record->meta) ? $record->meta : [];
                        $data['badge_i18n'] = $this->hydrateLocalized($rawMeta['badge'] ?? null);
                        $data['description_i18n'] = $this->hydrateLocalized($rawMeta['description'] ?? null);

                        return $data;
                    })
                    ->using(fn (MenuItem $record, array $data): Model => app('cms.menu')->updateItem($record, $this->withLocalizedMeta($data))),

                DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /**
     * Options for the reference_id select, depending on the item type.
     *
     * @return array<int, string>
     */
    private static function referenceOptions(string $type): array
    {
        return match ($type) {
            'page' => self::contentOptions('page'),
            'post' => self::contentOptions('post'),
            'category' => self::termOptions('category'),
            'tag' => self::termOptions('tag'),
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private static function contentOptions(string $type): array
    {
        return Content::query()
            ->where('type', $type)
            ->with(['translations' => fn ($q) => $q->where('locale', editing_locale())])
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(static fn (Content $content): array => [
                $content->id => $content->translatedTitle(editing_locale()),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function termOptions(string $taxonomySlug): array
    {
        $taxonomyId = Taxonomy::query()
            ->where('content_type', 'post')
            ->where('slug', $taxonomySlug)
            ->value('id');

        if ($taxonomyId === null) {
            return [];
        }

        return Term::query()
            ->where('taxonomy_id', $taxonomyId)
            ->with(['translations' => fn ($q) => $q->where('locale', editing_locale())])
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(static fn (Term $term): array => [
                $term->id => $term->displayName(editing_locale()),
            ])
            ->all();
    }

    /**
     * Sibling items in this menu, excluding the item being edited. Labels use
     * the selected locale title, falling back to the default locale title.
     *
     * @return array<int, string>
     */
    private function parentOptions(?MenuItem $record): array
    {
        $locale = $this->selectedLocale();

        return $this->getOwnerRecord()
            ->items()
            ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(static fn (MenuItem $item): array => [
                $item->id => $item->displayTitle($locale),
            ])
            ->all();
    }

    /**
     * The menu edit locale: the captured ?locale= value, resolving once on
     * first access (it is persisted on the component after mount).
     */
    private function selectedLocale(): string
    {
        return $this->editLocale ??= $this->resolveSelectedLocale();
    }

    /**
     * Resolve the CONTENT EDITING locale (`?locale=` → session → default) via
     * the locale preference manager. Independent of the admin UI locale
     * (`?lang`); see {@see \TheNguyen\CMS\Services\LocalePreferenceManager}.
     */
    private function resolveSelectedLocale(): string
    {
        return editing_locale();
    }

    /**
     * Human label for the selected locale (e.g. "English (en)").
     */
    private function localeLabel(): string
    {
        $code = $this->selectedLocale();
        $language = app('cms.language')->find($code);

        return $language?->label() ?? strtoupper($code);
    }

    /**
     * Title for the relation manager table: the selected locale title, else the
     * default locale title flagged "(default)".
     */
    private function tableTitle(MenuItem $record): string
    {
        $locale = $this->selectedLocale();
        // translations are eager-loaded by the table query (modifyQueryUsing).
        $title = $record->translations->firstWhere('locale', $locale)?->title;

        if (is_string($title) && $title !== '') {
            return $title;
        }

        $defaultLocale = app('cms.language')->defaultCode();
        $fallback = $record->displayTitle($defaultLocale);

        return $locale !== $defaultLocale
            ? tn_trans(':title (default)', ['title' => $fallback])
            : $fallback;
    }

    /**
     * One input per active locale for a localized meta field (badge /
     * description), bound to `{$field}_i18n.{locale}`. A single-locale site gets
     * a single field. Intentionally minimal — per-locale inputs saved into the
     * meta JSON as a locale map, not a translation-management system.
     *
     * @return array<int, \Filament\Forms\Components\Field>
     */
    private function localizedInputs(string $field, string $label, int $maxLength, bool $textarea = false): array
    {
        $locales = app('cms.language')->active();
        $multi = $locales->count() > 1;

        return $locales->map(function ($language) use ($field, $label, $maxLength, $textarea, $multi) {
            $code = (string) $language->code;
            $name = $field.'_i18n.'.$code;
            $fieldLabel = $multi ? sprintf('%s (%s)', $label, strtoupper($code)) : $label;

            $component = $textarea
                ? Textarea::make($name)->rows(2)
                : TextInput::make($name);

            return $component->label($fieldLabel)->maxLength($maxLength);
        })->all();
    }

    /**
     * Collapse the per-locale `{field}_i18n` inputs into the scalar-or-map
     * `badge`/`description` payload the MenuManager stores in meta: trimmed,
     * empty locales dropped, and null when nothing remains (so it clears).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withLocalizedMeta(array $data): array
    {
        $data['badge'] = $this->collapseLocalized($data['badge_i18n'] ?? null);
        $data['description'] = $this->collapseLocalized($data['description_i18n'] ?? null);

        unset($data['badge_i18n'], $data['description_i18n']);

        return $data;
    }

    /**
     * @return array<string, string>|null
     */
    private function collapseLocalized(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $code => $text) {
            if (is_string($text) && trim($text) !== '') {
                $out[(string) $code] = trim($text);
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Hydrate per-locale form inputs from a stored badge/description value: a
     * locale map fills each locale; a legacy scalar seeds the default locale.
     *
     * @return array<string, string>
     */
    private function hydrateLocalized(mixed $stored): array
    {
        if (is_array($stored)) {
            $out = [];

            foreach ($stored as $code => $text) {
                if (is_string($text)) {
                    $out[(string) $code] = $text;
                }
            }

            return $out;
        }

        if (is_string($stored) && $stored !== '') {
            return [app('cms.language')->defaultCode() => $stored];
        }

        return [];
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return tn_trans('Menu Items');
    }
}
