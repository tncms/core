<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use TheNguyen\CMS\Support\InstallResult;

/**
 * Plugins → Install Plugin (v1.0.0-beta.2).
 *
 * A safe local ZIP installer over {@see \TheNguyen\CMS\Services\ExtensionInstaller}.
 * The upload is stored privately under storage/app/private, handed to the
 * installer (which extracts to a throwaway temp dir, validates, and only then
 * moves into plugins/{slug}), and deleted afterwards. The plugin is never
 * activated automatically — it appears in Installed Plugins as inactive.
 */
class InstallPluginPage extends Page
{
    protected static ?string $slug = 'plugins/install';

    protected static ?string $navigationLabel = 'Install Plugin';

    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Install Plugin';

    protected string $view = 'filament.admin.pages.install-plugin-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Install Plugin');
    }

    public function getTitle(): string
    {
        return tn_trans('Install Plugin');
    }

    public static function canAccess(): bool
    {
        return cms_can('plugins.install');
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function getSubheading(): ?string
    {
        return tn_trans('Upload a plugin .zip to install it into plugins/{slug}. The plugin is installed inactive — activate it from Installed Plugins when ready.');
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        FileUpload::make('zip')
                            ->label(tn_trans('Plugin ZIP file'))
                            ->helperText(tn_trans('A .zip containing a plugin.json manifest (at the root or inside a single folder).'))
                            ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'])
                            ->disk('local')
                            ->directory('tncms-installer-uploads')
                            ->visibility('private')
                            ->storeFiles(true)
                            ->required(),

                        Toggle::make('overwrite')
                            ->label(tn_trans('Overwrite existing files'))
                            ->helperText(tn_trans('If a plugin with the same slug exists, replace it. An active plugin cannot be overwritten — deactivate it first.'))
                            ->default(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function install(): void
    {
        $state = $this->form->getState();

        $path = $this->resolveUploadedPath($state['zip'] ?? null);

        if ($path === null) {
            Notification::make()->title(tn_trans('Please choose a .zip file to upload.'))->danger()->send();

            return;
        }

        $absolute = Storage::disk('local')->path($path);

        $result = extension_installer()->installPluginFromZip(
            $absolute,
            (bool) ($state['overwrite'] ?? false),
        );

        // The uploaded archive is no longer needed once the installer has run.
        Storage::disk('local')->delete($path);

        $this->notify($result);

        if ($result->success) {
            $this->form->fill();
        }
    }

    /**
     * FileUpload state may be a string path or a single-element array of paths.
     */
    private function resolveUploadedPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value) ?: null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function notify(InstallResult $result): void
    {
        if ($result->success) {
            $body = $result->warnings === []
                ? $result->message
                : $result->message . ' ' . tn_trans('Warnings:') . ' ' . implode(' ', $result->warnings);

            Notification::make()
                ->title(tn_trans('Plugin installed'))
                ->body($body)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(tn_trans('Plugin not installed'))
            ->body($result->message)
            ->danger()
            ->send();
    }
}
