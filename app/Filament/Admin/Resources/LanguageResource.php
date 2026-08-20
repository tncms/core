<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\LanguageResource\Pages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;
use TheNguyen\CMS\Models\Language;

class LanguageResource extends Resource
{
    protected static ?string $model = Language::class;

    protected static ?string $slug = 'languages';

    protected static ?string $navigationLabel = 'Languages';

    protected static ?string $modelLabel = 'Language';

    protected static ?string $pluralModelLabel = 'Languages';

    protected static string|\UnitEnum|null $navigationGroup = 'CMS';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-language';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Languages');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Language');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Languages');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                Group::make([
                    Section::make(tn_trans('Language'))
                        ->schema([
                            TextInput::make('code')
                                ->label(tn_trans('Code'))
                                ->helperText(tn_trans('Lowercase code: two letters, optionally a region, e.g. vi, en, pt-br.'))
                                ->required()
                                ->maxLength(20)
                                // Normalize as the user leaves the field so the value validated for
                                // uniqueness already matches what LanguageManager::normalizeCode()
                                // stores (lowercased + trimmed). Entering "EN" when "en" exists now
                                // fails with a friendly validation error instead of a duplicate-key
                                // QueryException after normalization.
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, $set) => $set('code', strtolower(trim((string) $state))))
                                ->dehydrateStateUsing(fn (?string $state): string => strtolower(trim((string) $state)))
                                ->rule('regex:/^[a-z]{2}(-[a-z]{2})?$/')
                                ->validationMessages([
                                    'regex' => tn_trans('Use a lowercase code like "vi", "en" or "pt-br".'),
                                ])
                                ->unique(ignoreRecord: true),

                            TextInput::make('locale')
                                ->label(tn_trans('Locale'))
                                ->helperText(tn_trans('Full locale, e.g. vi_VN, en_US.'))
                                ->maxLength(20),

                            TextInput::make('name')
                                ->label(tn_trans('Name (English)'))
                                ->required()
                                ->maxLength(255),

                            TextInput::make('native_name')
                                ->label(tn_trans('Native name'))
                                ->maxLength(255),
                        ])
                        ->columns(2),
                ])
                    ->columnSpan([
                        'default' => 12,
                        'xl' => 8,
                    ]),

                Group::make([
                    Section::make(tn_trans('Settings'))
                        ->schema([
                            Select::make('direction')
                                ->label(tn_trans('Direction'))
                                ->options([
                                    'ltr' => tn_trans('Left to right (ltr)'),
                                    'rtl' => tn_trans('Right to left (rtl)'),
                                ])
                                ->default('ltr')
                                ->required(),

                            TextInput::make('flag')
                                ->label(tn_trans('Flag'))
                                ->helperText(tn_trans('Optional flag code, e.g. vn, us.'))
                                ->maxLength(255),

                            TextInput::make('sort_order')
                                ->label(tn_trans('Sort order'))
                                ->numeric()
                                ->default(0),

                            Toggle::make('is_default')
                                ->label(tn_trans('Default language'))
                                ->helperText(tn_trans('Only one language can be the default.')),

                            Toggle::make('is_active')
                                ->label(tn_trans('Active'))
                                ->default(true)
                                ->helperText(tn_trans('The default language is always active.')),
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
                TextColumn::make('code')
                    ->label(tn_trans('Code'))
                    ->badge()
                    ->searchable(),

                TextColumn::make('name')
                    ->label(tn_trans('Name'))
                    ->searchable(),

                TextColumn::make('native_name')
                    ->label(tn_trans('Native'))
                    ->toggleable(),

                TextColumn::make('direction')
                    ->label(tn_trans('Dir'))
                    ->badge(),

                IconColumn::make('is_default')
                    ->label(tn_trans('Default'))
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label(tn_trans('Active'))
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label(tn_trans('Order'))
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label(tn_trans('Updated'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('syncTranslationFiles')
                    ->label(tn_trans('Sync files'))
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => cms_can('languages.manage'))
                    ->action(function (Language $record): void {
                        $summary = app('cms.extension_translation')->syncTranslationFiles([$record->code]);

                        Notification::make()
                            ->title(tn_trans('Translation files synced'))
                            ->body(tn_trans(':created created, :skipped skipped, :errors errors.', [
                                'created' => count($summary['created']),
                                'skipped' => count($summary['skipped']),
                                'errors' => count($summary['errors']),
                            ]))
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->hidden(fn (Language $record): bool => $record->is_default)
                    // Safe delete (B3): refuse when the language still owns
                    // translated content/slugs, then clear the route cache (B5)
                    // so the dropped locale leaves the localized route pattern.
                    ->action(fn (Language $record) => static::deleteLanguageSafely($record)),
            ])
            ->defaultSort('sort_order');
    }

    public static function canViewAny(): bool
    {
        return cms_can('languages.manage');
    }

    public static function canCreate(): bool
    {
        return cms_can('languages.manage');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('languages.manage');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('languages.manage');
    }

    /**
     * Safe language deletion (B3): refuse when the manager reports a blocking
     * reason (default language, last language, or translated data still owned),
     * otherwise delete through the manager and refresh the localized route cache
     * (B5). Shared by the table row action and the edit-page header action.
     */
    public static function deleteLanguageSafely(Language $record): void
    {
        $languages = app('cms.language');
        $reason = $languages->deletionBlockReason($record);

        if ($reason !== null) {
            Notification::make()
                ->title(tn_trans('Language not deleted'))
                ->body(tn_trans($reason))
                ->danger()
                ->send();

            return;
        }

        $languages->delete($record);
        static::flushLanguageRouteCache();

        Notification::make()
            ->title(tn_trans('Language deleted'))
            ->body(tn_trans('Route cache was cleared so localized URLs can update.'))
            ->success()
            ->send();
    }

    /**
     * Clear the route cache after a language change (B5). Localized route
     * patterns are built from the active languages at registration time, so a
     * created/updated/deleted language only takes effect once routes recompile.
     * Best-effort: a failure warns the operator instead of breaking the request.
     */
    public static function flushLanguageRouteCache(): bool
    {
        try {
            Artisan::call('route:clear');

            return true;
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->title(tn_trans('Route cache not cleared'))
                ->body(tn_trans('Run "php artisan route:clear" so the language change affects localized URLs.'))
                ->warning()
                ->send();

            return false;
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLanguages::route('/'),
            'create' => Pages\CreateLanguage::route('/create'),
            'edit' => Pages\EditLanguage::route('/{record}/edit'),
        ];
    }
}
