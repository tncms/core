<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\TaxonomyResource\Pages;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use TheNguyen\CMS\Models\Taxonomy;

class TaxonomyResource extends Resource
{
    protected static ?string $model = Taxonomy::class;

    protected static ?string $slug = 'taxonomies';

    protected static ?string $navigationLabel = 'Taxonomies';

    protected static ?string $modelLabel = 'Taxonomy';

    protected static ?string $pluralModelLabel = 'Taxonomies';

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationParentItem = 'Posts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 23;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Taxonomies');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Taxonomy');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Taxonomies');
    }

    // Match the (translated) Posts navigation label so this stays nested under it.
    public static function getNavigationParentItem(): ?string
    {
        return tn_trans('Posts');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(tn_trans('Taxonomy'))
                    ->schema([
                        Select::make('type')
                            ->label(tn_trans('Type'))
                            ->options([
                                'category' => tn_trans('Category'),
                                'tag' => tn_trans('Tag'),
                                'custom' => tn_trans('Custom'),
                            ])
                            ->required()
                            ->default('custom'),
                        Select::make('content_type')
                            ->label(tn_trans('Content type'))
                            ->options([
                                'post' => tn_trans('Post'),
                                'page' => tn_trans('Page'),
                                'custom' => tn_trans('Custom'),
                            ])
                            ->required()
                            ->default('post'),
                        TextInput::make('slug')->label(tn_trans('Slug'))->required()->maxLength(255),
                        Toggle::make('hierarchical')->label(tn_trans('Hierarchical'))->default(false),
                        TextInput::make('sort_order')->label(tn_trans('Sort order'))->numeric()->default(0),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')->label(tn_trans('Slug'))->sortable()->searchable(),
                TextColumn::make('type')->label(tn_trans('Type'))->sortable(),
                TextColumn::make('content_type')->label(tn_trans('For'))->sortable(),
                IconColumn::make('hierarchical')->label(tn_trans('Hierarchical'))->boolean(),
                IconColumn::make('is_core')->boolean()->label(tn_trans('Core')),
                TextColumn::make('sort_order')->label(tn_trans('Sort order'))->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Taxonomy $record): bool => (bool) $record->is_core),
            ])
            ->defaultSort('sort_order');
    }

    public static function canViewAny(): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('taxonomy.manage');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxonomies::route('/'),
            'create' => Pages\CreateTaxonomy::route('/create'),
            'edit' => Pages\EditTaxonomy::route('/{record}/edit'),
        ];
    }
}
