<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Sitemap\SitemapManager;
use TheNguyen\CMS\Sitemap\SitemapSettings;
use Throwable;

/**
 * SEO → XML Sitemap (CORE-SITEMAP-ADMIN-R1).
 *
 * A thin admin surface over the existing engine: settings persist through
 * {@see SitemapSettings}, provider rows come from the Core registry, the
 * overview reads cached manifests only (it never builds or enumerates), and
 * diagnostics / cache refresh are separate, explicitly permitted actions.
 * Every mutation re-checks its permission server-side, so calling a Livewire
 * method directly cannot bypass the hidden UI.
 */
class SitemapPage extends Page
{
    protected static ?string $slug = 'xml-sitemap';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'SEO';

    protected string $view = 'filament.admin.pages.xml-sitemap';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return cms_can('sitemap.view');
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('XML Sitemap');
    }

    public function getTitle(): string
    {
        return tn_trans('XML Sitemap');
    }

    public function mount(): void
    {
        $this->form->fill($this->settings()->formState());
    }

    protected function canManage(): bool
    {
        return cms_can('sitemap.manage');
    }

    public function form(Schema $schema): Schema
    {
        $readOnly = ! $this->canManage();

        return $schema
            ->statePath('data')
            ->components([
                Section::make(tn_trans('General settings'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(tn_trans('Enable XML sitemap'))
                            ->helperText(tn_trans('When off, /sitemap.xml and its child files answer 404 and robots.txt stops advertising them. Pages stay indexable.'))
                            ->disabled($readOnly),
                        TextInput::make('chunk_size')
                            ->label(tn_trans('Sitemap URLs per file'))
                            ->helperText(tn_trans('Each child sitemap lists at most this many URLs (500–50,000).'))
                            ->integer()
                            ->minValue(SitemapManager::MIN_CHUNK)
                            ->maxValue(50000)
                            ->required()
                            ->disabled($readOnly),
                        Placeholder::make('indexing_policy')
                            ->label(tn_trans('Search engine visibility'))
                            ->content(fn (): HtmlString => $this->indexingPolicyNote()),
                    ]),
                Section::make(tn_trans('Content types'))
                    ->description(tn_trans('Excluding a type removes its URLs from the sitemap only. It does not hide the pages or mark them noindex. The home page is listed unless the page shown as the homepage is set to noindex.'))
                    ->columns(2)
                    ->schema(array_map(
                        fn (string $type): Toggle => Toggle::make('types.'.$type)
                            ->label($this->typeLabel($type))
                            ->disabled($readOnly),
                        SitemapSettings::TOGGLEABLE_TYPES,
                    )),
                Section::make(tn_trans('Plugin providers'))
                    ->description(tn_trans('Excluding a provider removes its URLs from the sitemap. The plugin stays active and its pages stay public.'))
                    ->schema($this->providerToggles($readOnly)),
            ]);
    }

    /** @return list<Toggle|Placeholder> */
    private function providerToggles(bool $readOnly): array
    {
        $toggles = [];

        foreach ($this->settings()->providerRows() as $row) {
            if (! $row['excludable']) {
                continue;
            }

            $toggles[] = Toggle::make('providers.'.$row['id'])
                ->label($row['name'].' ('.$row['id'].')')
                ->helperText($row['registered']
                    ? ($row['active'] ? tn_trans('Registered · plugin active') : tn_trans('Registered · plugin inactive'))
                    : tn_trans('Not registered (plugin inactive or removed). The stored exclusion is kept until you include it again.'))
                ->disabled($readOnly);
        }

        if ($toggles === []) {
            $toggles[] = Placeholder::make('no_providers')
                ->hiddenLabel()
                ->content(tn_trans('No plugin has registered a sitemap provider.'));
        }

        return $toggles;
    }

    public function save(): void
    {
        abort_unless($this->canManage(), 403);

        $input = $this->submitted();
        $known = array_column($this->settings()->providerRows(), 'id');
        $errors = $this->settings()->validate($input, $known);

        if ($errors !== []) {
            foreach ($errors as $field => $message) {
                $this->addError('data.'.$field, $message);
            }

            Notification::make()->title(tn_trans('Please fix the highlighted fields.'))->danger()->send();

            return;
        }

        $result = $this->settings()->save($input);
        $this->form->fill($this->settings()->formState());

        if (! $result['invalidated']) {
            Notification::make()
                ->title(tn_trans('Settings saved, but the sitemap cache could not be invalidated.'))
                ->body(tn_trans('Exclusions already apply. Other changes take effect when the cached manifests expire or after a successful cache refresh.'))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($result['changes'] === [] ? tn_trans('No changes to save.') : tn_trans('Sitemap settings saved.'))
            ->success()
            ->send();
    }

    /**
     * The raw submitted state limited to the known top-level keys; unknown
     * nested keys are kept so validation can refuse a forged identifier.
     *
     * @return array<string, mixed>
     */
    private function submitted(): array
    {
        $data = is_array($this->data) ? $this->data : [];
        $input = array_intersect_key($data, array_flip(['enabled', 'chunk_size', 'types', 'providers']));

        if (isset($input['chunk_size']) && is_string($input['chunk_size'])) {
            $input['chunk_size'] = trim($input['chunk_size']);
        }

        return $input;
    }

    public function runDiagnosticsNow(): void
    {
        abort_unless(cms_can('sitemap.diagnostics'), 403);

        try {
            $report = app('cms.sitemap_diagnostics')->run();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(tn_trans('Diagnostics could not run.'))->danger()->send();

            return;
        }

        Notification::make()
            ->title(match ($report['status']) {
                'pass' => tn_trans('Diagnostics passed.'),
                'busy' => tn_trans('Diagnostics are already running. Try again shortly.'),
                'warn' => tn_trans('Diagnostics finished with warnings.'),
                default => tn_trans('Diagnostics found problems.'),
            })
            ->status(match ($report['status']) {
                'pass' => 'success',
                'fail' => 'danger',
                default => 'warning',
            })
            ->send();
    }

    public function refreshCacheNow(): void
    {
        abort_unless(cms_can('sitemap.cache_refresh'), 403);

        try {
            $ids = app('cms.sitemap')->refreshAll();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(tn_trans('The sitemap cache could not be refreshed.'))->danger()->send();

            return;
        }

        \Illuminate\Support\Facades\Log::info('TN CMS sitemap cache refreshed', ['user_id' => auth()->id(), 'providers' => $ids]);

        Notification::make()
            ->title(tn_trans('Sitemap cache invalidated.'))
            ->body(tn_trans('The sitemap is rebuilt on the next request. Until then the last good version is served.'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runDiagnostics')
                ->label(tn_trans('Run diagnostics'))
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => cms_can('sitemap.diagnostics'))
                ->action(fn () => $this->runDiagnosticsNow()),
            Action::make('refreshCache')
                ->label(tn_trans('Refresh sitemap cache'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => cms_can('sitemap.cache_refresh'))
                ->requiresConfirmation()
                ->modalDescription(tn_trans('Only sitemap manifests are invalidated. Sessions, page cache and plugin data are not touched.'))
                ->action(fn () => $this->refreshCacheNow()),
        ];
    }

    /**
     * Overview numbers, all from cached state. URL figures are an upper bound
     * (child files × URLs per file): exact counts would require enumerating
     * every URL, which this page never does.
     *
     * @return array<string, mixed>
     */
    protected function overview(): array
    {
        $manager = app('cms.sitemap');
        $rows = $this->providerRows();
        $plugins = array_values(array_filter($rows, static fn (array $r): bool => $r['excludable']));
        $served = array_values(array_filter($rows, static fn (array $r): bool => $r['registered'] && $r['active'] && $r['included']));
        $known = array_values(array_filter($served, static fn (array $r): bool => $r['children'] !== null));
        $children = array_sum(array_column($known, 'children'));
        $built = array_filter(array_column($rows, 'built_at'));
        $last = app('cms.sitemap_diagnostics')->last();
        $base = rtrim((string) config('app.url'), '/');

        return [
            'enabled' => $manager->enabled(),
            'setting_enabled' => $this->settings()->enabled(),
            'site_indexable' => app('cms.seo')->siteIndexable(),
            'index_url' => $base.'/sitemap.xml',
            'robots_url' => $base.'/robots.txt',
            'registered' => count(array_filter($plugins, static fn (array $r): bool => $r['registered'])),
            'active' => count(array_filter($plugins, static fn (array $r): bool => $r['registered'] && $r['active'] && $r['included'])),
            'excluded' => count(array_filter($plugins, static fn (array $r): bool => ! $r['included'])),
            'children' => $known === [] ? null : $children,
            'children_complete' => count($known) === count($served),
            'url_capacity' => $known === [] ? null : $children * $manager->chunkSize(),
            'last_built' => $built === [] ? null : max($built),
            'last_refresh' => $manager->lastRefresh(),
            'health' => $last['status'] ?? null,
            'health_at' => $last['ran_at'] ?? null,
            'failures' => array_values(array_filter($rows, static fn (array $r): bool => ($r['failed_at'] ?? 0) > ($r['built_at'] ?? 0))),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function providerRows(): array
    {
        return $this->providerRowsCache ??= $this->settings()->providerRows();
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $providerRowsCache = null;

    /** @return array<string, mixed>|null */
    protected function lastDiagnostics(): ?array
    {
        return app('cms.sitemap_diagnostics')->last();
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'pages' => tn_trans('Pages'),
            'posts' => tn_trans('Posts'),
            'categories' => tn_trans('Categories'),
            'tags' => tn_trans('Tags'),
            default => $type,
        };
    }

    private function indexingPolicyNote(): HtmlString
    {
        $indexable = app('cms.seo')->siteIndexable();
        try {
            $link = '<a href="'.e(SettingsPage::getUrl()).'" class="underline">'.e(tn_trans('Settings → Reading')).'</a>';
        } catch (Throwable) {
            $link = '<em>'.e(tn_trans('Settings → Reading')).'</em>';
        }

        return new HtmlString(
            '<strong>'.e($indexable ? tn_trans('Visible') : tn_trans('Discouraged')).'</strong> — '
            .e($indexable
                ? tn_trans('Search engines may index the site.')
                : tn_trans('The site discourages indexing, so the sitemap is not served regardless of this page.'))
            .' '.tn_trans('Change this under :location.', ['location' => $link])
        );
    }

    private function settings(): SitemapSettings
    {
        return app('cms.sitemap_settings');
    }
}
