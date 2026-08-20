<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Models\Role;

/**
 * Users admin resource (v1.0.0-beta.3).
 *
 * Manages the host app's users and their CMS role assignments. Password is
 * required on create and optional on edit (left blank = unchanged); the User
 * model's "hashed" cast hashes it on save. Two safety rails are enforced through
 * {@see canDelete()}: you can never delete yourself, nor the last super admin.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    protected static string|\UnitEnum|null $navigationGroup = 'Users';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Users');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('User');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(tn_trans('Account'))
                    ->schema([
                        TextInput::make('name')
                            ->label(tn_trans('Name'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(tn_trans('Email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),

                        TextInput::make('password')
                            ->label(tn_trans('Password'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText(tn_trans('Leave blank to keep the current password when editing.'))
                            // Required only when creating; the model's "hashed"
                            // cast hashes the value. Only persisted when filled.
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state)),

                        Select::make('roles')
                            ->label(tn_trans('Roles'))
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->helperText(tn_trans('Roles grant permissions. A user with no roles can sign in but has no admin permissions.')),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(tn_trans('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(tn_trans('Email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles.name')
                    ->label(tn_trans('Roles'))
                    ->badge()
                    ->placeholder(tn_trans('No roles')),

                TextColumn::make('created_at')
                    ->label(tn_trans('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    // ---------------------------------------------------------------------
    // Authorization (permission-guarded)
    // ---------------------------------------------------------------------

    public static function canViewAny(): bool
    {
        return cms_can('users.view');
    }

    public static function canCreate(): bool
    {
        return cms_can('users.create');
    }

    public static function canEdit(Model $record): bool
    {
        return cms_can('users.edit');
    }

    public static function canDelete(Model $record): bool
    {
        if (! cms_can('users.delete')) {
            return false;
        }

        // Never delete yourself.
        if (auth()->id() === $record->getKey()) {
            return false;
        }

        // Never delete the last remaining super admin.
        return ! self::isLastSuperAdmin($record);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * True when $record is a super admin and the only one left.
     */
    public static function isLastSuperAdmin(Model $record): bool
    {
        if (! method_exists($record, 'isSuperAdmin') || ! $record->isSuperAdmin()) {
            return false;
        }

        return app('cms.permission')->superAdminUserCount() <= 1;
    }

    public static function superAdminRoleId(): ?int
    {
        $id = Role::query()->where('slug', Role::SUPER_ADMIN)->value('id');

        return $id === null ? null : (int) $id;
    }
}
