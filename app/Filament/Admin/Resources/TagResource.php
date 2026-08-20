<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\TagResource\Pages;
use BackedEnum;

/**
 * Tags: the core FLAT post taxonomy. It reuses the very same
 * {@see AbstractTaxonomyResource} as Categories — the parent selector, tree
 * indentation and featured image simply never render because its taxonomy row
 * has `hierarchical = false`. This is the proof that hierarchy lives in the
 * taxonomy, not in any one resource.
 */
class TagResource extends AbstractTaxonomyResource
{
    protected static ?string $slug = 'tags';

    protected static ?string $navigationLabel = 'Tags';

    protected static ?string $modelLabel = 'Tag';

    protected static ?string $pluralModelLabel = 'Tags';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationParentItem = 'Posts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 22;

    protected static function taxonomySlug(): string
    {
        return 'tag';
    }

    protected static function contentType(): string
    {
        return 'post';
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Tags');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Tag');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Tags');
    }

    // Match the (translated) Posts navigation label so this stays nested under it.
    public static function getNavigationParentItem(): ?string
    {
        return tn_trans('Posts');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTags::route('/'),
            'create' => Pages\CreateTag::route('/create'),
            'edit' => Pages\EditTag::route('/{record}/edit'),
        ];
    }
}
