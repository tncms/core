<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\Theme;

class ThemesPage extends Page
{
    protected static ?string $slug = 'themes';

    protected static ?string $navigationLabel = 'Themes';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Themes';

    protected string $view = 'filament.admin.pages.themes-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Themes');
    }

    public function getTitle(): string
    {
        return tn_trans('Themes');
    }

    public static function canAccess(): bool
    {
        return cms_can('themes.view');
    }

    public function getSubheading(): ?string
    {
        return tn_trans('TN CMS requires exactly one active theme for the frontend. To change the active theme, click Activate on another valid theme.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('installTheme')
                ->label(tn_trans('Install Theme'))
                ->icon('heroicon-m-arrow-up-tray')
                ->visible(fn (): bool => cms_can('themes.install'))
                ->url(InstallThemePage::getUrl()),
        ];
    }

    /**
     * Theme cards for the view: metadata + screenshot data URI + active flag.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getThemes(): array
    {
        // The effective active theme drives the "Active" badge; there is no
        // Deactivate action — switching happens by activating another theme.
        $activeSlug = $this->manager()->active()?->slug;

        return array_map(function (Theme $theme) use ($activeSlug): array {
            return [
                'name' => $theme->name,
                'slug' => $theme->slug,
                'version' => $theme->version,
                'author' => $theme->author,
                'authorUri' => $theme->authorUri,
                'description' => $theme->description,
                'screenshot' => $this->screenshotDataUri($theme),
                'screenshotPath' => $theme->screenshot,
                'isActive' => $theme->slug === $activeSlug,
                'supportsThemeOptions' => $this->manager()->hasThemeOptions($theme->slug),
                'supports' => $theme->supports,
                'requires' => $this->manifestRequires($theme),
                'path' => $theme->path,
            ];
        }, $this->manager()->all());
    }

    /**
     * Theme folders with a missing/invalid manifest, surfaced as warnings.
     *
     * @return array<int, array{slug: string, reason: string}>
     */
    public function getInvalidThemes(): array
    {
        return $this->manager()->invalidThemes();
    }

    public function activate(string $slug): void
    {
        if (! cms_can('themes.activate')) {
            $this->denied();

            return;
        }

        if ($this->manager()->activate($slug)) {
            Notification::make()
                ->title(tn_trans('Theme ":slug" activated', ['slug' => $slug]))
                ->success()
                ->send();

            return;
        }

        // Activation is transactional: on failure the previously active theme is
        // left unchanged and the frontend keeps rendering with it.
        Notification::make()
            ->title(tn_trans('Theme ":slug" could not be activated', ['slug' => $slug]))
            ->body(tn_trans('The theme is missing, has an invalid manifest, lacks required views, or its assets could not be published. The current active theme is unchanged.'))
            ->danger()
            ->send();
    }

    /**
     * Permanently delete a non-active theme's files (delegates to the installer,
     * which re-validates the slug, the active/last-theme state, and the resolved
     * path before removing anything).
     */
    public function delete(string $slug): void
    {
        if (! cms_can('themes.delete')) {
            $this->denied();

            return;
        }

        $result = extension_installer()->deleteTheme($slug);

        if ($result->success) {
            Notification::make()
                ->title(tn_trans('Theme deleted'))
                ->body($result->message)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(tn_trans('Theme not deleted'))
            ->body($result->message)
            ->danger()
            ->send();
    }

    /**
     * The theme's declared `requires` map (e.g. {"cms": ">=0.6.0"}), read
     * straight from theme.json for the read-only "View details" panel. Returns
     * an empty array when absent/invalid. Read-only — no manifest mutation.
     *
     * @return array<string, mixed>
     */
    private function manifestRequires(Theme $theme): array
    {
        $file = $theme->path . DIRECTORY_SEPARATOR . 'theme.json';

        if (! File::exists($file)) {
            return [];
        }

        $data = json_decode((string) File::get($file), true);

        return is_array($data['requires'] ?? null) ? $data['requires'] : [];
    }

    /**
     * Read a theme screenshot into an inline data URI (admin-only preview).
     * The theme directory is not web-accessible, so we embed it directly.
     */
    private function screenshotDataUri(Theme $theme): ?string
    {
        if ($theme->screenshot === null || ! File::exists($theme->screenshot)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) File::get($theme->screenshot));
    }

    /**
     * Notify the user that they lack permission for the requested action.
     * Defense-in-depth: the buttons are already hidden when unauthorized.
     */
    private function denied(): void
    {
        Notification::make()
            ->title(tn_trans('Permission denied'))
            ->body(tn_trans('You do not have permission to perform this action.'))
            ->danger()
            ->send();
    }

    private function manager(): ThemeManager
    {
        /** @var ThemeManager $manager */
        $manager = app('cms.theme');

        return $manager;
    }
}
