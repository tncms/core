<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RoleResource\Pages;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Roles admin resource (v1.0.0-beta.3).
 *
 * Permissions are presented as a checkbox list per permission group. The
 * selections are NOT model attributes — they are gathered from the form state
 * and synced to the cms_role_permissions pivot in the Create/Edit pages (see
 * {@see syncPermissions()}). System roles are protected from deletion, and the
 * super-admin role always keeps every permission regardless of the form.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $slug = 'roles';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'Role';

    protected static ?string $pluralModelLabel = 'Roles';

    protected static string|\UnitEnum|null $navigationGroup = 'Users';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Roles');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Role');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Roles');
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
            Section::make(tn_trans('Role'))
                ->schema([
                    TextInput::make('name')
                        ->label(tn_trans('Name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label(tn_trans('Slug'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText(tn_trans('Stable identifier, e.g. "editor". Cannot be changed for system roles.'))
                        ->disabled(fn (?Role $record): bool => $record?->is_system === true)
                        ->dehydrated(fn (?Role $record): bool => $record?->is_system !== true),

                    Textarea::make('description')
                        ->label(tn_trans('Description'))
                        ->rows(2)
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Toggle::make('is_system')
                        ->label(tn_trans('System role'))
                        ->disabled()
                        ->helperText(tn_trans('System roles are managed by TN CMS and cannot be deleted.')),
                ])
                ->columns(2),
        ];

        // One collapsible checkbox section per permission group.
        foreach (self::permissions()->grouped() as $group => $perms) {
            $options = [];

            foreach ($perms as $perm) {
                $options[$perm['slug']] = tn_trans($perm['name']);
            }

            $components[] = Section::make(tn_trans($group))
                ->description(tn_trans('Permissions in the ":group" group.', ['group' => $group]))
                ->schema([
                    CheckboxList::make(self::permissionGroupKey($group))
                        ->hiddenLabel()
                        ->options($options)
                        ->columns(2)
                        ->bulkToggleable()
                        // Not a model attribute — synced manually in the pages.
                        ->dehydrated(false),
                ])
                ->collapsible();
        }

        return $schema->components($components);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(tn_trans('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label(tn_trans('Slug'))
                    ->badge()
                    ->searchable(),

                TextColumn::make('permissions_count')
                    ->label(tn_trans('Permissions'))
                    ->counts('permissions')
                    ->badge()
                    ->color('gray'),

                IconColumn::make('is_system')
                    ->label(tn_trans('System'))
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label(tn_trans('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    // ---------------------------------------------------------------------
    // Permission state <-> pivot helpers (used by Create/Edit pages)
    // ---------------------------------------------------------------------

    public static function permissionGroupKey(string $group): string
    {
        return 'perm_' . Str::slug($group, '_');
    }

    /**
     * Resolve the permission slugs selected across all group checkbox lists.
     *
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    public static function selectedSlugsFromState(array $state): array
    {
        $slugs = [];

        foreach (self::permissions()->grouped() as $group => $perms) {
            $chosen = (array) ($state[self::permissionGroupKey($group)] ?? []);

            foreach ($chosen as $slug) {
                $slugs[] = (string) $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Build the per-group checkbox state from a role's current permissions.
     *
     * @return array<string, array<int, string>>
     */
    public static function fillStateFromRole(Role $role): array
    {
        $current = $role->permissions()->pluck('slug')->all();
        $state = [];

        foreach (self::permissions()->grouped() as $group => $perms) {
            $groupSlugs = array_map(static fn (array $p): string => $p['slug'], $perms);
            $state[self::permissionGroupKey($group)] = array_values(array_intersect($groupSlugs, $current));
        }

        return $state;
    }

    /**
     * Sync a role's permissions from form state. The super-admin role always
     * keeps every permission, so it can never be accidentally emptied.
     *
     * @param  array<string, mixed>  $state
     */
    public static function syncPermissions(Role $role, array $state): void
    {
        if ($role->isSuperAdmin()) {
            $ids = Permission::query()->pluck('id')->all();
        } else {
            $slugs = self::selectedSlugsFromState($state);
            $ids = Permission::query()->whereIn('slug', $slugs)->pluck('id')->all();
        }

        $role->permissions()->sync($ids);
    }

    // ---------------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------------

    public static function canViewAny(): bool
    {
        return cms_can('roles.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('roles.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return cms_can('roles.manage');
    }

    public static function canDelete(Model $record): bool
    {
        // System roles (including super-admin) are never deletable.
        return cms_can('roles.manage') && $record->getAttribute('is_system') !== true;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function permissions(): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = app('cms.permission');

        return $manager;
    }
}
