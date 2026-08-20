<?php

declare(strict_types=1);

namespace App\Filament\Admin\Components;

use App\Filament\Admin\Support\MediaItems;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use TheNguyen\CMS\Services\MediaManager;

/**
 * WordPress-like featured-image picker, reusable on any form.
 *
 * `MediaPicker::make('featured_image', 'Featured image')` returns a Filament
 * `Group` with:
 *   - a reactive preview (empty "Add image" state, or the selected image with
 *     its metadata),
 *   - Add/Replace + Remove action buttons,
 *   - a hidden field holding the value.
 *
 * "Add image" / "Replace image" opens a Filament action modal with two tabs:
 *   - **Media Library** — a thumbnail grid (MediaLibrarySelect) to pick an
 *     existing image,
 *   - **Upload files** — a drag-and-drop FileUpload routed through
 *     MediaManager::upload() (no page leave, no separate route).
 *
 * The stored value is the media **URL string** in `cms_contents.featured_image`
 * (unchanged from earlier phases — no `featured_image_media_id` relation).
 */
class MediaPicker
{
    /** Accepted upload MIME types (images only). */
    private const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Fallback max upload size in MB when no setting is configured. */
    private const DEFAULT_MAX_SIZE_MB = 8;

    /**
     * Max upload size in kilobytes, from media.max_upload_size_mb.
     */
    private static function maxSizeKb(): int
    {
        $mb = (int) settings('media.max_upload_size_mb', self::DEFAULT_MAX_SIZE_MB);

        if ($mb < 1) {
            $mb = self::DEFAULT_MAX_SIZE_MB;
        }

        return $mb * 1024;
    }

