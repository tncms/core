<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Services\PluginLifecycleManager;
use TheNguyen\CMS\Support\Plugin;

/**
 * Plugins → Installed Plugins (v1.0.0-beta.1).
 *
 * Admin UI over the existing Extension Framework, rendered with a native Filament
 * Table (array records — plugins are not Eloquent models). It lists valid
 * discovered plugins (name, description, status) with Activate / Deactivate / View
 * details row actions, and surfaces invalid plugin folders below. It never
 * installs, edits, or deletes plugin files — it only toggles the active registry
 * stored in `cms_settings`.
 *
 * Filament-native UI is used deliberately: a custom Blade/Tailwind layout renders
 * unstyled because the panel ships a precompiled stylesheet that only includes the
 * utilities Filament itself uses, not arbitrary classes written in a page view.
 * The Table builder, badges, and modal infolist are all part of that bundle.
 *
 * Because plugin routes are registered at application boot, a route change only
 * takes effect on the next request; activation/deactivation best-effort runs
 * `optimize:clear`.
 */
class InstalledPluginsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'plugins';

    protected static ?string $navigationLabel = 'Installed Plugins';

    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Installed Plugins';

    protected string $view = 'filament.admin.pages.installed-plugins-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Installed Plugins');
    }

    public function getTitle(): string
    {
        return tn_trans('Installed Plugins');
    }

    public static function canAccess(): bool
    {
        return cms_can('plugins.view');
    }

    public function getSubheading(): ?string
    {
        return tn_trans('Manage plugins discovered from the plugins/ directory.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('installPlugin')
                ->label(tn_trans('Install Plugin'))
                ->icon('heroicon-m-arrow-up-tray')
                ->visible(fn (): bool => cms_can('plugins.install'))
                ->url(InstallPluginPage::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, ?array $filters): Collection {
                $statusFilter = $filters['status']['value'] ?? null;
                $needle = is_string($search) ? trim(mb_strtolower($search)) : '';

                return collect($this->getPlugins())
                    ->when(
                        in_array($statusFilter, ['active', 'inactive'], true),
                        fn (Collection $rows): Collection => $rows->where('status', $statusFilter),
                    )
                    ->when(
                        $needle !== '',
                        fn (Collection $rows): Collection => $rows->filter(fn (array $row): bool => str_contains(
                            mb_strtolower($row['name'].' '.$row['description'].' '.$row['author']),
                            $needle,
                        )),
                    )
                    ->keyBy('slug');
            })
            ->columns([
                TextColumn::make('name')
                    ->label(tn_trans('Plugin'))
                    ->weight(FontWeight::Bold)
                    ->searchable(),

                TextColumn::make('description')
                    ->label(tn_trans('Description'))
                    ->wrap()
                    ->placeholder(tn_trans('No description provided.'))
                    ->description(fn (array $record): string => tn_trans('Version :version · By :author', ['version' => $record['version'], 'author' => $record['author']]))
                    ->searchable(),

                TextColumn::make('status')
                    ->label(tn_trans('Status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => tn_trans(ucfirst($state))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(tn_trans('Status'))
                    ->options([
                        'active' => tn_trans('Active'),
                        'inactive' => tn_trans('Inactive'),
                    ]),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label(tn_trans('Activate'))
                    ->icon('heroicon-m-bolt')
                    ->color('primary')
                    ->link()
                    ->visible(fn (array $record): bool => $record['status'] === 'inactive' && cms_can('plugins.activate'))
                    ->action(fn (array $record): mixed => $this->activate($record['slug'])),

                Action::make('deactivate')
                    ->label(tn_trans('Deactivate'))
                    ->icon('heroicon-m-power')
                    ->color('danger')
                    ->link()
                    ->visible(fn (array $record): bool => $record['status'] === 'active' && cms_can('plugins.activate'))
                    ->action(fn (array $record): mixed => $this->deactivate($record['slug'])),

                Action::make('details')
                    ->label(tn_trans('View details'))
                    ->icon('heroicon-m-information-circle')
                    ->color('gray')
                    ->link()
                    ->modalHeading(fn (array $record): string => tn_trans(':name — developer details', ['name' => $record['name']]))
                    ->modalIcon('heroicon-o-information-circle')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(tn_trans('Close'))
                    ->schema($this->detailsSchema()),

                // Delete is offered only for INACTIVE plugins (active plugins have
                // no Delete action). The installer re-checks active state too.
                Action::make('delete')
                    ->label(tn_trans('Delete'))
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->link()
                    ->visible(fn (array $record): bool => $record['status'] === 'inactive' && cms_can('plugins.delete'))
                    ->requiresConfirmation()
                    ->modalHeading(tn_trans('Delete plugin?'))
                    ->modalDescription(fn (array $record): string => tn_trans('This will permanently delete the plugin files from plugins/:slug. This cannot be undone.', ['slug' => $record['slug']]))
                    ->modalSubmitActionLabel(tn_trans('Delete plugin'))
                    ->action(fn (array $record): mixed => $this->deletePlugin($record['slug'])),
            ])
            ->paginated(false)
            ->emptyStateIcon('heroicon-o-puzzle-piece')
            ->emptyStateHeading(fn (): string => $this->hasActiveTableFilterOrSearch()
                ? tn_trans('No plugins match your search.')
                : tn_trans('No plugins installed.'))
            ->emptyStateDescription(fn (): ?string => $this->hasActiveTableFilterOrSearch()
                ? tn_trans('Try a different search term or filter.')
                : tn_trans('Add a plugin folder under plugins/ containing a valid plugin.json and refresh this page.'));
    }

    /**
     * Read-only developer details shown in the "View details" modal. None of
     * these technical fields appear in the table itself.
     *
     * @return array<int, TextEntry>
     */
    private function detailsSchema(): array
    {
        return [
            TextEntry::make('slug')
                ->label(tn_trans('Slug'))
                ->state(fn (array $record): string => $record['slug']),

            TextEntry::make('requiresTncms')
                ->label(tn_trans('Requires TN CMS'))
                ->state(fn (array $record): string => $record['requiresTncms'] ?? '—'),

            TextEntry::make('providersCount')
                ->label(tn_trans('Providers'))
                ->state(fn (array $record): string => (string) $record['providersCount']),

            TextEntry::make('providers')
                ->label(tn_trans('Provider classes'))
                ->state(fn (array $record): array => $record['providers'])
                ->listWithLineBreaks()
                ->bulleted()
                ->visible(fn (array $record): bool => $record['providersCount'] > 0),

            TextEntry::make('supportEmail')
                ->label(tn_trans('Support email'))
                ->state(fn (array $record): ?string => $record['supportEmail'])
                ->visible(fn (array $record): bool => filled($record['supportEmail'])),

            TextEntry::make('path')
                ->label(tn_trans('Path'))
                ->state(fn (array $record): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $record['path'])),

            TextEntry::make('providerWarning')
                ->label(tn_trans('Provider warning'))
                ->state(fn (array $record): ?string => $record['providerWarning'])
                ->color('warning')
                ->visible(fn (array $record): bool => filled($record['providerWarning'])),
        ];
    }

    private function hasActiveTableFilterOrSearch(): bool
    {
        return filled($this->getTableSearch())
            || filled($this->tableFilters['status']['value'] ?? null);
    }

    /**
     * Valid discovered plugins with metadata + active status, as table rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlugins(): array
    {
        $activeSlugs = $this->manager()->activePluginSlugs();

        return array_map(function (Plugin $plugin) use ($activeSlugs): array {
            $isActive = in_array($plugin->slug, $activeSlugs, true);

            // Delegate provider availability to the ExtensionManager, which uses
            // the SAME PSR-4 autoload logic as runtime boot (it registers the
            // plugin's autoloader before class_exists). This is reliable even
            // immediately after activating a plugin that was inactive when the
            // request booted — no false "Provider class not found" warning.
            $warnings = $this->manager()->pluginProviderWarnings($plugin);

            return [
                'name' => $plugin->name,
                'slug' => $plugin->slug,
                'version' => $plugin->version,
                'author' => $plugin->author,
                'description' => $plugin->description,
                'homepage' => $plugin->homepage,
                'authorUri' => $plugin->authorUri,
                'supportEmail' => $plugin->supportEmail,
                'path' => $plugin->path,
                'requiresTncms' => $this->requiresTncms($plugin),
                'providers' => $plugin->providers,
                'providersCount' => count($plugin->providers),
                'isActive' => $isActive,
                'status' => $isActive ? 'active' : 'inactive',
                'providerWarning' => $warnings[0] ?? null,
            ];
        }, $this->manager()->plugins());
    }

    /**
     * Plugin folders with a missing/invalid manifest, surfaced below the table.
     *
     * @return array<int, array{slug: string, reason: string}>
     */
    public function getInvalidPlugins(): array
    {
        return $this->manager()->invalidPlugins();
    }

    public function activate(string $slug): void
    {
        if (! cms_can('plugins.activate')) {
            $this->denied();

            return;
        }

        try {
            // Full lifecycle: the plugin's database is installed BEFORE it is
            // marked active, so its dashboard never 500s on a missing table.
            $result = $this->lifecycle()->activate($slug);
        } catch (\Throwable $e) {
            Notification::make()
                ->title(tn_trans('Plugin ":slug" could not be activated', ['slug' => $slug]))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($result->success) {
            $this->afterRegistryChange();

            Notification::make()
                ->title($result->alreadyActive
                    ? tn_trans('Plugin ":slug" is already active', ['slug' => $slug])
                    : tn_trans('Plugin ":slug" activated', ['slug' => $slug]))
                ->body(tn_trans('Its database is installed. Route changes take effect on the next request. Caches were cleared.'))
                ->success()
                ->send();

            return;
        }

        // Installation failed → the plugin stays inactive and the error is
        // recorded so the operator can review it / retry from the plugin page.
        Notification::make()
            ->title(tn_trans('Plugin installation incomplete'))
            ->body(tn_trans('Plugin ":slug" was not activated: :error', [
                'slug' => $slug,
                'error' => $result->error ?? $result->message,
            ]))
            ->danger()
            ->persistent()
            ->send();
    }

    public function deactivate(string $slug): void
    {
        if (! cms_can('plugins.activate')) {
            $this->denied();

            return;
        }

        $ok = false;

        try {
            // Disable only — migrations are never rolled back on deactivate.
            $ok = $this->lifecycle()->deactivate($slug);
        } catch (\Throwable) {
            $ok = false;
        }

        if ($ok) {
            $this->afterRegistryChange();

            Notification::make()
                ->title(tn_trans('Plugin ":slug" deactivated', ['slug' => $slug]))
                ->body(tn_trans('Route changes take effect on the next request. Caches were cleared.'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(tn_trans('Plugin ":slug" could not be deactivated', ['slug' => $slug]))
            ->body(tn_trans('The plugin was not active. It remains unchanged.'))
            ->danger()
            ->send();
    }

    /**
     * Permanently delete an inactive plugin's files (delegates to the installer,
     * which re-validates the slug, the active state, and the resolved path).
     */
    public function deletePlugin(string $slug): void
    {
        if (! cms_can('plugins.delete')) {
            $this->denied();

            return;
        }

        $result = extension_installer()->deletePlugin($slug);

        if ($result->success) {
            Notification::make()
                ->title(tn_trans('Plugin deleted'))
                ->body($result->message)
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(tn_trans('Plugin not deleted'))
            ->body($result->message)
            ->danger()
            ->send();
    }

    /**
     * After the active-plugin registry changes, clear caches so plugin routes
     * re-register on the next request. Best-effort and never throws — the
     * registry change has already been persisted by the ExtensionManager.
     */
    private function afterRegistryChange(): void
    {
        try {
            settings()->clearCache();
        } catch (\Throwable) {
            // best-effort
        }

        try {
            // Non-destructive: clears config/route/view/event/compiled caches so
            // the next request reflects the new active-plugin set.
            Artisan::call('optimize:clear');
        } catch (\Throwable) {
            // If clearing fails (e.g. permissions), the operator can run
            // `php artisan optimize:clear` manually.
        }
    }

    private function requiresTncms(Plugin $plugin): ?string
    {
        $value = $plugin->requires['tncms'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Notify the user that they lack permission for the requested action.
     * Defense-in-depth: the actions are already hidden when unauthorized.
     */
    private function denied(): void
    {
        Notification::make()
            ->title(tn_trans('Permission denied'))
            ->body(tn_trans('You do not have permission to perform this action.'))
            ->danger()
            ->send();
    }

    private function manager(): ExtensionManager
    {
        /** @var ExtensionManager $manager */
        $manager = app('cms.extension');

        return $manager;
    }

    private function lifecycle(): PluginLifecycleManager
    {
        /** @var PluginLifecycleManager $lifecycle */
        $lifecycle = app('cms.plugin_lifecycle');

        return $lifecycle;
    }
}
