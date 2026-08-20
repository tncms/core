<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Components\RichEditor;
use App\Filament\Admin\Concerns\HasLocaleSelect;
use App\Filament\Admin\Resources\AbstractTaxonomyResource\Support\TaxonomyLocalizedContent;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Models\Taxonomy;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Translation\Admin\FallbackPreviewResult;

/**
 * Shared admin resource for ANY taxonomy's terms.
 *
 * This is the reusable half of the taxonomy framework on the admin side: the
 * form and table are built once here and driven by the taxonomy's own
 * `hierarchical` flag — never by a hardcoded "category" check. A subclass only
 * declares which taxonomy it edits (slug + content type) plus its navigation
 * chrome; a future Product / Documentation / Forum category resource needs
 * nothing more than extending this class and registering a hierarchical
 * taxonomy row.
 *
 * Hierarchy-only fields (parent selector, featured image) appear automatically
 * when {@see isHierarchical()} is true, so the flat Tag resource reuses the
 * exact same code path with those fields hidden.
 */
abstract class AbstractTaxonomyResource extends Resource
{
    use HasLocaleSelect;
    use HasTranslations;

    protected static ?string $model = Term::class;

    /** Per-request memo of id => tree depth, keyed by taxonomy id. */
    private static array $depthMaps = [];

    /** The taxonomy slug this resource manages (e.g. "category", "tag"). */
    abstract protected static function taxonomySlug(): string;

    /** The content type the taxonomy is attached to (e.g. "post"). */
    abstract protected static function contentType(): string;

    public static function taxonomyId(): ?int
    {
        return Taxonomy::query()
            ->where('content_type', static::contentType())
            ->where('slug', static::taxonomySlug())
            ->value('id');
    }

    /**
     * Whether the managed taxonomy is hierarchical. Single source of truth for
     * showing the parent selector, featured image, and indented tree list.
     */
    protected static function isHierarchical(): bool
    {
        $id = static::taxonomyId();

        return $id !== null && (bool) Taxonomy::query()->whereKey($id)->value('hierarchical');
    }

    /**
     * The locale the list view renders labels/slugs in. Follows the locale the
     * admin is actually viewing (the `?locale=` switcher → current locale →
     * default) via {@see HasLocaleSelect::viewingLocale()} — NOT the site
     * default — so switching language updates the term list instead of always
     * showing the default language's labels.
     */
    protected static function displayLocale(): string
    {
        return static::viewingLocale();
    }

