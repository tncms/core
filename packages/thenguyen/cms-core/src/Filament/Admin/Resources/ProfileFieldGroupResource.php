<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages;
use TheNguyen\CMS\Models\UserProfileFieldGroup;

/**
 * Admin resource for profile field GROUPS (Account Profile Field Builder, P2).
 * Writes route through ProfileFieldAdminService (never raw model saves), so
 * reserved-key/collision/ownership rules are enforced server-side. Gated by
 * `profile_fields.manage`. Manages definitions only — not users' values.
 */
class ProfileFieldGroupResource extends Resource
{
    protected static ?string $model = UserProfileFieldGroup::class;

    protected static ?string $slug = 'profile-field-groups';

    protected static string|\UnitEnum|null $navigationGroup = 'Users';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 21;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Profile Field Groups');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Profile Field Group');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Profile Field Groups');
    }

    public static function canViewAny(): bool
    {
        return cms_can('profile_fields.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('profile_fields.manage');
    }

    public static function canEdit($record): bool
    {
        return cms_can('profile_fields.manage');
    }

    public static function canDelete($record): bool
    {
        return cms_can('profile_fields.manage') && $record->source === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(tn_trans('Group'))->schema([
                TextInput::make('key')
                    ->label(tn_trans('Key'))
                    ->helperText(tn_trans('Stable snake_case identifier, e.g. "professional". Cannot be changed after creation.'))
                    ->required()
                    ->disabled(fn (?UserProfileFieldGroup $record): bool => $record !== null)
                    ->dehydrated(fn (?UserProfileFieldGroup $record): bool => $record === null),
                TextInput::make('label')->label(tn_trans('Label'))->maxLength(255),
                TextInput::make('label_vi')->label(tn_trans('Label (Tiếng Việt)'))->maxLength(255),
                TextInput::make('sort_order')->label(tn_trans('Order'))->numeric()->default(0),
                Toggle::make('is_active')->label(tn_trans('Active'))->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(tn_trans('Key'))->searchable()->sortable(),
                TextColumn::make('source')->label(tn_trans('Source'))->badge(),
                TextColumn::make('sort_order')->label(tn_trans('Order'))->sortable(),
                IconColumn::make('is_active')->label(tn_trans('Active'))->boolean(),
                IconColumn::make('is_locked')->label(tn_trans('Locked'))->boolean(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfileFieldGroups::route('/'),
            'create' => Pages\CreateProfileFieldGroup::route('/create'),
            'edit' => Pages\EditProfileFieldGroup::route('/{record}/edit'),
        ];
    }
}
