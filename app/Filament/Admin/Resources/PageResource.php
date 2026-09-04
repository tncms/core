<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Components\RichEditor;
use App\Filament\Admin\Resources\PageResource\Pages;
use App\Filament\Admin\Resources\PageResource\Support\PageLocalizedContent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use TheNguyen\CMS\Translation\Admin\FallbackPreviewResult;

class PageResource extends Resource
{
    use HasTranslations;

    protected static ?string $model = Content::class;

    protected static ?string $slug = 'pages';

    protected static ?string $navigationLabel = 'Pages';

    protected static ?string $modelLabel = 'Page';

    protected static ?string $pluralModelLabel = 'Pages';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Pages');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Page');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Pages');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', 'page')
            ->with(['translations' => fn ($q) => $q->where('locale', editing_locale())]);
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Group::make([
                Section::make(tn_trans('Page'))
                    ->schema([
                        static::translationLocaleField(),

                        TextInput::make('title')
                            ->label(tn_trans('Title'))
                            // Default locale required; secondary locales optional (Translation Platform policy).
                            ->markAsRequired(fn (Get $get): bool => PageLocalizedContent::isRequired('title', self::formLocale($get)))
                            ->rules(fn (Get $get): array => [PageLocalizedContent::validationRule('title', self::formLocale($get))])
                            ->maxLength(255)
                            ->live(onBlur: true),

                        self::fallbackPreviewField('title'),

                        TextInput::make('slug')
                            ->label(tn_trans('Slug'))
                            ->helperText(tn_trans('Language-specific. Leave empty to auto-generate from the title; changing it affects only the selected language.'))
                            // Live preview of the locale-aware slug that will be generated from the title (no write).
                            ->placeholder(fn (Get $get): ?string => PageLocalizedContent::generateSlug($get('title'), self::formLocale($get)))
                            ->rules(fn (Get $get): array => [PageLocalizedContent::validationRule('slug', self::formLocale($get))])
                            ->maxLength(255),

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
                            ->default('draft')
                            ->required(),

                        DateTimePicker::make('published_at')
                            ->label(tn_trans('Publish date')),

                        // Active-theme page templates (CORE-THEME-2): a validated
                        // Select over the active theme's declared allowlist. The
                        // stored value is a stable identifier — never a Blade
                        // path — and a legacy value from another theme is shown
                        // as unavailable instead of being silently discarded.
                        Select::make('template')
                            ->label(tn_trans('Template'))
                            ->options(fn (?Content $record) => static::pageTemplateOptions($record))
                            ->placeholder(tn_trans('Default (theme page layout)'))
                            ->nullable()
                            ->visible(fn (?Content $record) => static::pageTemplateOptions($record) !== [])
                            ->helperText(tn_trans('Layout provided by the active theme.'))
                            ->rules([
                                fn (?Content $record) => static function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    $value = trim((string) $value);

                                    if ($value === '') {
                                        return;
                                    }

                                    if (isset(app('cms.page_templates')->templatesFor()[$value])) {
                                        return;
                                    }

                                    // Preserve (don't destroy) a stored identifier
                                    // that the current theme no longer declares.
                                    if ($record !== null && trim((string) $record->template) === $value) {
                                        return;
                                    }

                                    $fail(tn_trans('The selected template is not available in the active theme.'));
                                },
                            ]),

                        MediaPicker::make(),
                    ])
                    ->columns(1),
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
                ...tn_form_schema($components, Content::class, 'page'),
                ...tn_form_regions([], Content::class, 'page'),
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
        return cms_can('pages.view');
    }

    public static function canCreate(): bool
    {
        return cms_can('pages.create');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('pages.edit');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('pages.delete');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }

    /**
     * Resolve the locale the localized page fields edit.
     *
     * The form `locale` field is seeded from selectedLocale() on fill (and the
     * language select on create), so it is the single source of truth here.
     * Falls back to the default language.
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
     * Pages-scoped override of the shared locale field so its options come from
     * the Translation Platform ordered default-first, with no hardcoded locales.
     * Posts/Terms keep their own behaviour; the shared trait is untouched.
     */
    protected static function translationLocaleField(): Select
    {
        return Select::make('locale')
            ->label(tn_trans('Language'))
            ->options(fn (): array => PageLocalizedContent::localeOptions())
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

        return PageLocalizedContent::fallbackPreview(
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
            ? PageLocalizedContent::locales()->labelFor($result->sourceLocale)
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
     * Options for the Template select (CORE-THEME-2): the active theme's
     * validated declarations only, plus — for an existing record — a stored
     * identifier the current theme no longer declares, labelled as
     * unavailable so the editor can pick a valid replacement without the
     * value being silently destroyed. Never exposes filesystem paths.
     *
     * @return array<string, string>
     */
    protected static function pageTemplateOptions(?Content $record): array
    {
        $options = [];

        foreach (app('cms.page_templates')->templatesFor() as $id => $template) {
            $options[$id] = theme_trans($template->label);
        }

        $current = trim((string) ($record?->template ?? ''));

        if ($current !== '' && ! isset($options[$current])) {
            $options[$current] = $current.' — '.tn_trans('Unavailable in the active theme');
        }

        return $options;
    }
}
