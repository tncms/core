<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Components\RichEditor;
use App\Filament\Admin\Resources\PostResource\Pages;
use App\Filament\Admin\Resources\PostResource\Support\PostLocalizedContent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Taxonomy;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Translation\Admin\FallbackPreviewResult;

class PostResource extends Resource
{
    use HasTranslations;

    protected static ?string $model = Content::class;

    protected static ?string $slug = 'posts';

    protected static ?string $navigationLabel = 'Posts';

    protected static ?string $modelLabel = 'Post';

    protected static ?string $pluralModelLabel = 'Posts';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Posts');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Post');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Posts');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'post')
            ->with(['translations' => fn ($q) => $q->where('locale', editing_locale())]);
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Group::make([
                Section::make(tn_trans('Post'))
                    ->schema([
                        static::translationLocaleField(),

                        TextInput::make('title')
                            ->label(tn_trans('Title'))
                            // Default locale required; secondary locales optional (Translation Platform policy).
                            ->markAsRequired(fn (Get $get): bool => PostLocalizedContent::isRequired('title', self::formLocale($get)))
                            ->rules(fn (Get $get): array => [PostLocalizedContent::validationRule('title', self::formLocale($get))])
                            ->maxLength(255)
                            ->live(onBlur: true),

                        self::fallbackPreviewField('title'),

                        TextInput::make('slug')
                            ->label(tn_trans('Slug'))
                            ->helperText(tn_trans('Language-specific. Leave empty to auto-generate from the title; changing it affects only the selected language.'))
                            // Live preview of the locale-aware slug that will be generated from the title (no write).
                            ->placeholder(fn (Get $get): ?string => PostLocalizedContent::generateSlug($get('title'), self::formLocale($get)))
                            ->rules(fn (Get $get): array => [PostLocalizedContent::validationRule('slug', self::formLocale($get))])
                            ->maxLength(255),

                        Textarea::make('excerpt')
                            ->label(tn_trans('Excerpt'))
                            ->rows(3),

                        self::fallbackPreviewField('excerpt'),

                        RichEditor::make('content')
                            ->label(tn_trans('Content')),

                        self::fallbackPreviewField('content'),
                    ])
                    ->columns(1),

                Section::make(tn_trans('SEO'))
                    ->schema([
                        TextInput::make('meta_title')
                            ->label(tn_trans('Meta title'))
                            ->maxLength(255),

                        self::fallbackPreviewField('meta_title'),

                        Textarea::make('meta_description')
                            ->label(tn_trans('Meta description'))
                            ->rows(2),

                        self::fallbackPreviewField('meta_description'),

                        Textarea::make('meta_keywords')
                            ->label(tn_trans('Meta keywords'))
                            ->rows(2),
                    ])
                    ->columns(1)
                    ->collapsed(),
            ])
                ->columnSpan([
                    'default' => 12,
                    'xl' => 8,
                ]),

            Group::make([
                Section::make(tn_trans('Publish'))
                    ->schema([
                        Select::make('status')
                            ->label(tn_trans('Status'))
                            ->options([
                                'draft' => tn_trans('Draft'),
                                'published' => tn_trans('Published'),
                                'pending' => tn_trans('Pending'),
                                'private' => tn_trans('Private'),
                            ])
                            ->default(fn (): string => (string) settings('writing.default_post_status', 'draft'))
                            ->required(),

                        Select::make('comment_status')
                            ->label(tn_trans('Comment status'))
                            ->options([
                                'open' => tn_trans('Open'),
                                'closed' => tn_trans('Closed'),
                            ])
                            ->default(fn (): string => (string) settings('writing.default_comment_status', 'open'))
                            ->required(),

                        DateTimePicker::make('published_at')
                            ->label(tn_trans('Publish date')),

                        Toggle::make('is_featured')
                            ->label(tn_trans('Featured post'))
                            ->helperText(tn_trans('Surface this post in featured sections and "Featured" data sources.')),

                        MediaPicker::make(),
                    ])
                    ->columns(1),

                Section::make(tn_trans('Metrics'))
                    ->schema([
                        TextInput::make('views_count')
                            ->label(tn_trans('Views'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(tn_trans('Editable for backfill/migration. Normally maintained automatically.')),

                        TextInput::make('comments_count')
                            ->label(tn_trans('Comments'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->helperText(tn_trans('Editable for backfill/migration. Normally maintained automatically.')),
                    ])
                    ->columns(1)
                    ->collapsed(),

                Section::make(tn_trans('Categories'))
                    ->schema([
                        CheckboxList::make('category_ids')
                            ->hiddenLabel()
                            ->options(static fn (Get $get): array => self::categoryOptions(self::formLocale($get)))
                            ->default(static fn (Get $get): array => self::defaultCategoryIds(self::formLocale($get)))
                            ->bulkToggleable()
                            ->columns(1),
                    ])
                    ->footerActions([
                        Action::make('addCategory')
                            ->label(tn_trans('+ Add New Category'))
                            ->link()
                            ->size('sm')
                            ->url(fn (): string => CategoryResource::getUrl('create'))
                            ->openUrlInNewTab(),
                    ])
                    ->collapsible(),

                Section::make(tn_trans('Tags'))
                    ->schema([
                        TagsInput::make('tag_names')
                            ->hiddenLabel()
                            ->placeholder(tn_trans('Type a tag and press Enter'))
                            ->helperText(tn_trans('New tags are created automatically. Separate tags with commas.'))
                            ->separator(',')
                            ->reorderable(false),
                    ])
                    ->collapsible(),
            ])
                ->columnSpan([
                    'default' => 12,
                    'xl' => 4,
                ]),
        ];

        // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2): let plugins reshape the
        // schema, then append any contributed regions full-width beneath it.
        return $schema
            ->columns(12)
            ->components([
                ...tn_form_schema($components, Content::class, 'post'),
                ...tn_form_regions([], Content::class, 'post'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(tn_trans('Title'))
                    ->state(fn (Content $record): string => $record->translatedTitle(editing_locale()))
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        return $query->whereHas('translations', function (Builder $q) use ($search): void {
                            $q->where('locale', editing_locale())
                                ->where(function (Builder $inner) use ($search): void {
                                    $inner->where('title', 'like', "%{$search}%")
                                        ->orWhere('slug', 'like', "%{$search}%");
                                });
                        });
                    }),

                TextColumn::make('slug')
                    ->label(tn_trans('Slug'))
                    ->state(fn (Content $record): string => $record->translatedSlug(editing_locale()))
                    ->toggleable(),

                BadgeColumn::make('status')
                    ->label(tn_trans('Status'))
                    ->formatStateUsing(fn (string $state): string => tn_trans(ucfirst($state)))
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'published',
                        'warning' => 'pending',
                        'info' => 'private',
                    ]),

                TextColumn::make('published_at')
                    ->label(tn_trans('Published at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label(tn_trans('Updated at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Preview (CORE-L10N.1B P3.1). ONE locale-aware producer for
                // published + draft alike: cms.preview_url resolves the public
                // localized URL for a published/translated record, else a signed
                // preview URL, never '#'. From the list there is no editing
                // context, so the site default locale is the intended target.
                // Always in a new tab. Hidden if core preview is disabled.
                Action::make('preview')
                    ->label(tn_trans('Preview'))
                    ->icon('heroicon-o-eye')
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => app()->bound('cms.preview') && app('cms.preview')->enabled())
                    ->url(fn (Content $record): ?string => app('cms.preview_url')->forContent($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return cms_can('posts.view');
    }

    public static function canCreate(): bool
    {
        return cms_can('posts.create');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('posts.edit');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('posts.delete');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit' => Pages\EditPost::route('/{record}/edit'),
        ];
    }

    /**
     * Build the category checkbox options keyed by term id.
     *
     * @return array<int, string>
     */
    public static function categoryOptions(string $locale): array
    {
        $taxonomyId = Taxonomy::query()
            ->where('content_type', 'post')
            ->where('slug', 'category')
            ->value('id');

        if ($taxonomyId === null) {
            return [];
        }

        // Strict locale: only categories that have a non-empty translation in
        // the editing locale appear. Never fall back to another locale's name.
        return Term::query()
            ->where('taxonomy_id', $taxonomyId)
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->where('name', '!=', ''))
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(static fn (Term $term): array => [
                $term->id => (string) $term->localeName($locale),
            ])
            ->filter(static fn (string $name): bool => $name !== '')
            ->all();
    }

    /**
     * Pre-select the default category on new posts, from
     * writing.default_category_id when it points at an existing category term.
     *
     * @return array<int, int>
     */
    public static function defaultCategoryIds(string $locale): array
    {
        $id = (int) settings('writing.default_category_id', 0);

        if ($id <= 0) {
            return [];
        }

        return array_key_exists($id, self::categoryOptions($locale)) ? [$id] : [];
    }

    /**
     * Resolve form data into a list of term IDs ready for ContentManager.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * Build the full term_ids set the content sync will persist.
     *
     * `$preservedTermIds` carries assignments whose term has no translation in
     * the editing locale: they are invisible to (and thus unmanageable from)
     * this locale's editor, so they are merged back in to avoid silently
     * dropping a foreign-locale term when the content terms are fully synced.
     *
     * @param  array<int, int>  $preservedTermIds
     */
    public static function mergeTermIds(array $data, array $preservedTermIds = []): array
    {
        $locale = $data['locale'] ?? app('cms.language')->defaultCode();

        $categoryIds = self::normalizeCategoryIds($data['category_ids'] ?? null);
        $tagNames = self::normalizeTagNames($data['tag_names'] ?? null);

        unset($data['category_ids'], $data['tag_names']);

        $tagIds = self::resolveTagIds($tagNames, $locale);
        $preserved = array_map('intval', $preservedTermIds);

        $data['term_ids'] = array_values(array_unique([...$categoryIds, ...$tagIds, ...$preserved]));

        return $data;
    }

    /**
     * Resolve the locale used by the post's taxonomy form fields.
     *
     * Mirrors the page's selectedLocale(): the form `locale` field is seeded
     * from selectedLocale() on fill (and the language select on create), so it
     * is the single source of truth here. Falls back to the default language.
     */
    private static function formLocale(Get $get): string
    {
        $language = app('cms.language');
        $locale = $get('locale');

        if (is_string($locale) && $locale !== '' && $language->isActive($locale)) {
            return $language->normalizeCode($locale);
        }

        return $language->defaultCode();
    }

    /**
     * Posts-scoped override of the shared locale field so its options come from
     * the LocaleRegistry (via the Translation Platform) ordered default-first,
     * with no hardcoded locales. Pages/Terms keep the shared trait behaviour.
     */
    protected static function translationLocaleField(): Select
    {
        return Select::make('locale')
            ->label(tn_trans('Language'))
            ->options(fn (): array => PostLocalizedContent::localeOptions())
            ->default(fn (): string => static::defaultTranslationLocale())
            ->required()
            ->hiddenOn('edit')
            ->helperText(tn_trans('The language this content belongs to.'));
    }

    /**
     * A read-only fallback preview for one localized field. Shows what the
     * frontend would inherit when the current locale has no value; it is never
     * dehydrated, never persisted, and never satisfies required validation.
     * Hidden on create (no record) and whenever the current locale is not
     * falling back.
     */
    private static function fallbackPreviewField(string $field): Placeholder
    {
        $placeholder = Placeholder::make($field.'_fallback')
            ->hiddenLabel()
            ->visible(fn (Get $get, ?Content $record): bool => self::fallbackPreviewResult($record, $field, $get)?->isFallback === true)
            ->content(fn (Get $get, ?Content $record): ?HtmlString => self::fallbackPreviewContent($record, $field, $get));

        if (method_exists($placeholder, 'dehydrated')) {
            $placeholder->dehydrated(false);
        }

        return $placeholder;
    }

    private static function fallbackPreviewResult(?Content $record, string $field, Get $get): ?FallbackPreviewResult
    {
        if (! $record instanceof Content) {
            return null;
        }

        return PostLocalizedContent::fallbackPreview(
            $record,
            $field,
            self::formLocale($get),
            is_string($value = $get($field)) ? $value : null,
        );
    }

    private static function fallbackPreviewContent(?Content $record, string $field, Get $get): ?HtmlString
    {
        $result = self::fallbackPreviewResult($record, $field, $get);

        if ($result === null || ! $result->isFallback || ! $result->found) {
            return null;
        }

        $sourceLabel = $result->sourceLocale !== null
            ? PostLocalizedContent::locales()->labelFor($result->sourceLocale)
            : '';

        $preview = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $result->value)));
        if (mb_strlen($preview) > 160) {
            $preview = mb_substr($preview, 0, 160).'…';
        }

        $note = trim(tn_trans('Inherited from').' '.$sourceLabel);

        return new HtmlString(
            '<span class="text-sm text-gray-500 dark:text-gray-400">'
            .e($note).($preview !== '' ? ' — '.e($preview) : '')
            .'</span>'
        );
    }

    /**
     * Map a post's term assignments into locale-aware form state.
     *
     * Strict locale: only categories/tags translated in $locale are exposed as
     * editable fields. Assignments whose term has no translation in $locale are
     * returned under `preserved` so a save in this locale re-attaches them.
     *
     * @return array{category_ids: array<int, int>, tag_names: array<int, string>, preserved: array<int, int>}
     */
    public static function termsForForm(Content $content, string $locale): array
    {
        $categoryTaxonomyId = Taxonomy::query()
            ->where('content_type', 'post')
            ->where('slug', 'category')
            ->value('id');

        $tagTaxonomyId = Taxonomy::query()
            ->where('content_type', 'post')
            ->where('slug', 'tag')
            ->value('id');

        $terms = $content->terms()
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->get();

        $categoryIds = [];
        $tagNames = [];
        $preserved = [];

        foreach ($terms as $term) {
            $taxonomyId = (int) $term->taxonomy_id;
            $isCategory = $categoryTaxonomyId !== null && $taxonomyId === (int) $categoryTaxonomyId;
            $isTag = $tagTaxonomyId !== null && $taxonomyId === (int) $tagTaxonomyId;

            if (! $isCategory && ! $isTag) {
                continue;
            }

            $name = $term->localeName($locale);

            // No translation in the editing locale: hide it, but keep it so the
            // full term sync on save does not drop the foreign-locale term.
            if ($name === null) {
                $preserved[] = (int) $term->id;

                continue;
            }

            if ($isCategory) {
                $categoryIds[] = (int) $term->id;

                continue;
            }

            $tagNames[] = $name;
        }

        return [
            'category_ids' => $categoryIds,
            'tag_names' => $tagNames,
            'preserved' => $preserved,
        ];
    }

    /**
     * Normalize the tags input into a clean list of tag names.
     *
     * Filament/Livewire may hand `tag_names` over as an array (TagsInput) or
     * as a raw comma-separated string. Both must be handled without crashing.
     *
     * @return array<int, string>
     */
    private static function normalizeTagNames(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $names = array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        );

        return array_values(array_filter(
            $names,
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Normalize the category selection into a clean list of positive int IDs.
     *
     * @return array<int, int>
     */
    private static function normalizeCategoryIds(mixed $value): array
    {
        if (! is_array($value)) {
            $value = ($value === null || $value === '') ? [] : [$value];
        }

        $ids = array_map('intval', $value);

        return array_values(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0,
        ));
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private static function resolveTagIds(array $names, string $locale): array
    {
        if ($names === []) {
            return [];
        }

        /** @var TaxonomyManager $taxonomies */
        $taxonomies = app('cms.taxonomy');
        $slugManager = app('cms.slug');

        $tagTaxonomyId = Taxonomy::query()
            ->where('content_type', 'post')
            ->where('slug', 'tag')
            ->value('id');

        if ($tagTaxonomyId === null) {
            $taxonomies->ensureCoreTaxonomies();

            $tagTaxonomyId = Taxonomy::query()
                ->where('content_type', 'post')
                ->where('slug', 'tag')
                ->value('id');
        }

        $resolved = [];

        foreach ($names as $name) {
            $existing = Term::query()
                ->where('taxonomy_id', $tagTaxonomyId)
                ->whereHas('translations', function ($q) use ($name, $locale): void {
                    $q->where('locale', $locale)->where('name', $name);
                })
                ->first();

            if ($existing) {
                $resolved[] = $existing->id;

                continue;
            }

            $slug = $slugManager->generate($name, $locale);
            $bySlug = $taxonomies->findTermBySlug($slug, $locale, 'tag');

            if ($bySlug) {
                $resolved[] = $bySlug->id;

                continue;
            }

            $term = $taxonomies->createTerm('tag', [
                'locale' => $locale,
                'name' => $name,
            ]);

            $resolved[] = $term->id;
        }

        return $resolved;
    }
}
