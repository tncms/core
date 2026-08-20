<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CategoryResource\Pages;
use BackedEnum;

/**
 * Categories: the core HIERARCHICAL post taxonomy. All hierarchy behavior
 * (parent selector, tree list, featured image, loop guards) comes from
 * {@see AbstractTaxonomyResource} driven by the taxonomy's `hierarchical`
 * flag — nothing here is category-specific beyond navigation chrome.
 */
class CategoryResource extends AbstractTaxonomyResource
{
    protected static ?string $slug = 'categories';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'Category';

    protected static ?string $pluralModelLabel = 'Categories';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationParentItem = 'Posts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 21;

    protected static function taxonomySlug(): string
    {
        return 'category';
    }

    protected static function contentType(): string
    {
        return 'post';
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Categories');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Categories');
    }

    // Match the (translated) Posts navigation label so this stays nested under it.
    public static function getNavigationParentItem(): ?string
    {
        return tn_trans('Posts');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
