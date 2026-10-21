<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources;

use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages;
use TheNguyen\CMS\Models\UserProfileField;
use TheNguyen\CMS\Models\UserProfileFieldGroup;
use TheNguyen\CMS\Profile\ProfileFieldAdminService;
use TheNguyen\CMS\Profile\UserProfileFieldRegistry;

/**
 * Admin resource for profile field DEFINITIONS (Account Profile Field Builder,
 * P2). Writes route through ProfileFieldAdminService: admin fields use the
 * reserved `custom.` namespace; Core/plugin-owned (locked) fields can only be
 * reordered/toggled; data-shape properties freeze once values exist. Gated by
 * `profile_fields.manage`. Manages definitions only — never users' values.
 */
class ProfileFieldResource extends Resource
{
    protected static ?string $model = UserProfileField::class;

    protected static ?string $slug = 'profile-fields';

    protected static string|\UnitEnum|null $navigationGroup = 'Users';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 22;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Profile Fields');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Profile Field');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Profile Fields');
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
        // F3 — reflect the server-side write rules in the form so forbidden edits
        // render disabled instead of silently discarding on save / erroring:
        //  - $existing : key + group are fixed once the field exists.
        //  - $locked   : Core/plugin-owned — the service applies only order/active,
        //                so everything else is read-only here.
        //  - $frozen   : locked OR already has stored values — data-shape
        //                properties (type, encryption, options) freeze.
        $existing = fn (?UserProfileField $record): bool => $record !== null;
        $locked = fn (?UserProfileField $record): bool => $record !== null && $record->source !== 'admin';
        $hasValues = fn (?UserProfileField $record): bool => $record !== null
            && app('cms.user_profile_fields.admin')->impactedValueCount($record) > 0;
        $frozen = fn (?UserProfileField $record): bool => $locked($record) || $hasValues($record);
        $lockNote = function (?UserProfileField $record) use ($locked, $hasValues): ?string {
            if ($locked($record)) {
                return tn_trans('Core/plugin-owned — managed by its owner; only order and active state can change here.');
            }
            if ($hasValues($record)) {
                return tn_trans('Locked because this field already has stored values.');
            }

            return null;
        };

        return $schema->components([
            Section::make(tn_trans('Definition'))->schema([
                TextInput::make('key')
                    ->label(tn_trans('Key'))
                    ->helperText(tn_trans('Namespaced snake_case in the reserved "custom." namespace, e.g. "custom.linkedin". Immutable after creation.'))
                    ->required()
                    ->default('custom.')
                    ->disabled($existing)
                    ->dehydrated(fn (?UserProfileField $record): bool => $record === null),
                Select::make('group_id')
                    ->label(tn_trans('Group'))
                    ->options(fn (): array => UserProfileFieldGroup::query()->orderBy('sort_order')->pluck('key', 'id')->all())
                    ->required()
                    ->helperText(fn (?UserProfileField $record): ?string => $record !== null ? tn_trans('Fixed after creation.') : null)
                    ->disabled($existing),
                Select::make('type')
                    ->label(tn_trans('Type'))
                    ->options(array_combine(UserProfileFieldRegistry::TYPES, UserProfileFieldRegistry::TYPES))
                    ->required()
                    ->helperText($lockNote)
                    ->disabled($frozen),
                Select::make('visibility')
                    ->label(tn_trans('Visibility'))
                    ->options(array_combine(UserProfileFieldRegistry::VISIBILITIES, UserProfileFieldRegistry::VISIBILITIES))
                    ->default('owner')
                    ->required()
                    ->helperText(fn (?UserProfileField $record) => $lockNote($record) ?? tn_trans('Personal/contact data should stay "owner".'))
                    ->disabled($locked),
                Select::make('editability')
                    ->label(tn_trans('Editability'))
                    ->options(array_combine(UserProfileFieldRegistry::EDITABILITIES, UserProfileFieldRegistry::EDITABILITIES))
                    ->default('owner')
                    ->required()
                    ->helperText($lockNote)
                    ->disabled($locked),
                Toggle::make('is_encrypted')
                    ->label(tn_trans('Encrypt at rest'))
                    ->disabled($frozen)
                    ->helperText(fn (?UserProfileField $record) => $lockNote($record) ?? tn_trans('Encrypted values are not searchable and cannot be changed to/from plaintext later.')),
                Toggle::make('is_active')->label(tn_trans('Active'))->default(true),
                TextInput::make('sort_order')->label(tn_trans('Order'))->numeric()->default(0),
            ])->columns(2),
            Section::make(tn_trans('Labels'))->schema([
                TextInput::make('label')->label(tn_trans('Label'))->maxLength(255)->disabled($locked),
                TextInput::make('label_vi')->label(tn_trans('Label (Tiếng Việt)'))->maxLength(255)->disabled($locked),
                Textarea::make('help')->label(tn_trans('Help text'))->rows(2)->disabled($locked),
                Textarea::make('help_vi')->label(tn_trans('Help text (Tiếng Việt)'))->rows(2)->disabled($locked),
            ])->columns(2),
            Section::make(tn_trans('Options'))->schema([
                KeyValue::make('options.choices')
                    ->label(tn_trans('Choices (value → label)'))
                    ->helperText(fn (?UserProfileField $record) => $lockNote($record) ?? tn_trans('For select / radio / checkbox types.'))
                    ->disabled($frozen),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(tn_trans('Key'))->searchable()->sortable(),
                TextColumn::make('group.key')->label(tn_trans('Group'))->sortable(),
                TextColumn::make('type')->label(tn_trans('Type'))->badge(),
                TextColumn::make('visibility')->label(tn_trans('Visibility'))->badge(),
                TextColumn::make('editability')->label(tn_trans('Editability'))->badge(),
                TextColumn::make('source')->label(tn_trans('Source'))->badge(),
                IconColumn::make('is_encrypted')->label(tn_trans('Enc'))->boolean(),
                IconColumn::make('is_active')->label(tn_trans('Active'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('group_id')->label(tn_trans('Group'))
                    ->options(fn (): array => UserProfileFieldGroup::query()->pluck('key', 'id')->all()),
                SelectFilter::make('type')->options(array_combine(UserProfileFieldRegistry::TYPES, UserProfileFieldRegistry::TYPES)),
                SelectFilter::make('visibility')->options(array_combine(UserProfileFieldRegistry::VISIBILITIES, UserProfileFieldRegistry::VISIBILITIES)),
                SelectFilter::make('source')->options(['admin' => 'admin', 'core' => 'core']),
                TernaryFilter::make('is_active')->label(tn_trans('Active')),
            ])
            ->defaultSort('sort_order');
    }

    public static function adminNamespace(): string
    {
        return ProfileFieldAdminService::ADMIN_NAMESPACE;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProfileFields::route('/'),
            'create' => Pages\CreateProfileField::route('/create'),
            'edit' => Pages\EditProfileField::route('/{record}/edit'),
        ];
    }
}
