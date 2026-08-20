<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use TheNguyen\CMS\Services\MediaManager;

class MediaUpload extends Page
{
    protected static ?string $slug = 'media/upload';

    protected static ?string $navigationLabel = 'Upload';

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Upload Media';

    protected string $view = 'filament.admin.pages.media-upload';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Upload');
    }

    public function getTitle(): string
    {
        return tn_trans('Upload Media');
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(tn_trans('Upload files'))
                    ->description(tn_trans('Drag and drop or select up to 20 files. Supported: images (JPG, PNG, GIF, WebP, AVIF) and PDF.'))
                    ->schema([
                        FileUpload::make('files')
                            ->hiddenLabel()
                            ->multiple()
                            ->maxFiles(20)
                            ->acceptedFileTypes($this->acceptedTypes())
                            ->maxSize($this->maxUploadKb())
                            ->panelLayout('grid')
                            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                                return $this->manager()->upload($file)->path;
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        // Accessing the form state triggers saveUploadedFileUsing for each
        // pending file, which persists them through the MediaManager.
        $state = $this->form->getState();

        $count = is_array($state['files'] ?? null) ? count($state['files']) : 0;

        if ($count === 0) {
            Notification::make()
                ->title(tn_trans('No files selected'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($count === 1 ? tn_trans('1 file uploaded') : tn_trans(':count files uploaded', ['count' => $count]))
            ->success()
            ->send();

        $this->form->fill();
    }

    /**
     * Max upload size in kilobytes: the smaller of the media.max_upload_size_mb
     * setting and the hard config('cms.media.max_upload_size') ceiling. This is
     * a UX hint; MediaManager re-enforces the same limit server-side.
     */
    private function maxUploadKb(): int
    {
        $mb = (int) settings('media.max_upload_size_mb', 8);
        $settingKb = ($mb >= 1 ? $mb : 8) * 1024;

        $configBytes = (int) config('cms.media.max_upload_size', 8 * 1024 * 1024);
        $configKb = $configBytes > 0 ? (int) floor($configBytes / 1024) : $settingKb;

        return max(1, min($settingKb, $configKb));
    }

    /**
     * Client-side accepted MIME types, derived from the server-side allow-list
     * so the picker can never advertise a type the server will reject. SVG is
     * only offered when explicitly enabled (and is sanitized on upload).
     */
    private function acceptedTypes(): array
    {
        $mimes = array_values((array) config('cms.media.allowed_mimes', []));

        if (config('cms.media.allow_svg', false)
            && in_array('svg', (array) config('cms.media.allowed_extensions', []), true)) {
            $mimes[] = 'image/svg+xml';
        }

        return $mimes;
    }

    private function manager(): MediaManager
    {
        /** @var MediaManager $manager */
        $manager = app('cms.media');

        return $manager;
    }
}