    public static function make(string $field = 'featured_image', string $label = 'Featured image'): Group
    {
        return Group::make([
            Placeholder::make($field . '_preview')
                ->label(tn_trans($label))
                ->content(static fn (Get $get): HtmlString => self::previewHtml((string) ($get($field) ?? ''))),

            Actions::make([
                Action::make($field . '_select')
                    ->label(static fn (Get $get): string => filled($get($field)) ? tn_trans('Replace image') : tn_trans('Add image'))
                    ->icon('heroicon-o-photo')
                    ->button()
                    ->modalHeading(tn_trans('Select featured image'))
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitActionLabel(tn_trans('Set featured image'))
                    ->fillForm(static fn (Get $get): array => ['selected_url' => (string) ($get($field) ?? '')])
                    ->schema([self::modalTabs()])
                    ->action(static function (array $data, Set $set) use ($field): void {
                        $url = self::resolveChosenUrl($data);

                        if ($url === null) {
                            Notification::make()
                                ->title(tn_trans('No image selected'))
                                ->body(tn_trans('Pick an image from the library or upload one, then try again.'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $set($field, $url);
                    }),

                Action::make($field . '_remove')
                    ->label(tn_trans('Remove image'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->link()
                    ->visible(static fn (Get $get): bool => filled($get($field)))
                    ->action(static fn (Set $set) => $set($field, '')),
            ]),

            // Source of truth. Persist a cleared selection as '' (not null) so it
            // survives ContentManager's `$data['featured_image'] ?? $current`
            // merge, which would otherwise keep the previously saved URL.
            Hidden::make($field)
                ->dehydrateStateUsing(static fn ($state): string => is_string($state) ? $state : ''),
        ]);
    }

    /**
     * The modal body: Media Library (grid) + Upload files (drag & drop) tabs.
     */
    private static function modalTabs(): Tabs
    {
        return Tabs::make('featuredImageTabs')
            ->contained(false)
            ->tabs([
                Tab::make('Media Library')
                    ->label(tn_trans('Media Library'))
                    ->icon('heroicon-o-photo')
                    ->schema([
                        MediaLibrarySelect::make('selected_url')->hiddenLabel(),
                    ]),

                Tab::make('Upload files')
                    ->label(tn_trans('Upload files'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Placeholder::make('upload_note')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<div style="padding:.6rem .8rem;border-radius:9px;background:rgba(245,158,11,.12);'
                                . 'border:1px solid rgba(245,158,11,.35);font-size:.82rem;line-height:1.4;">'
                                . e(tn_trans('Uploaded image will be used as the featured image. It is also added to your media library, and takes priority over any selection on the Media Library tab.'))
                                . '</div>'
                            )),

                        FileUpload::make('uploaded_url')
                            ->hiddenLabel()
                            ->image()
                            ->imageEditor(false)
                            ->imagePreviewHeight('180px')
                            ->acceptedFileTypes(self::ACCEPTED_TYPES)
                            ->maxSize(self::maxSizeKb())
                            ->helperText(tn_trans('Drag & drop or browse a JPG, PNG, GIF or WebP. A preview appears before you submit.'))
                            // Hand the upload to the CMS MediaManager (SEO filename,
                            // alt default, dimensions) and keep the resulting URL.
                            ->saveUploadedFileUsing(static fn (TemporaryUploadedFile $file): string => self::manager()->upload($file)->url),
                    ]),
            ]);
    }

    /**
     * Pick the URL chosen in the modal: a freshly uploaded image wins over a
     * library selection. Returns null when nothing was chosen.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveChosenUrl(array $data): ?string
    {
        $uploaded = $data['uploaded_url'] ?? null;

        if (is_array($uploaded)) {
            $uploaded = collect($uploaded)->filter()->last();
        }

        if (is_string($uploaded) && $uploaded !== '') {
            return $uploaded;
        }

        $library = $data['selected_url'] ?? null;

        return is_string($library) && $library !== '' ? $library : null;
    }

    /**
     * Preview block: empty "no image" state, or the selected image with the
     * metadata we can resolve from cms_media (label external/missing otherwise).
     */
    private static function previewHtml(string $url): HtmlString
    {
        if ($url === '') {
            return new HtmlString(
                '<div style="padding:.85rem;border:1px dashed rgba(127,127,127,.4);border-radius:10px;text-align:center;">'
                . '<div style="font-size:.85rem;opacity:.7;">' . e(tn_trans('No image selected.')) . '</div>'
                . '<div style="font-size:.78rem;opacity:.55;margin-top:.25rem;">' . e(tn_trans('Select or upload an image for this content.')) . '</div>'
                . '</div>'
            );
        }

        // Resolve metadata through the shared helper so the preview matches the
        // data shown inside the modal grid/details panel.
        $media = MediaItems::findByUrl($url);

        $img = '<img src="' . e($url) . '" alt="' . e($media['seo_alt'] ?? '')
            . '" style="width:100%;max-height:220px;object-fit:contain;border-radius:9px;border:1px solid rgba(127,127,127,.25);background:rgba(127,127,127,.08);" />';

        $rows = '';
        $row = static fn (string $k, string $v): string => '<div style="font-size:.62rem;text-transform:uppercase;letter-spacing:.04em;opacity:.6;margin-top:.45rem;">'
            . e($k) . '</div><div style="font-size:.8rem;word-break:break-all;">' . e($v) . '</div>';

        if ($media !== null) {
            $rows .= $row(tn_trans('Filename'), (string) ($media['name'] !== '' ? $media['name'] : '—'));

            if (($media['alt'] ?? null) !== null) {
                $rows .= $row(tn_trans('Alt text'), (string) $media['alt']);
            }

            if ($media['width'] !== null && $media['height'] !== null) {
                $rows .= $row(tn_trans('Dimensions'), $media['width'] . ' × ' . $media['height']);
            }
        } else {
            $rows .= '<div style="font-size:.78rem;color:#b45309;margin-top:.45rem;">' . e(tn_trans('External or missing media record.')) . '</div>';
        }

        $rows .= $row(tn_trans('URL'), $url);

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;gap:.15rem;">' . $img . $rows . '</div>'
        );
    }

    private static function manager(): MediaManager
    {
        /** @var MediaManager $manager */
        $manager = app('cms.media');

        return $manager;
    }
}
