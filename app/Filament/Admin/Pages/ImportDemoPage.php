<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use TheNguyen\CMS\Support\DemoPackage;
use TheNguyen\CMS\Support\ImportResult;

/**
 * Appearance → Import Demo.
 *
 * A thin admin surface over the generic DemoImporter service (theme-architecture
 * 16, generalized): it lists every demo package discovered for installed themes
 * AND active plugins, and lets an authorized admin import / re-import / reset
 * each one. All logic lives in the service — this page only displays discovery +
 * provenance and dispatches actions.
 *
 * Core natively imports the `media`, `theme_options`, and `homepage` files of a
 * theme package; a plugin package's extra files are handled by the handlers it
 * declares. Reset restores the pre-import settings/layout snapshot the core
 * wrote (data written by a custom handler is not auto-reverted).
 */
class ImportDemoPage extends Page
{
    protected static ?string $slug = 'import-demo';

    protected static ?string $navigationLabel = 'Import Demo';

    protected static string|\UnitEnum|null $navigationGroup = 'Appearance';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    // After Themes (20), Theme Options (30), Install Theme (40).
    protected static ?int $navigationSort = 50;

    protected static ?string $title = 'Import Demo';

    protected string $view = 'filament.admin.pages.import-demo-page';

    /** Max preview image size to inline as a data URI (bytes). */
    private const PREVIEW_MAX_BYTES = 512000;

    public static function getNavigationLabel(): string
    {
        return tn_trans('Import Demo');
    }

    public function getTitle(): string
    {
        return tn_trans('Import Demo');
    }

    public static function canAccess(): bool
    {
        return cms_can('themes.import');
    }

    /**
     * Every discovered demo package (theme + active plugin), decorated with its
     * import status read from the provenance entry.
     *
     * @return array<int, array<string, mixed>>
     */
    public function packages(): array
    {
        $out = [];
        $activeTheme = app('cms.theme')->activeSlug();

        foreach (app('cms.demo_importer')->discover() as $package) {
            /** @var DemoPackage $package */
            $provenance = settings()->get($this->provenanceKey($package->owner, $package->slug));
            $imported = is_array($provenance);

            // Active-theme scoping (CORE-THEME-2): an inactive theme's presets
            // are not importable here; one already imported stays listed (as
            // inactive) so the owner can still reset it after a theme switch.
            $inactiveTheme = $package->type === DemoPackage::TYPE_THEME && $package->owner !== $activeTheme;

            if ($inactiveTheme && ! $imported) {
                continue;
            }

            $out[] = [
                'inactive_theme' => $inactiveTheme,
                'id' => $package->id(),
                'type' => $package->type,
                'owner' => $package->owner,
                'slug' => $package->slug,
                'name' => $package->name,
                'description' => $package->description,
                'version' => $package->version,
                'preset' => $package->preset ?? '',
                'preview' => $this->previewDataUri($package),
                'warnings' => $package->warnings,
                'rollback' => $package->rollbackSupported,
                'imported' => $imported,
                'imported_at' => $imported && is_string($provenance['imported_at'] ?? null) ? $provenance['imported_at'] : null,
                'batch_id' => $imported && is_string($provenance['batch_id'] ?? null) ? $provenance['batch_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * Import (or re-import) a discovered package by its id ("type:owner:slug").
     */
    public function importPackage(string $id): void
    {
        $this->dispatchAction($id, false);
    }

    /**
     * Read-only import preview (dry-run) for a discovered package: reports what
     * an import would create/update/set/skip without performing any write.
     */
    public function previewPackage(string $id): void
    {
        if (! cms_can('themes.import')) {
            abort(403);
        }

        $importer = app('cms.demo_importer');
        $packages = $importer->discover();

        if (! isset($packages[$id])) {
            Notification::make()->title(tn_trans('Demo package not found.'))->danger()->send();

            return;
        }

        $package = $packages[$id];

        if ($this->inactiveThemePackage($package)) {
            Notification::make()->title(tn_trans('Only the active theme\'s demo presets can be imported.'))->danger()->send();

            return;
        }

        $plan = $importer->preview($package);

        if (! $plan['ok']) {
            Notification::make()
                ->title(tn_trans('Demo requirements not met.'))
                ->body(implode("\n", $plan['preflight']))
                ->danger()
                ->send();

            return;
        }

        $lines = [];
        foreach (array_slice($plan['actions'], 0, 14) as $action) {
            $lines[] = strtoupper($action['action']).' — '.$action['file'].': '.$action['detail'];
        }
        if (count($plan['actions']) > 14) {
            $lines[] = '… '.(count($plan['actions']) - 14).' '.tn_trans('more');
        }
        foreach (array_slice($plan['warnings'], 0, 4) as $warning) {
            $lines[] = '⚠ '.$warning;
        }

        Notification::make()
            ->title($plan['reimport'] ? tn_trans('Preview (re-import)') : tn_trans('Preview (first import)'))
            ->body($lines !== [] ? implode("\n", $lines) : tn_trans('Nothing to import.'))
            ->info()
            ->send();
    }

    /**
     * Reset a previously imported package by its id ("type:owner:slug").
     */
    public function resetPackage(string $id): void
    {
        $this->dispatchAction($id, true);
    }

    private function dispatchAction(string $id, bool $reset): void
    {
        if (! cms_can('themes.import')) {
            abort(403);
        }

        $importer = app('cms.demo_importer');
        $packages = $importer->discover();

        if (! isset($packages[$id])) {
            Notification::make()->title(tn_trans('Demo package not found.'))->danger()->send();

            return;
        }

        $package = $packages[$id];

        // Active-theme scoping (CORE-THEME-2): importing an inactive theme's
        // preset is blocked; reset stays allowed so a prior import can always
        // be cleaned up after a theme switch.
        if (! $reset && $this->inactiveThemePackage($package)) {
            Notification::make()->title(tn_trans('Only the active theme\'s demo presets can be imported.'))->danger()->send();

            return;
        }

        $this->notify($reset ? $importer->reset($package) : $importer->import($package));
    }

    private function inactiveThemePackage(DemoPackage $package): bool
    {
        return $package->type === DemoPackage::TYPE_THEME
            && $package->owner !== app('cms.theme')->activeSlug();
    }

    private function notify(ImportResult $result): void
    {
        if (! $result->success) {
            Notification::make()
                ->title($result->message)
                ->body($result->errors !== [] ? implode("\n", $result->errors) : null)
                ->danger()
                ->send();

            return;
        }

        $title = $result->message;
        if ($result->batchId !== null && $result->batchId !== '') {
            $title .= ' ('.$result->batchId.')';
        }

        Notification::make()
            ->title($title)
            ->body($result->warnings !== [] ? implode("\n", array_slice($result->warnings, 0, 6)) : null)
            ->success()
            ->send();
    }

    /**
     * Inline a package preview image as a data URI (so it renders without
     * publishing assets), or null when none / too large / unsafe.
     */
    private function previewDataUri(DemoPackage $package): ?string
    {
        $path = $package->previewImagePath();

        if ($path === null || ! is_file($path) || filesize($path) > self::PREVIEW_MAX_BYTES) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        $data = @file_get_contents($path);

        if ($data === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($data);
    }

    private function provenanceKey(string $owner, string $slug): string
    {
        return 'demo.imports.'.$owner.'.'.$slug;
    }
}