    public static function getEloquentQuery(): Builder
    {
        $taxonomyId = static::taxonomyId();
        $locale = static::displayLocale();

        return parent::getEloquentQuery()
            ->when($taxonomyId, fn (Builder $q) => $q->where('taxonomy_id', $taxonomyId))
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)]);
    }

    public static function form(Schema $schema): Schema
    {
        $hierarchical = static::isHierarchical();

        $fields = [
            static::translationLocaleField(),

            // Name: required in the DEFAULT locale only (secondary optional), and
            // validated through the Translation Platform with locale-aware messages
            // (Phase 9.2F). The visual asterisk tracks the active locale.
            TextInput::make('name')
                ->label(tn_trans('Name'))
                ->maxLength(255)
                ->markAsRequired(fn (Get $get): bool => TaxonomyLocalizedContent::isRequired('name', static::formLocale($get)))
                ->rules(fn (Get $get): array => [TaxonomyLocalizedContent::validationRule('name', static::formLocale($get))]),
            static::fallbackPreviewField('name'),

            TextInput::make('slug')
                ->label(tn_trans('Slug'))
                ->helperText(tn_trans('Language-specific. Leave empty to auto-generate from the name; changing it affects only the selected language.'))
                ->maxLength(255)
                // Preview only — SlugManager/TaxonomyManager still own generation,
                // uniqueness and cms_slugs. No write happens here.
                ->placeholder(fn (Get $get): ?string => TaxonomyLocalizedContent::generateSlug($get('name'), static::formLocale($get)))
                ->rules(fn (Get $get): array => [TaxonomyLocalizedContent::validationRule('slug', static::formLocale($get))]),
        ];

        if ($hierarchical) {
            // Parent and featured image are GLOBAL (not per-locale) and only exist
            // for hierarchical taxonomies. The parent options are an indented tree
            // excluding the term itself and its descendants (loop-safe by design).
            $fields[] = Select::make('parent_id')
                ->label(tn_trans('Parent'))
                ->options(fn (?Model $record): array => static::parentOptions($record instanceof Term ? $record : null))
                ->searchable()
                ->native(false)
                ->placeholder(tn_trans('— None (top level) —'))
                ->helperText(tn_trans('Optional. Build a tree by nesting under another term. Global across languages.'));
        }

        // Rich description replaces the old plain textarea; stored HTML is
        // sanitized server-side on save (TaxonomyManager).
        $fields[] = RichEditor::make('description')
            ->label(tn_trans('Description'))
            ->height(260);
        $fields[] = static::fallbackPreviewField('description');

        if ($hierarchical) {
            $fields[] = MediaPicker::make('featured_image', tn_trans('Featured image'));
        }

        $components = [
            Section::make(static::getModelLabel())
                ->schema($fields)
                ->columns(1),

            Section::make(tn_trans('SEO'))
                ->schema([
                    TextInput::make('meta_title')
                        ->label(tn_trans('Meta title'))
                        ->maxLength(255)
                        ->rules(fn (Get $get): array => [TaxonomyLocalizedContent::validationRule('meta_title', static::formLocale($get))]),
                    static::fallbackPreviewField('meta_title'),
                    Textarea::make('meta_description')
                        ->label(tn_trans('Meta description'))
                        ->rows(3),
                    static::fallbackPreviewField('meta_description'),
                ])
                ->columns(1)
                ->collapsed(),
        ];

        // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2): plugins may reshape the
        // taxonomy schema and append regions (alias "term" for category + tag).
        return $schema
            ->components([
                ...tn_form_schema($components, Term::class, 'term'),
                ...tn_form_regions([], Term::class, 'term'),
            ]);
    }

    public static function table(Table $table): Table
    {
        $hierarchical = static::isHierarchical();
        $locale = static::displayLocale();

        $columns = [
            TextColumn::make('name')
                ->label(tn_trans('Name'))
                ->state(function (Term $record) use ($hierarchical, $locale): string {
                    $name = $record->displayName($locale);

                    // Indent by tree depth so the flat (paginated) list still
                    // reads as a hierarchy without a JS tree component.
                    if ($hierarchical) {
                        $depth = static::depthFor((int) $record->getKey());

                        if ($depth > 0) {
                            return str_repeat('— ', $depth).$name;
                        }
                    }

                    return $name;
                })
                ->searchable(query: static function (Builder $query, string $search) use ($locale): Builder {
                    return $query->whereHas('translations', function (Builder $q) use ($search, $locale): void {
                        $q->where('locale', $locale)
                            ->where(function (Builder $inner) use ($search): void {
                                $inner->where('name', 'like', "%{$search}%")
                                    ->orWhere('slug', 'like', "%{$search}%");
                            });
                    });
                }),
            TextColumn::make('slug')
                ->label(tn_trans('Slug'))
                ->state(fn (Term $record): string => $record->translatedSlug($locale))
                ->toggleable(),
        ];

        if ($hierarchical) {
            $columns[] = TextColumn::make('parent')
                ->label(tn_trans('Parent'))
                ->state(fn (Term $record): string => $record->parent?->displayName($locale) ?? '—')
                ->toggleable();
        }

        $columns[] = TextColumn::make('count')
            ->label(tn_trans('Posts'))
            ->sortable();
        $columns[] = TextColumn::make('updated_at')
            ->label(tn_trans('Updated at'))
            ->dateTime()
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);

        return $table
            ->columns($columns)
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /**
     * Indented parent options for the Select, excluding $record and its whole
     * subtree so the UI can never offer a choice that would create a loop.
     *
     * @return array<int, string>
     */
    protected static function parentOptions(?Term $record): array
    {
        $taxonomyId = static::taxonomyId();

        if ($taxonomyId === null) {
            return [];
        }

        $locale = static::displayLocale();
        $options = [];

        foreach (app('cms.taxonomy')->treeOrderedTerms($taxonomyId, $record?->getKey()) as $node) {
            /** @var Term $term */
            $term = $node['term'];
            $options[(int) $term->id] = str_repeat('— ', $node['depth']).$term->displayName($locale);
        }

        return $options;
    }

    /**
     * Tree depth for a term id, memoized per request/taxonomy so the table does
     * not rebuild the tree for every row.
     */
    protected static function depthFor(int $termId): int
    {
        $taxonomyId = static::taxonomyId();

        if ($taxonomyId === null) {
            return 0;
        }

        if (! isset(self::$depthMaps[$taxonomyId])) {
            $map = [];

            foreach (app('cms.taxonomy')->treeOrderedTerms($taxonomyId) as $node) {
                $map[(int) $node['term']->id] = $node['depth'];
            }

            self::$depthMaps[$taxonomyId] = $map;
        }

        return self::$depthMaps[$taxonomyId][$termId] ?? 0;
    }

    /**
     * Taxonomy-scoped override of the shared locale field so its options come from
     * the Translation Platform ordered default-first, with no hardcoded locales
     * (Phase 9.2F). Posts/Pages keep their own behaviour; the shared trait is
     * untouched. Generic across every taxonomy — no concrete-type branching.
     */
    protected static function translationLocaleField(): Select
    {
        return Select::make('locale')
            ->label(tn_trans('Language'))
            ->options(fn (): array => TaxonomyLocalizedContent::localeOptions())
            ->default(fn (): string => static::defaultTranslationLocale())
            ->required()
            ->hiddenOn('edit')
            ->helperText(tn_trans('The language this term belongs to.'));
    }

    /**
     * The locale the form is currently editing. The `locale` field is seeded from
     * the switcher/selected locale on fill (and the language select on create), so
     * it is the single source of truth. Falls back to the default language.
     */
    protected static function formLocale(Get $get): string
    {
        $language = app('cms.language');
        $locale = $get('locale');

        if (is_string($locale) && $locale !== '' && $language->isActive($locale)) {
            return $language->normalizeCode($locale);
        }

        return $language->defaultCode();
    }

    /**
     * A read-only fallback preview for one localized field. Shows what the frontend
     * would inherit when the current locale has no value; it is never dehydrated,
     * never persisted, and never satisfies required validation. Hidden on create
     * (no record) and whenever the current locale is not falling back.
     */
    protected static function fallbackPreviewField(string $field): Placeholder
    {
        $placeholder = Placeholder::make($field.'_fallback')
            ->hiddenLabel()
            ->visible(fn (Get $get, ?Model $record): bool => static::fallbackPreviewResult($record instanceof Term ? $record : null, $field, $get)?->isFallback === true)
            ->content(fn (Get $get, ?Model $record): ?HtmlString => static::fallbackPreviewContent($record instanceof Term ? $record : null, $field, $get));

        if (method_exists($placeholder, 'dehydrated')) {
            $placeholder->dehydrated(false);
        }

        return $placeholder;
    }

    private static function fallbackPreviewResult(?Term $record, string $field, Get $get): ?FallbackPreviewResult
    {
        if (! $record instanceof Term) {
            return null;
        }

        return TaxonomyLocalizedContent::fallbackPreview(
            $record,
            $field,
            static::formLocale($get),
            is_string($value = $get($field)) ? $value : null,
        );
    }

    private static function fallbackPreviewContent(?Term $record, string $field, Get $get): ?HtmlString
    {
        $result = static::fallbackPreviewResult($record, $field, $get);

        if ($result === null || ! $result->isFallback || ! $result->found) {
            return null;
        }

        $sourceLabel = $result->sourceLocale !== null
            ? TaxonomyLocalizedContent::locales()->labelFor($result->sourceLocale)
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

    public static function canViewAny(): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return cms_can('taxonomy.manage');
    }
}
