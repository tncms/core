<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MenuResource\Pages;
use App\Filament\Admin\Resources\MenuResource\RelationManagers\MenuItemsRelationManager;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Models\Menu;

class MenuResource extends Resource
{
    use HasTranslations;

    protected static ?string $model = Menu::class;

    protected static ?string $slug = 'menus';

    protected static ?string $navigationLabel = 'Menus';

    protected static ?string $modelLabel = 'Menu';

    protected static ?string $pluralModelLabel = 'Menus';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Menus');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Menu');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Menus');
    }

    /**
     * Locations a theme may later declare. Menus are core data; themes will
     * map these slots (header/footer/sidebar) to registered menus.
     *
     * @return array<string, string>
     */
    public static function locationOptions(): array
    {
        return [
            'header' => tn_trans('Header'),
            'footer' => tn_trans('Footer'),
            'sidebar' => tn_trans('Sidebar'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['translations' => fn ($q) => $q->where('locale', editing_locale())]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                Group::make([
                    Section::make(tn_trans('Menu'))
                        ->schema([
                            static::translationLocaleField(),

                            TextInput::make('name')
                                ->label(tn_trans('Name'))
                                ->required()
                                ->maxLength(255),

                            TextInput::make('slug')
                                ->label(tn_trans('Slug'))
                                ->helperText(tn_trans('Leave empty to auto-generate from the name.'))
                                ->maxLength(255),

                            Textarea::make('description')
                                ->label(tn_trans('Description'))
                                ->rows(3),
                        ])
                        ->columns(1),
                ])
                    ->columnSpan([
                        'default' => 12,
                        'xl' => 8,
                    ]),

                Group::make([
                    Section::make(tn_trans('Settings'))
                        ->schema([
                            Select::make('location')
                                ->label(tn_trans('Location'))
                                ->options(self::locationOptions())
                                ->placeholder(tn_trans('Not assigned'))
                                ->helperText(tn_trans('Where a theme will render this menu.')),

                            Select::make('status')
                                ->label(tn_trans('Status'))
                                ->options([
                                    'active' => tn_trans('Active'),
                                    'inactive' => tn_trans('Inactive'),
                                ])
                                ->default('active')
                                ->required(),

                            TextInput::make('sort_order')
                                ->label(tn_trans('Sort order'))
                                ->numeric()
                                ->default(0),
                        ])
                        ->columns(1),
                ])
                    ->columnSpan([
                        'default' => 12,
                        'xl' => 4,
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(tn_trans('Name'))
                    ->state(fn (Menu $record): string => $record->displayName(editing_locale()))
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        return $query->whereHas('translations', function (Builder $q) use ($search): void {
                            $q->where('locale', editing_locale())->where('name', 'like', "%{$search}%");
                        });
                    }),

                TextColumn::make('slug')
                    ->label(tn_trans('Slug'))
                    ->searchable()
                    ->toggleable(),

                BadgeColumn::make('location')
                    ->label(tn_trans('Location'))
                    ->placeholder('—')
                    ->searchable()
                    ->colors([
                        'info' => 'header',
                        'success' => 'footer',
                        'warning' => 'sidebar',
                    ]),

                BadgeColumn::make('status')
                    ->label(tn_trans('Status'))
                    ->colors([
                        'success' => 'active',
                        'gray' => 'inactive',
                    ]),

                TextColumn::make('items_count')
                    ->label(tn_trans('Items'))
                    ->state(fn (Menu $record): int => $record->items()->count()),

                TextColumn::make('updated_at')
                    ->label(tn_trans('Updated'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getRelations(): array
    {
        return [
            MenuItemsRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return cms_can('menus.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('menus.manage');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('menus.manage');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('menus.manage');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenus::route('/'),
            'create' => Pages\CreateMenu::route('/create'),
            'edit' => Pages\EditMenu::route('/{record}/edit'),
        ];
    }
}
