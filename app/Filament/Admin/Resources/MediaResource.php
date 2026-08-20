<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\MediaResource\Pages;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Services\MediaManager;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static ?string $slug = 'media';

    protected static ?string $navigationLabel = 'Library';

    protected static ?string $modelLabel = 'Media';

    protected static ?string $pluralModelLabel = 'Media';

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Library');
    }

    public static function getModelLabel(): string
    {
        return tn_trans('Media');
    }

    public static function getPluralModelLabel(): string
    {
        return tn_trans('Media');
    }

    public static function form(Schema $schema): Schema
    {
        $components = [
                Group::make([
                    Section::make(tn_trans('Metadata'))
                        ->description(tn_trans('Used for SEO, accessibility and when inserting the image into content.'))
                        ->schema([
                            TextInput::make('alt')
                                ->label(tn_trans('Alt text'))
                                ->maxLength(255)
                                ->helperText(tn_trans('Describe the image for accessibility and SEO.')),

                            TextInput::make('title')
                                ->label(tn_trans('Title'))
                                ->maxLength(255)
                                ->helperText(tn_trans('Optional title attribute.')),

                            Textarea::make('caption')
                                ->label(tn_trans('Caption'))
                                ->rows(2)
                                ->helperText(tn_trans('Shown under images when inserted as figure.')),

                            Textarea::make('description')
                                ->label(tn_trans('Description'))
                                ->rows(3)
                                ->helperText(tn_trans('Internal/media library description.')),
                        ])
                        ->columns(1),
                ])
                    ->columnSpan([
                        'default' => 12,
                        'xl' => 8,
                    ]),

                Group::make([
                    Section::make(tn_trans('Preview'))
                        ->schema([
                            Placeholder::make('preview')
                                ->hiddenLabel()
                                ->content(static function (?Media $record): HtmlString {
                                    if ($record === null) {
                                        return new HtmlString('');
                                    }

                                    if ($record->isImage()) {
                                        return new HtmlString(
                                            '<img src="' . e($record->previewUrl()) . '" alt="' . e($record->seoAlt()) . '" style="max-width:100%;max-height:280px;border-radius:8px;border:1px solid #e5e7eb;" />'
                                        );
                                    }

                                    return new HtmlString('<span class="text-sm text-gray-500">No preview available (' . e((string) $record->mime_type) . ')</span>');
                                }),

                            Placeholder::make('url_copyable')
                                ->label(tn_trans('URL'))
                                ->content(static fn (?Media $record): HtmlString => static::copyableUrl($record)),
                        ]),

                    Section::make(tn_trans('File info'))
                        ->schema([
                            TextInput::make('original_filename')
                                ->label(tn_trans('Original filename'))
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('filename')
                                ->label(tn_trans('Stored filename'))
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('mime_type')
                                ->label(tn_trans('Type'))
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('size_human')
                                ->label(tn_trans('Size'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn (?Media $record): string => $record?->humanSize() ?? ''),

                            TextInput::make('dimensions')
                                ->label(tn_trans('Dimensions'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn (?Media $record): string => static::dimensionsLabel($record)),
                        ]),
                ])
                    ->columnSpan([
                        'default' => 12,
                        'xl' => 4,
                    ]),
        ];

        // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2): let plugins reshape the
        // media schema, then append any contributed regions full-width beneath.
        return $schema
            ->columns(12)
            ->components([
                ...tn_form_schema($components, Media::class, 'media'),
                ...tn_form_regions([], Media::class, 'media'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('preview')
                    ->label(tn_trans('Preview'))
                    ->size(48)
                    ->getStateUsing(fn (Media $record): ?string => $record->isImage() ? $record->previewUrl() : null)
                    ->defaultImageUrl(fn (): string => static::fileIconDataUri()),

                TextColumn::make('original_filename')
                    ->label(tn_trans('Filename'))
                    // Global search spans every metadata field.
                    ->searchable(['original_filename', 'filename', 'alt', 'title', 'caption', 'description'])
                    ->limit(40)
                    ->sortable(),

                TextColumn::make('alt')
                    ->label(tn_trans('Alt text'))
                    ->placeholder('—')
                    ->limit(32)
                    ->toggleable(),

                TextColumn::make('title')
                    ->label(tn_trans('Title'))
                    ->placeholder('—')
                    ->limit(24)
                    ->toggleable(),

                IconColumn::make('caption')
                    ->label(tn_trans('Caption'))
                    ->boolean()
                    ->state(fn (Media $record): bool => filled($record->caption))
                    ->toggleable(),

                TextColumn::make('url')
                    ->label(tn_trans('URL'))
                    ->copyable()
                    ->copyMessage(tn_trans('URL copied'))
                    ->limit(32)
                    ->toggleable(),

                TextColumn::make('mime_type')
                    ->label(tn_trans('Type'))
                    ->badge()
                    ->toggleable(),

                TextColumn::make('size')
                    ->label(tn_trans('Size'))
                    ->state(fn (Media $record): string => $record->humanSize())
                    ->sortable(),

                TextColumn::make('dimensions')
                    ->label(tn_trans('Dimensions'))
                    ->state(fn (Media $record): string => static::dimensionsLabel($record))
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(tn_trans('Uploaded'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('images')
                    ->label(tn_trans('Images'))
                    ->query(fn (Builder $query): Builder => $query->where('mime_type', 'like', 'image/%')),

                Filter::make('documents')
                    ->label(tn_trans('Documents'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNull('mime_type')
                            ->orWhere('mime_type', 'not like', 'image/%');
                    })),

                Filter::make('missing_alt')
                    ->label(tn_trans('Missing alt text'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNull('alt')->orWhere('alt', '');
                    })),

                Filter::make('missing_title')
                    ->label(tn_trans('Missing title'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNull('title')->orWhere('title', '');
                    })),

                Filter::make('missing_caption')
                    ->label(tn_trans('Missing caption'))
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNull('caption')->orWhere('caption', '');
                    })),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->after(fn (Media $record): bool => static::manager()->delete($record)),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->after(function (Collection $records): void {
                        $manager = static::manager();
                        $records->each(fn (Media $record) => $manager->delete($record));
                    }),

                BulkAction::make('generate_alt')
                    ->label(tn_trans('Generate alt from filename'))
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->modalDescription(tn_trans('Fill the Alt text from the filename for the selected media — only where Alt is currently empty. Existing alt text is never overwritten.'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $filled = 0;

                        $records->each(function (Media $record) use (&$filled): void {
                            if (filled($record->alt)) {
                                return;
                            }

                            $alt = Media::humanizeFilename($record->original_filename ?? $record->filename);

                            if ($alt === '') {
                                return;
                            }

                            $record->forceFill(['alt' => $alt])->save();
                            $filled++;
                        });

                        $notification = Notification::make()
                            ->title($filled === 0
                                ? tn_trans('No alt text needed updating')
                                : tn_trans('Generated alt text for :count item(s)', ['count' => $filled]));

                        ($filled === 0 ? $notification->info() : $notification->success())->send();
                    }),

                BulkAction::make('clear_metadata')
                    ->label(tn_trans('Clear metadata'))
                    ->icon('heroicon-o-backspace')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(tn_trans('Clear Alt text, Title, Caption and Description for the selected media. The files themselves are not affected.'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each(fn (Media $record) => $record->forceFill([
                            'alt' => null,
                            'title' => null,
                            'caption' => null,
                            'description' => null,
                        ])->save());

                        Notification::make()
                            ->title(tn_trans('Cleared metadata for :count item(s)', ['count' => $records->count()]))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return cms_can('media.view');
    }

    public static function canCreate(): bool
    {
        return cms_can('media.upload');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('media.edit');
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return cms_can('media.delete');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMedia::route('/'),
            'edit' => Pages\EditMedia::route('/{record}/edit'),
        ];
    }

    /**
     * Read-only URL field with an Alpine-powered "Copy" button. The URL only
     * appears once (HTML-escaped in the input value); the button copies it via
     * the input ref, so nothing is interpolated into JS.
     */
    private static function copyableUrl(?Media $record): HtmlString
    {
        $url = $record?->url;

        if (! is_string($url) || $url === '') {
            return new HtmlString('<span class="text-sm text-gray-400">—</span>');
        }

        return new HtmlString(
            '<div x-data="{copied:false}" style="display:flex;gap:.5rem;align-items:center;max-width:100%;">'
            . '<input x-ref="u" type="text" readonly value="' . e($url) . '" @focus="$el.select()"'
            . ' style="flex:1 1 auto;min-width:0;padding:.4rem .6rem;border:1px solid #e5e7eb;border-radius:8px;font-size:.8rem;background:transparent;">'
            . '<button type="button"'
            . ' @click="navigator.clipboard ? navigator.clipboard.writeText($refs.u.value).then(() => { copied=true; setTimeout(() => copied=false, 1500) }) : $refs.u.select()"'
            . ' x-text="copied ? \'Copied!\' : \'Copy\'"'
            . ' style="padding:.4rem .75rem;border:1px solid #e5e7eb;border-radius:8px;font-size:.8rem;cursor:pointer;white-space:nowrap;"></button>'
            . '</div>'
        );
    }

    private static function dimensionsLabel(?Media $record): string
    {
        if ($record === null || $record->width === null || $record->height === null) {
            return '—';
        }

        return $record->width . ' × ' . $record->height;
    }

    private static function manager(): MediaManager
    {
        /** @var MediaManager $manager */
        $manager = app('cms.media');

        return $manager;
    }

    /**
     * Inline SVG document icon used as a fallback preview for non-image files.
     */
    private static function fileIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#9ca3af">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
