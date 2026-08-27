<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Resources\PostResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use TheNguyen\CMS\Services\MaintenanceManager;
use TheNguyen\CMS\Services\PermalinkManager;
use TheNguyen\CMS\Services\SettingsManager;

/**
 * CMS Settings — tabbed page (v0.9.6 Settings Polish).
 *
 * Sections: General, Reading, Writing, Media, SEO, Permalinks. The form uses
 * a FLAT state array (one key per field) which is mapped to the dotted
 * cms_settings keys on save (see {@see editable()}). This keeps nested
 * components (MediaPicker for favicon / OG image) working without dotted
 * statePath gymnastics.
 */
class SettingsPage extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?string $navigationLabel = 'Settings';

    protected static string|\UnitEnum|null $navigationGroup = 'CMS';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'CMS Settings';

    protected string $view = 'filament.admin.pages.settings-page';

    public static function canAccess(): bool
    {
        return cms_can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return tn_trans('Settings');
    }

    public function getTitle(): string
    {
        return tn_trans('CMS Settings');
    }

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Memoised page-select options (queried once per request).
     *
     * @var array<int, string>|null
     */
    private ?array $pageOptionsCache = null;

    public function mount(): void
    {
        $s = $this->settings();
        // Localized settings are content: open the editor in the content EDITING
        // locale (v1.0.0-beta.7.1.10.2), not the admin UI language. The
        // settings_locale switcher can still change it per session.
        $locale = $this->normalizeEditLocale(editing_locale());
        $values = ['settings_locale' => $locale];

        foreach ($this->editable() as [$flat, $key, $type, $default]) {
            $values[$flat] = $s->isLocalized($key)
                ? $this->localizedFieldValue($s, $key, $locale)
                : $s->get($key, $default);
        }

        // Booleans must be real bools for Toggles; ints for numeric inputs.
        foreach ($this->editable() as [$flat, , $type]) {
            if ($type === 'boolean') {
                $values[$flat] = (bool) $values[$flat];
            } elseif ($type === 'array' && ! is_array($values[$flat])) {
                $values[$flat] = [];
            }
        }

        $this->data = $values;
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('settings_locale')
                    ->label(tn_trans('Language'))
                    ->options(app('cms.language')->optionList())
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $this->reloadLocalizedFields($set, $state))
                    ->helperText(tn_trans('Only translatable fields (site name, tagline, description, SEO meta, maintenance text) follow this language. Other settings are global.'))
                    ->columnSpanFull(),
                Tabs::make('settings')
                    ->columnSpanFull()
                    ->tabs(array_values(array_filter([
                        $this->generalTab(),
                        $this->readingTab(),
                        $this->writingTab(),
                        $this->mediaTab(),
                        $this->seoTab(),
                        $this->permalinksTab(),
                        $this->optimizeTab(),
                        $this->canManageMaintenance() ? $this->maintenanceTab() : null,
                    ]))),
            ])
            ->statePath('data');
    }

    private function generalTab(): Tab
    {
        return Tab::make('General')
            ->label(tn_trans('General'))
            ->icon('heroicon-o-identification')
            ->schema([
                TextInput::make('general_site_name')->label(tn_trans('Site name'))->required()->maxLength(255),
                TextInput::make('general_site_tagline')->label(tn_trans('Tagline'))->maxLength(255),
                Textarea::make('general_site_description')->label(tn_trans('Site description'))->rows(3),
                TextInput::make('general_admin_email')->label(tn_trans('Admin email'))->email()->maxLength(255),
                Select::make('general_timezone')
                    ->label(tn_trans('Timezone'))
                    ->options($this->timezoneOptions())
                    ->searchable()
                    ->native(false),
                TextInput::make('general_date_format')
                    ->label(tn_trans('Date format'))
                    ->helperText(tn_trans('PHP date() format, e.g. d/m/Y or F j, Y.'))
                    ->maxLength(50),
                TextInput::make('general_time_format')
                    ->label(tn_trans('Time format'))
                    ->helperText(tn_trans('PHP date() format, e.g. H:i or g:i a.'))
                    ->maxLength(50),
                TextInput::make('admin_brand_name')->label(tn_trans('Admin brand name'))->maxLength(255),
                MediaPicker::make('general_favicon', tn_trans('Favicon')),
            ])
            ->columns(1);
    }

    private function readingTab(): Tab
    {
        return Tab::make('Reading')
            ->label(tn_trans('Reading'))
            ->icon('heroicon-o-book-open')
            ->schema([
                Select::make('reading_homepage_display')
                    ->label(tn_trans('Homepage displays'))
                    ->options([
                        'latest_posts' => tn_trans('Your latest posts'),
                        'static_page' => tn_trans('A static page'),
                    ])
                    ->placeholder(tn_trans('Default — keep current homepage'))
                    ->helperText(tn_trans('Leave on "Default" to keep the existing homepage behaviour.'))
                    ->live()
                    ->native(false),
                Select::make('reading_homepage_page_id')
                    ->label(tn_trans('Homepage'))
                    ->options($this->pageOptions())
                    ->searchable()
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('reading_homepage_display') === 'static_page'),
                Select::make('reading_posts_page_id')
                    ->label(tn_trans('Posts page'))
                    ->options($this->pageOptions())
                    ->searchable()
                    ->native(false)
                    ->helperText(tn_trans('The page that lists your blog posts (stored for theme use).'))
                    ->visible(fn (Get $get): bool => $get('reading_homepage_display') === 'static_page'),
                TextInput::make('reading_posts_per_page')->label(tn_trans('Posts per page'))->numeric()->minValue(1)->maxValue(100),
                TextInput::make('reading_feed_items_count')->label(tn_trans('Syndication feed items'))->numeric()->minValue(1)->maxValue(100),
                Select::make('reading_feed_content_mode')
                    ->label(tn_trans('Feed shows'))
                    ->options(['full' => tn_trans('Full text'), 'excerpt' => tn_trans('Excerpt')])
                    ->native(false),
                Toggle::make('reading_noindex_site')
                    ->label(tn_trans('Discourage search engines from indexing this site'))
                    ->live()
                    ->helperText(tn_trans('Sets meta robots to noindex,nofollow and robots.txt to Disallow: /. Also shown under the SEO tab.')),
            ])
            ->columns(1);
    }

    private function writingTab(): Tab
    {
        return Tab::make('Writing')
            ->label(tn_trans('Writing'))
            ->icon('heroicon-o-pencil-square')
            ->schema([
                Select::make('writing_default_post_status')
                    ->label(tn_trans('Default post status'))
                    ->options(['draft' => tn_trans('Draft'), 'published' => tn_trans('Published')])
                    ->native(false),
                Select::make('writing_default_comment_status')
                    ->label(tn_trans('Default comment status'))
                    ->options(['open' => tn_trans('Open'), 'closed' => tn_trans('Closed')])
                    ->native(false),
                Select::make('writing_default_category_id')
                    ->label(tn_trans('Default category'))
                    ->options($this->categoryOptions())
                    ->searchable()
                    ->native(false),
                Select::make('writing_default_language')
                    ->label(tn_trans('Default language'))
                    ->options(app('cms.language')->optionList())
                    ->native(false),
                Select::make('writing_default_post_format')
                    ->label(tn_trans('Default post format'))
                    ->options(['standard' => tn_trans('Standard')])
                    ->native(false),
            ])
            ->columns(1);
    }

    private function mediaTab(): Tab
    {
        return Tab::make('Media')
            ->label(tn_trans('Media'))
            ->icon('heroicon-o-photo')
            ->schema([
                Toggle::make('media_organize_uploads_by_date')
                    ->label(tn_trans('Organize uploads into year/month folders')),
                TextInput::make('media_max_upload_size_mb')->label(tn_trans('Max upload size (MB)'))->numeric()->minValue(1)->maxValue(512),
                Toggle::make('media_auto_alt_from_filename')->label(tn_trans('Auto-generate alt text from filename')),
                Toggle::make('media_auto_title_from_filename')->label(tn_trans('Auto-generate title from filename')),

                TextInput::make('media_thumb_w')->label(tn_trans('Thumbnail width'))->numeric()->minValue(0),
                TextInput::make('media_thumb_h')->label(tn_trans('Thumbnail height'))->numeric()->minValue(0),
                Toggle::make('media_thumb_crop')->label(tn_trans('Crop thumbnail to exact dimensions')),
                TextInput::make('media_medium_w')->label(tn_trans('Medium max width'))->numeric()->minValue(0),
                TextInput::make('media_medium_h')->label(tn_trans('Medium max height'))->numeric()->minValue(0),
                TextInput::make('media_large_w')->label(tn_trans('Large max width'))->numeric()->minValue(0),
                TextInput::make('media_large_h')->label(tn_trans('Large max height'))->numeric()->minValue(0),

                Placeholder::make('media_sizes_note')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="font-size:.8rem;opacity:.7;">'
                        .e(tn_trans('Image sizes and custom sizes are stored for future use (Page Builder / API / themes). Resized image generation is planned; existing uploads are unchanged.'))
                        .'</div>'
                    )),

                Repeater::make('media_custom_sizes')
                    ->label(tn_trans('Custom image sizes'))
                    ->schema([
                        TextInput::make('name')->label(tn_trans('Name'))->required()->maxLength(50),
                        TextInput::make('width')->label(tn_trans('Width'))->numeric()->minValue(0),
                        TextInput::make('height')->label(tn_trans('Height'))->numeric()->minValue(0),
                        Toggle::make('crop')->label(tn_trans('Crop')),
                    ])
                    ->columns(4)
                    ->addActionLabel(tn_trans('Add custom size'))
                    ->reorderable(false)
                    ->default([]),
            ])
            ->columns(1);
    }

    private function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->label(tn_trans('SEO'))
            ->icon('heroicon-o-magnifying-glass')
            ->schema([
                Placeholder::make('seo_visibility_note')
                    ->label(tn_trans('Search engine visibility'))
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        ((bool) $get('reading_noindex_site')
                            ? '<strong style="color:#b45309;">'.e(tn_trans('Discouraged')).'</strong> — '.e(tn_trans('robots = noindex,nofollow, robots.txt = Disallow: /.'))
                            : '<strong style="color:#15803d;">'.e(tn_trans('Visible')).'</strong> — '.e(tn_trans('robots = your default below.')))
                        .' '.tn_trans('Toggle this under :location.', ['location' => '<em>Settings → Reading</em>'])
                    )),
                TextInput::make('seo_title_separator')->label(tn_trans('Title separator'))->maxLength(10),
                TextInput::make('seo_default_meta_title')->label(tn_trans('Default meta title'))->maxLength(255),
                Textarea::make('seo_default_meta_description')->label(tn_trans('Default meta description'))->rows(3),
                Select::make('seo_robots_default')
                    ->label(tn_trans('Default robots'))
                    ->options([
                        'index,follow' => 'index, follow',
                        'noindex,nofollow' => 'noindex, nofollow',
                        'index,nofollow' => 'index, nofollow',
                        'noindex,follow' => 'noindex, follow',
                    ])
                    ->native(false),
                MediaPicker::make('seo_default_og_image', tn_trans('Default OG image')),
            ])
            ->columns(1);
    }

    private function permalinksTab(): Tab
    {
        $reserved = implode(', ', PermalinkManager::reserved());

        return Tab::make('Permalinks')
            ->label(tn_trans('Permalinks'))
            ->icon('heroicon-o-link')
            ->schema([
                Placeholder::make('permalink_warning')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="padding:.6rem .8rem;border-radius:9px;background:rgba(245,158,11,.12);'
                        .'border:1px solid rgba(245,158,11,.35);font-size:.82rem;line-height:1.45;">'
                        .tn_trans('Leave a base empty for base-less URLs (e.g. <code>/post-slug</code> instead of <code>/blog/post-slug</code>); the record then resolves through the generic <code>/{slug}</code> route. Empty bases are supported, but slugs must be globally unique per language — conflicts auto-increment (<code>slug-2</code>). Changing a base rebuilds existing links on save and may require a route cache clear (<code>php artisan route:clear</code>). Bases cannot use reserved prefixes: :reserved.', ['reserved' => e($reserved)])
                        .'</div>'
                    )),
                TextInput::make('permalink_post_base')
                    ->label(tn_trans('Post base'))
                    ->placeholder(PermalinkManager::DEFAULT_POST_BASE)
                    ->helperText(tn_trans('e.g. "blog" → /blog/post-slug. Leave empty for base-less /post-slug.'))
                    ->regex('/^[a-z0-9-]*$/'),
                TextInput::make('permalink_category_base')
                    ->label(tn_trans('Category base'))
                    ->placeholder(PermalinkManager::DEFAULT_CATEGORY_BASE)
                    ->helperText(tn_trans('e.g. "category" → /category/category-slug. Leave empty for base-less /category-slug.'))
                    ->regex('/^[a-z0-9-]*$/'),
                TextInput::make('permalink_tag_base')
                    ->label(tn_trans('Tag base'))
                    ->placeholder(PermalinkManager::DEFAULT_TAG_BASE)
                    ->helperText(tn_trans('e.g. "tag" → /tag/tag-slug. Leave empty for base-less /tag-slug.'))
                    ->regex('/^[a-z0-9-]*$/'),
            ])
            ->columns(1);
    }

    /**
     * Optimize — CMS Cache health center (CORE-OPTIMIZE-1 · CORE-OPTIMIZE-2). One
     * canonical toggle plus honest diagnostics over the single managed cache
     * domain (public_content). Deliberately narrow: it governs reusable CMS
     * performance caches only, never sessions, logins or Laravel infrastructure
     * caches. Targeted invalidation and scoped warm-up live in the page header
     * actions ({@see getHeaderActions()}).
     */
    private function optimizeTab(): Tab
    {
        return Tab::make('Optimize')
            ->label(tn_trans('Optimize'))
            ->icon('heroicon-o-bolt')
            ->schema([
                Placeholder::make('cms_cache_diagnostics')
                    ->label(tn_trans('CMS Cache'))
                    ->content(fn (): HtmlString => $this->renderCacheDiagnostics()),
                Toggle::make('optimize_cache_enabled')
                    ->label(tn_trans('Enable CMS Cache'))
                    ->helperText(tn_trans('Cache reusable TNCMS application data for performance. Disabling this bypasses CMS optimization caches but does not disable sessions or Laravel infrastructure caches.')),
                Placeholder::make('optimize_cache_note')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="font-size:.8rem;opacity:.7;line-height:1.5;">'
                        .e(tn_trans('CMS Cache stores reusable application data (such as resolved public URLs) to speed up the site. It does not control sessions, logins, rate limiting, or Laravel config/route/view caches. Use “Clear CMS Cache” above to invalidate cached CMS data safely at any time.'))
                        .'</div>'
                    )),

                // Frontend Optimization (CORE-OPTIMIZE-3). Safe browser response
                // header semantics for anonymous public pages, plus read-only
                // runtime diagnostics. Never applies to admin, logged-in, installer
                // or upgrade responses.
                Placeholder::make('optimize_runtime_diagnostics')
                    ->label(tn_trans('Frontend Optimization'))
                    ->content(fn (): HtmlString => $this->renderRuntimeDiagnostics()),
                Toggle::make('optimize_response_enabled')
                    ->label(tn_trans('Optimize public response headers'))
                    ->helperText(tn_trans('Add safe browser cache headers to anonymous public pages. Logged-in, admin, installer and update responses are never made publicly cacheable.')),
                TextInput::make('optimize_response_public_html_ttl')
                    ->label(tn_trans('Public page browser cache (seconds)'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(CmsOptimizationPolicy::TTL_MAX)
                    ->helperText(tn_trans('How long browsers may reuse an anonymous public page before revalidating. 0 means always revalidate. Applies only when public response optimization is on.')),
                Placeholder::make('optimize_response_note')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div style="font-size:.8rem;opacity:.7;line-height:1.5;">'
                        .e(tn_trans('Response optimization only sets browser (private) cache headers for anonymous pages; it never enables shared/CDN caching, never changes sessions or logins, and never caches admin, installer, or update pages. Long-term caching of fingerprinted assets is handled by your web server or CDN.'))
                        .'</div>'
                    )),
            ])
            ->columns(1);
    }

    /**
     * Render the read-only runtime optimization diagnostics from the canonical
     * runtime diagnostics authority (CORE-OPTIMIZE-3 §14). Recomputed on every
     * Livewire render, so "Refresh Diagnostics" needs only to re-render — it never
     * mutates. Exposes only safe values (status, TTL, capability flags); never a
     * path, credential or secret.
     */
    private function renderRuntimeDiagnostics(): HtmlString
    {
        $snap = app('cms.runtime_diagnostics')->snapshot();
        $response = $snap['response'] ?? [];
        $assets = $snap['static_assets'] ?? [];
        $media = $snap['media'] ?? [];

        $statusLabels = [
            'healthy' => tn_trans('Healthy'),
            'disabled' => tn_trans('Disabled'),
            'degraded' => tn_trans('Degraded'),
            'unavailable' => tn_trans('Unavailable'),
            'bypassed' => tn_trans('Bypassed'),
        ];
        $label = static fn (string $status): string => $statusLabels[$status] ?? $status;

        $rows = [
            [tn_trans('Response optimization'), $label((string) ($response['status'] ?? 'disabled'))],
            [tn_trans('Public page browser cache'), ((int) ($response['public_html_ttl'] ?? 0)).' '.tn_trans('seconds')],
            [tn_trans('Fingerprinted assets'), ($assets['fingerprinted'] ?? false) ? tn_trans('Detected') : tn_trans('Not detected')],
            [tn_trans('Media hints'), $label((string) ($media['status'] ?? 'unavailable')).' ('.tn_trans('theme-managed').')'],
        ];

        $cells = '';
        foreach ($rows as [$rowLabel, $value]) {
            $cells .= '<div style="display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid rgba(120,120,120,.15);">'
                .'<span style="opacity:.7;">'.e($rowLabel).'</span>'
                .'<span style="font-weight:600;">'.e($value).'</span>'
                .'</div>';
        }

        $warnings = (array) ($snap['environment']['warnings'] ?? []);
        $warningLabels = [
            'cache_store_non_persistent' => tn_trans('The active cache store is not persistent across requests, which limits CMS caching effectiveness.'),
        ];
        $notice = '';
        foreach ($warnings as $warning) {
            if (isset($warningLabels[$warning])) {
                $notice .= '<div style="font-size:.8rem;color:#b45309;line-height:1.5;margin-top:.5rem;">'.e($warningLabels[$warning]).'</div>';
            }
        }

        $scope = '<div style="font-size:.8rem;opacity:.7;line-height:1.5;margin-top:.6rem;">'
            .e(tn_trans('These figures describe Core response and asset optimization only. They never affect sessions, logins, updates, installer state, or plugin caches.'))
            .'</div>';

        return new HtmlString('<div style="font-size:.85rem;max-width:32rem;">'.$cells.$notice.$scope.'</div>');
    }

    /**
     * Render the read-only CMS Cache diagnostics table from the canonical
     * diagnostics authority (CORE-OPTIMIZE-2 §28). Recomputed on every Livewire
     * render, so "Refresh Diagnostics" needs only to re-render — it never mutates.
     * Exposes only safe, administrator-friendly values (status, driver name, TTL,
     * epoch, managed domain); never a path, credential or secret.
     */
    private function renderCacheDiagnostics(): HtmlString
    {
        $snap = app('cms.cache_diagnostics')->snapshot();
        $pc = $snap['public_cache'];

        $statusLabels = [
            'healthy' => tn_trans('Healthy'),
            'disabled' => tn_trans('Disabled'),
            'degraded' => tn_trans('Degraded'),
            'unavailable' => tn_trans('Unavailable'),
            'bypassed' => tn_trans('Bypassed'),
        ];
        $status = (string) ($snap['status'] ?? 'unavailable');

        $rows = [
            [tn_trans('Status'), $statusLabels[$status] ?? $status],
            [tn_trans('Effective state'), ($pc['effective_enabled'] ?? false) ? tn_trans('Active') : tn_trans('Bypassed')],
            [tn_trans('Cache Driver'), (string) ($snap['driver'] ?? 'unknown')],
            [tn_trans('Cache Lifetime'), ((int) ($pc['configured_ttl'] ?? 0)).' '.tn_trans('seconds')],
            [tn_trans('Cache Version'), (string) ($pc['epoch'] ?? 0)],
            [tn_trans('Managed Cache'), tn_trans('Public content')],
        ];

        $cells = '';
        foreach ($rows as [$label, $value]) {
            $cells .= '<div style="display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px solid rgba(120,120,120,.15);">'
                .'<span style="opacity:.7;">'.e($label).'</span>'
                .'<span style="font-weight:600;">'.e($value).'</span>'
                .'</div>';
        }

        $scope = '<div style="font-size:.8rem;opacity:.7;line-height:1.5;margin-top:.6rem;">'
            .e(tn_trans('These figures describe the TNCMS-managed public content cache only. Disabling or clearing it never affects sessions, logins, updates, installer state, or plugin caches.'))
            .'</div>';

        return new HtmlString('<div style="font-size:.85rem;max-width:32rem;">'.$cells.$scope.'</div>');
    }

    /**
     * Page header actions. "Clear CMS Cache" performs a targeted invalidation of
     * TNCMS optimization caches (a cache-version epoch bump), never a global
     * Cache::flush(). Authorized by the page's settings.manage gate and
     * CSRF-protected by Livewire; safe and idempotent whether the toggle is
     * ON or OFF.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshCmsCacheDiagnostics')
                ->label(tn_trans('Refresh Diagnostics'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    // Diagnostics recompute on every render; this action only
                    // re-renders and confirms. It never clears, rebuilds or mutates.
                    Notification::make()->title(tn_trans('Diagnostics refreshed'))->success()->send();
                }),

            Action::make('clearCmsCache')
                ->label(tn_trans('Clear CMS Cache'))
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(tn_trans('Clear CMS Cache'))
                ->modalDescription(tn_trans('Invalidate cached TNCMS application data. This does not affect sessions, logins, or Laravel infrastructure caches.'))
                ->action(function (): void {
                    $result = app('cms.cache_policy')->clearResult();

                    if ($result->success) {
                        Notification::make()->title(tn_trans('CMS cache cleared'))->success()->send();

                        return;
                    }

                    Notification::make()->title(tn_trans('Could not clear CMS cache'))->danger()->send();
                }),

            Action::make('rebuildCmsCache')
                ->label(tn_trans('Rebuild CMS Cache'))
                ->icon('heroicon-o-bolt')
                ->color('gray')
                // Only offered when a warm can do real, safe work (§32): never a
                // fake success. Hidden when CMS Cache is OFF or there is no corpus.
                ->visible(fn (): bool => app('cms.cache_policy')->rebuildSupported())
                ->requiresConfirmation()
                ->modalHeading(tn_trans('Rebuild CMS Cache'))
                ->modalDescription(tn_trans('Warms supported TNCMS public content cache entries from canonical CMS content. It does not affect search, plugins, sessions, or CDN caches.'))
                ->action(function (): void {
                    $result = app('cms.cache_policy')->rebuild();

                    if ($result->success) {
                        Notification::make()
                            ->title(tn_trans('CMS cache rebuilt'))
                            ->body(tn_trans(':count entries warmed.', ['count' => $result->processedCount]))
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(tn_trans('CMS cache rebuild incomplete'))
                        ->body(tn_trans(':ok warmed, :failed failed.', ['ok' => $result->processedCount, 'failed' => $result->failedCount]))
                        ->warning()
                        ->send();
                }),
        ];
    }

    private function maintenanceTab(): Tab
    {
        return Tab::make('Maintenance')
            ->label(tn_trans('Maintenance'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->schema([
                Toggle::make('maintenance_enabled')
                    ->label(tn_trans('Enable maintenance mode'))
                    ->helperText(tn_trans('When on, public visitors see a maintenance page. The admin panel and login stay accessible.'))
                    ->live(),
                Select::make('maintenance_mode')
                    ->label(tn_trans('Display mode'))
                    ->options([
                        MaintenanceManager::MODE_THEME => tn_trans('Theme maintenance view'),
                        MaintenanceManager::MODE_PAGE => tn_trans('Custom CMS page'),
                    ])
                    ->native(false)
                    ->live()
                    ->helperText(tn_trans('Theme view uses themes/{slug}/views/maintenance.blade.php (with a core fallback).')),
                Select::make('maintenance_page_id')
                    ->label(tn_trans('Maintenance page'))
                    ->options($this->pageOptions())
                    ->searchable()
                    ->native(false)
                    ->helperText(tn_trans('The published page rendered as the maintenance page.'))
                    ->visible(fn (Get $get): bool => $get('maintenance_mode') === MaintenanceManager::MODE_PAGE),
                TextInput::make('maintenance_title')
                    ->label(tn_trans('Title'))
                    ->maxLength(255),
                Textarea::make('maintenance_message')
                    ->label(tn_trans('Message'))
                    ->rows(3),
                Select::make('maintenance_status_code')
                    ->label(tn_trans('HTTP status code'))
                    ->options([
                        503 => tn_trans('503 Service Unavailable'),
                        200 => tn_trans('200 OK'),
                    ])
                    ->native(false)
                    ->live()
                    ->helperText(tn_trans('503 tells search engines the downtime is temporary (recommended).')),
                TextInput::make('maintenance_retry_after')
                    ->label(tn_trans('Retry after (minutes)'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(10080)
                    ->helperText(tn_trans('Sent as the Retry-After header. Leave 0 to omit.'))
                    ->visible(fn (Get $get): bool => (int) $get('maintenance_status_code') === 503),
                Toggle::make('maintenance_allow_admin_bypass')
                    ->label(tn_trans('Allow admin bypass'))
                    ->helperText(tn_trans('Users with the system.maintenance.bypass permission can view the site normally.')),
                Toggle::make('maintenance_allow_logged_in_bypass')
                    ->label(tn_trans('Allow logged-in user bypass'))
                    ->helperText(tn_trans('Any authenticated user can view the site normally.')),
                TagsInput::make('maintenance_allowed_ips')
                    ->label(tn_trans('Allowed IPs'))
                    ->placeholder(tn_trans('Add an IP and press Enter'))
                    ->helperText(tn_trans('One IP per tag. These IPs can bypass maintenance.')),
                TagsInput::make('maintenance_exclude_paths')
                    ->label(tn_trans('Excluded paths'))
                    ->placeholder(tn_trans('Add a path and press Enter'))
                    ->helperText(tn_trans('Paths that remain accessible, e.g. admin, livewire, robots.txt.')),
            ])
            ->columns(1);
    }

    /**
     * Maintenance settings additionally require system.maintenance.manage (when
     * RBAC is initialised). cms_can() fails open before RBAC is seeded, so the
     * owner/super admin is never locked out.
     */
    private function canManageMaintenance(): bool
    {
        return cms_can('system.maintenance.manage');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        if (! $this->validatePermalinks($state)) {
            return;
        }

        $settings = $this->settings();
        $permalink = app('cms.permalink');

        // Snapshot the effective bases so we can rebuild cms_slugs if they move.
        $basesBefore = [$permalink->postBase(), $permalink->categoryBase(), $permalink->tagBase()];

        $locale = $this->normalizeEditLocale($state['settings_locale'] ?? null);
        $defaultLocale = app('cms.language')->defaultCode();

        foreach ($this->editable() as [$flat, $key, $type]) {
            $value = $this->prepareValue($flat, $type, $state[$flat] ?? null);

            // Maintenance settings (allowed IPs / excluded paths) are operator
            // config and stay private; everything else remains publicly readable.
            $isPublic = ! str_starts_with($key, 'maintenance.');

            if ($settings->isLocalized($key)) {
                // Translatable field: write only the selected locale's row, so
                // saving one language never overwrites another.
                $settings->setLocalized($key, $locale, $value, $type);

                // Keep the global cms_settings value (the permanent fallback)
                // aligned with the default locale, so plain setting() and any
                // untranslated locale resolve to the default-language value.
                if ($locale === $defaultLocale) {
                    $settings->set($key, $value, $type, ['is_public' => $isPublic, 'autoload' => true]);
                }

                continue;
            }

            $settings->set($key, $value, $type, [
                'is_public' => $isPublic,
                'autoload' => true,
            ]);
        }

        $settings->clearCache();

        // A changed permalink base re-points every public URL; rebuild the
        // cms_slugs prefixes/full_paths so existing content resolves at the new
        // URLs without re-saving each record. Route registration reads the new
        // bases on the next request (run `php artisan route:clear` if cached).
        $basesAfter = [$permalink->postBase(), $permalink->categoryBase(), $permalink->tagBase()];

        if ($basesBefore !== $basesAfter) {
            app('cms.slug')->rebuildPublicSlugs();
        }

        Notification::make()->title(tn_trans('Settings saved'))->success()->send();
    }

    /**
     * Normalize a field value into the shape the SettingsManager expects.
     */
    private function prepareValue(string $flat, string $type, mixed $value): mixed
    {
        if ($type === 'boolean') {
            return (bool) $value;
        }

        if ($type === 'integer') {
            return (int) $value;
        }

        if ($flat === 'media_custom_sizes' && is_array($value)) {
            return array_values(array_map(static fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'width' => (int) ($row['width'] ?? 0),
                'height' => (int) ($row['height'] ?? 0),
                'crop' => (bool) ($row['crop'] ?? false),
            ], $value));
        }

        if (str_contains($flat, 'permalink_')) {
            return app('cms.permalink')->normalizeBase(is_string($value) ? $value : '');
        }

        return $value;
    }

    /**
     * Validate the three permalink bases (reserved + uniqueness). Pattern is
     * enforced inline by the field regex; this guards cross-field rules.
     *
     * @param  array<string, mixed>  $state
     */
    private function validatePermalinks(array $state): bool
    {
        $permalink = app('cms.permalink');

        $bases = [
            'Post base' => $permalink->normalizeBase((string) ($state['permalink_post_base'] ?? '')),
            'Category base' => $permalink->normalizeBase((string) ($state['permalink_category_base'] ?? '')),
            'Tag base' => $permalink->normalizeBase((string) ($state['permalink_tag_base'] ?? '')),
        ];

        foreach ($bases as $label => $base) {
            if ($base !== '' && $permalink->isReserved($base)) {
                $this->fail(tn_trans(':label ":base" is a reserved prefix and cannot be used.', [
                    'label' => tn_trans($label),
                    'base' => $base,
                ]));

                return false;
            }
        }

        $nonEmpty = array_filter($bases, static fn (string $b): bool => $b !== '');

        if (count($nonEmpty) !== count(array_unique($nonEmpty))) {
            $this->fail(tn_trans('Post, category and tag bases must be different when set.'));

            return false;
        }

        return true;
    }

    private function fail(string $message): void
    {
        Notification::make()->title(tn_trans('Could not save settings'))->body($message)->danger()->send();
    }

    /**
     * Editable field map: [flatStateKey, settingKey, type, default].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: mixed}>
     */
    private function editable(): array
    {
        $fields = [
            ['general_site_name', 'general.site_name', 'string', 'TN CMS'],
            ['general_site_tagline', 'general.site_tagline', 'string', ''],
            ['general_site_description', 'general.site_description', 'text', ''],
            ['general_admin_email', 'general.admin_email', 'string', ''],
            ['general_timezone', 'general.timezone', 'string', (string) config('app.timezone', 'UTC')],
            ['general_date_format', 'general.date_format', 'string', 'd/m/Y'],
            ['general_time_format', 'general.time_format', 'string', 'H:i'],
            ['general_favicon', 'general.favicon', 'string', ''],
            ['admin_brand_name', 'admin.brand_name', 'string', 'TN CMS'],

            // Empty default = "keep current homepage" (the controller treats
            // any value other than static_page/latest_posts as legacy). This
            // ensures saving the page never silently flips the homepage.
            ['reading_homepage_display', 'reading.homepage_display', 'string', ''],
            ['reading_homepage_page_id', 'reading.homepage_page_id', 'integer', 0],
            ['reading_posts_page_id', 'reading.posts_page_id', 'integer', 0],
            ['reading_posts_per_page', 'reading.posts_per_page', 'integer', 10],
            ['reading_feed_items_count', 'reading.feed_items_count', 'integer', 10],
            ['reading_feed_content_mode', 'reading.feed_content_mode', 'string', 'excerpt'],
            ['reading_noindex_site', 'seo.noindex_site', 'boolean', false],

            ['writing_default_post_status', 'writing.default_post_status', 'string', 'draft'],
            ['writing_default_comment_status', 'writing.default_comment_status', 'string', 'open'],
            ['writing_default_category_id', 'writing.default_category_id', 'integer', 0],
            ['writing_default_language', 'writing.default_language', 'string', app('cms.language')->defaultCode()],
            ['writing_default_post_format', 'writing.default_post_format', 'string', 'standard'],

            ['media_organize_uploads_by_date', 'media.organize_uploads_by_date', 'boolean', true],
            ['media_max_upload_size_mb', 'media.max_upload_size_mb', 'integer', 8],
            ['media_auto_alt_from_filename', 'media.auto_alt_from_filename', 'boolean', true],
            ['media_auto_title_from_filename', 'media.auto_title_from_filename', 'boolean', false],
            ['media_thumb_w', 'media.sizes.thumbnail.width', 'integer', 150],
            ['media_thumb_h', 'media.sizes.thumbnail.height', 'integer', 150],
            ['media_thumb_crop', 'media.sizes.thumbnail.crop', 'boolean', true],
            ['media_medium_w', 'media.sizes.medium.width', 'integer', 768],
            ['media_medium_h', 'media.sizes.medium.height', 'integer', 768],
            ['media_large_w', 'media.sizes.large.width', 'integer', 1536],
            ['media_large_h', 'media.sizes.large.height', 'integer', 1536],
            ['media_custom_sizes', 'media.custom_sizes', 'array', []],

            ['seo_title_separator', 'seo.title_separator', 'string', '|'],
            ['seo_default_meta_title', 'seo.default_meta_title', 'string', ''],
            ['seo_default_meta_description', 'seo.default_meta_description', 'text', ''],
            ['seo_default_og_image', 'seo.default_og_image', 'string', ''],
            ['seo_robots_default', 'seo.robots_default', 'string', 'index,follow'],

            ['permalink_post_base', 'permalink.post_base', 'string', PermalinkManager::DEFAULT_POST_BASE],
            ['permalink_category_base', 'permalink.category_base', 'string', PermalinkManager::DEFAULT_CATEGORY_BASE],
            ['permalink_tag_base', 'permalink.tag_base', 'string', PermalinkManager::DEFAULT_TAG_BASE],

            // Optimize — canonical CMS Cache toggle (CORE-OPTIMIZE-1). Default
            // enabled so existing sites keep caching until an admin opts out.
            ['optimize_cache_enabled', 'optimize.cache_enabled', 'boolean', true],

            // Frontend response optimization (CORE-OPTIMIZE-3). Default OFF and TTL
            // 0 (revalidate) so an upgraded site keeps its existing response
            // headers until an admin opts in.
            ['optimize_response_enabled', 'optimize.response.enabled', 'boolean', false],
            ['optimize_response_public_html_ttl', 'optimize.response.public_html_ttl', 'integer', 0],
        ];

        // Maintenance keys are only loaded/persisted when the user may manage
        // them, so the maintenance tab and the save loop stay in lockstep (a
        // field absent from the form must never be written back as null).
        if ($this->canManageMaintenance()) {
            array_push(
                $fields,
                ['maintenance_enabled', 'maintenance.enabled', 'boolean', false],
                ['maintenance_mode', 'maintenance.mode', 'string', MaintenanceManager::MODE_THEME],
                ['maintenance_page_id', 'maintenance.page_id', 'integer', 0],
                ['maintenance_title', 'maintenance.title', 'string', MaintenanceManager::DEFAULT_TITLE],
                ['maintenance_message', 'maintenance.message', 'text', MaintenanceManager::DEFAULT_MESSAGE],
                ['maintenance_status_code', 'maintenance.status_code', 'integer', MaintenanceManager::DEFAULT_STATUS],
                ['maintenance_retry_after', 'maintenance.retry_after_minutes', 'integer', MaintenanceManager::DEFAULT_RETRY_AFTER],
                ['maintenance_allow_admin_bypass', 'maintenance.allow_admin_bypass', 'boolean', true],
                ['maintenance_allow_logged_in_bypass', 'maintenance.allow_logged_in_bypass', 'boolean', false],
                ['maintenance_allowed_ips', 'maintenance.allowed_ips', 'array', []],
                ['maintenance_exclude_paths', 'maintenance.exclude_paths', 'array', MaintenanceManager::DEFAULT_EXCLUDE_PATHS],
            );
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function timezoneOptions(): array
    {
        $zones = \DateTimeZone::listIdentifiers();

        return array_combine($zones, $zones) ?: ['UTC' => 'UTC'];
    }

    /**
     * Published + draft pages keyed by id, for the homepage/posts-page selects.
     *
     * @return array<int, string>
     */
    private function pageOptions(): array
    {
        if ($this->pageOptionsCache !== null) {
            return $this->pageOptionsCache;
        }

        // Page titles shown in the dropdown follow the content editing locale
        // so they read in the language the admin is working in (beta.7.1.10.2).
        $locale = editing_locale();

        return $this->pageOptionsCache = Content::query()
            ->where('type', 'page')
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderByDesc('updated_at')
            ->get()
            ->mapWithKeys(static fn (Content $page): array => [
                $page->id => $page->translatedTitle($locale),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        return PostResource::categoryOptions(editing_locale());
    }

    /**
     * The raw value stored for a localizable key in $locale (empty string when
     * the locale has no translation yet). Deliberately NOT the fallback value —
     * the editor must show exactly what is saved for the locale, so saving never
     * copies another locale's value into this one.
     */
    private function localizedFieldValue(SettingsManager $settings, string $key, string $locale): mixed
    {
        return $settings->rawLocalizedValue($key, $locale) ?? '';
    }

    /**
     * Re-fill the translatable fields with the newly selected locale's values
     * when the language switcher changes. Global fields are left untouched.
     */
    private function reloadLocalizedFields(Set $set, ?string $locale): void
    {
        $settings = $this->settings();
        $locale = $this->normalizeEditLocale($locale);

        foreach ($this->editable() as [$flat, $key]) {
            if ($settings->isLocalized($key)) {
                $set($flat, $this->localizedFieldValue($settings, $key, $locale));
            }
        }
    }

    /**
     * Validate the editing locale against active languages, falling back to the
     * default language code.
     */
    private function normalizeEditLocale(?string $locale): string
    {
        $language = app('cms.language');

        if (is_string($locale) && $locale !== '' && $language->isActive($locale)) {
            return $language->normalizeCode($locale);
        }

        return $language->defaultCode();
    }

    private function settings(): SettingsManager
    {
        /** @var SettingsManager $manager */
        $manager = app('cms.settings');

        return $manager;
    }
}
