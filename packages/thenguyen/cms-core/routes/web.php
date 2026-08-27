<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Http\Controllers\LocaleSwitchController;
use TheNguyen\CMS\Http\Controllers\SeoController;
use TheNguyen\CMS\Support\CmsInfo;

// SEO infrastructure endpoints. Registered before the frontend catch-all
// (web.php loads before frontend.php) so they are not swallowed by /{slug}.
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('cms.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('cms.sitemap');

// CORE-L10N A2 — the ONE canonical public locale-switch endpoint. The active
// SessionLocalizationStrategy already targets this route name. "web" gives it
// session + CSRF; the runtime persists the preference and safe-redirects back.
Route::middleware('web')
    ->post('/locale/switch', LocaleSwitchController::class)
    ->name('cms.locale.switch');

Route::get('/cms-health', static function (): JsonResponse {
    $settingsLoaded = false;
    $siteName = null;
    $adminBrandName = null;

    try {
        if (Schema::hasTable('cms_settings')) {
            $manager = app('cms.settings');
            $siteName = $manager->get('general.site_name');
            $adminBrandName = $manager->get('admin.brand_name');
            $settingsLoaded = true;
        }
    } catch (\Throwable) {
        $settingsLoaded = false;
    }

    $contentTablesReady = false;
    $pagesCount = null;
    $postsCount = null;
    $taxonomiesCount = null;
    $termsCount = null;

    try {
        $required = [
            'cms_contents',
            'cms_content_translations',
            'cms_taxonomies',
            'cms_terms',
            'cms_term_translations',
            'cms_content_terms',
            'cms_slugs',
        ];

        $allReady = true;
        foreach ($required as $table) {
            if (! Schema::hasTable($table)) {
                $allReady = false;
                break;
            }
        }

        $contentTablesReady = $allReady;

        if ($contentTablesReady) {
            $pagesCount = \TheNguyen\CMS\Models\Content::query()->where('type', 'page')->count();
            $postsCount = \TheNguyen\CMS\Models\Content::query()->where('type', 'post')->count();
            $taxonomiesCount = \TheNguyen\CMS\Models\Taxonomy::query()->count();
            $termsCount = \TheNguyen\CMS\Models\Term::query()->count();
        }
    } catch (\Throwable) {
        $contentTablesReady = false;
    }

    $mediaCount = null;
    $imageCount = null;
    $mediaMetadataReady = false;

    try {
        if (Schema::hasTable('cms_media')) {
            $mediaCount = \TheNguyen\CMS\Models\Media::query()->count();
            $imageCount = \TheNguyen\CMS\Models\Media::query()
                ->where('mime_type', 'like', 'image/%')
                ->count();
            // Media Metadata Manager (v0.9.3): description column present.
            $mediaMetadataReady = Schema::hasColumn('cms_media', 'description');
        }
    } catch (\Throwable) {
        $mediaCount = null;
        $imageCount = null;
        $mediaMetadataReady = false;
    }

    $menusCount = null;
    $menuItemsCount = null;

    try {
        if (Schema::hasTable('cms_menus')) {
            $menusCount = \TheNguyen\CMS\Models\Menu::query()->count();
        }

        if (Schema::hasTable('cms_menu_items')) {
            $menuItemsCount = \TheNguyen\CMS\Models\MenuItem::query()->count();
        }
    } catch (\Throwable) {
        $menusCount = null;
        $menuItemsCount = null;
    }

    $activeTheme = CmsInfo::activeTheme();
    $themesCount = null;
    $activeThemeEffective = null;
    $themeSystemReady = false;
    $invalidThemesCount = null;

    try {
        $manager = app('cms.theme');
        $themes = $manager->all();
        $themesCount = count($themes);
        // The raw stored selection (may be null if "deactivated").
        $activeTheme = $manager->activeSlug();
        // The effective theme that actually renders the frontend (with fallback).
        $activeThemeEffective = $manager->active()?->slug;
        $themeSystemReady = $manager->themeSystemReady();
        $invalidThemesCount = count($manager->invalidThemes());
    } catch (\Throwable) {
        $themesCount = null;
        $activeThemeEffective = null;
        $themeSystemReady = false;
        $invalidThemesCount = null;
    }

    // TN CMS always requires one active (effective) theme for the frontend.
    $activeThemeRequired = true;

    // Extension Framework Core (v0.9.8): plugin discovery + active registry.
    $extensionFrameworkReady = false;
    $pluginsCount = null;
    $activePluginsCount = null;
    $activePluginSlugs = [];
    $invalidPluginsCount = null;

    try {
        $extension = app('cms.extension');
        $pluginsCount = count($extension->plugins());
        $activePlugins = $extension->activePlugins();
        $activePluginsCount = count($activePlugins);
        $activePluginSlugs = array_map(static fn ($p): string => $p->slug, $activePlugins);
        $invalidPluginsCount = count($extension->invalidPlugins());
        $extensionFrameworkReady = $extension->extensionFrameworkReady();
    } catch (\Throwable) {
        $extensionFrameworkReady = false;
        $pluginsCount = null;
        $activePluginsCount = null;
        $activePluginSlugs = [];
        $invalidPluginsCount = null;
    }

    // Plugin Manager UI (v1.0.0-beta.1): the Installed Plugins admin page over
    // the (ready) Extension Framework.
    $pluginManagerUiReady = false;

    try {
        $pluginManagerUiReady = $extensionFrameworkReady
            && class_exists(\App\Filament\Admin\Pages\InstalledPluginsPage::class);
    } catch (\Throwable) {
        $pluginManagerUiReady = false;
    }

    // Theme/Plugin ZIP Installer (v1.0.0-beta.2): the ExtensionInstaller service
    // (requires the PHP zip extension) + the two install admin pages.
    $extensionInstallerReady = false;
    $pluginInstallerReady = false;
    $themeInstallerReady = false;

    try {
        $extensionInstallerReady = app()->bound('cms.extension_installer')
            && app('cms.extension_installer')->isReady();
        $pluginInstallerReady = $extensionInstallerReady
            && class_exists(\App\Filament\Admin\Pages\InstallPluginPage::class);
        $themeInstallerReady = $extensionInstallerReady
            && class_exists(\App\Filament\Admin\Pages\InstallThemePage::class);
    } catch (\Throwable) {
        $extensionInstallerReady = false;
        $pluginInstallerReady = false;
        $themeInstallerReady = false;
    }

    // Theme Options Framework (v0.9.9): schema from the active theme + the
    // ThemeOptionManager storage layer.
    $themeOptionsReady = false;
    $activeThemeHasOptions = false;
    $themeOptionsCount = null;

    try {
        $themeOptionsReady = app()->bound('cms.theme_option')
            && class_exists(\TheNguyen\CMS\Services\ThemeOptionManager::class)
            && class_exists(\App\Filament\Admin\Pages\ThemeOptionsPage::class);

        $options = app('cms.theme_option');
        $schema = $options->schema();
        $activeThemeHasOptions = $options->hasOptions();

        $count = 0;
        foreach ($schema['sections'] as $section) {
            $count += count($section['fields']);
        }
        $themeOptionsCount = $count;
    } catch (\Throwable) {
        $themeOptionsReady = false;
        $activeThemeHasOptions = false;
        $themeOptionsCount = null;
    }

    $activeThemeViewsReady = false;
    $frontendReady = false;

    try {
        $activeThemeViewsReady = \Illuminate\Support\Facades\View::exists('theme::layouts.master');
        $frontendReady = $activeThemeViewsReady
            && \Illuminate\Support\Facades\View::exists('theme::pages.page')
            && \Illuminate\Support\Facades\View::exists('theme::posts.post')
            && \Illuminate\Support\Facades\View::exists('theme::archives.index');
    } catch (\Throwable) {
        $activeThemeViewsReady = false;
        $frontendReady = false;
    }

    $seoReady = false;
    $sitemapReady = false;
    $robotsReady = false;

    try {
        $seoReady = app()->bound('cms.seo')
            && \Illuminate\Support\Facades\View::exists('theme::partials.seo');
        $robotsReady = Route::has('cms.robots');
        $sitemapReady = Route::has('cms.sitemap');
    } catch (\Throwable) {
        $seoReady = false;
        $sitemapReady = false;
        $robotsReady = false;
    }

    $htmlSanitizerReady = false;
    $richEditorReady = false;
    $mediaModalReady = false;

    try {
        $htmlSanitizerReady = app()->bound('cms.html')
            && class_exists(\TheNguyen\CMS\Services\HtmlSanitizer::class);
        $richEditorReady = class_exists(\App\Filament\Admin\Components\RichEditor::class)
            && \Illuminate\Support\Facades\View::exists('filament.admin.components.rich-editor')
            && is_file(public_path('vendor/tinymce/tinymce.min.js'));
        $mediaModalReady = $richEditorReady && Schema::hasTable('cms_media');
    } catch (\Throwable) {
        $htmlSanitizerReady = false;
        $richEditorReady = false;
        $mediaModalReady = false;
    }

    // Editor Polish (v0.8.2): the rich editor + sanitizer pipeline is ready.
    $editorPolishReady = $richEditorReady && $htmlSanitizerReady;

    // Featured Image Media Modal (v0.9.4): metadata-ready media + the picker
    // component (which provides the modal library + upload tabs).
    $featuredImageModalReady = false;

    try {
        $featuredImageModalReady = $mediaMetadataReady
            && class_exists(\App\Filament\Admin\Components\MediaPicker::class)
            && class_exists(\App\Filament\Admin\Components\MediaLibrarySelect::class);
    } catch (\Throwable) {
        $featuredImageModalReady = false;
    }

    // Media UX Polish (v0.9.5): metadata-ready media + the featured-image modal
    // + the shared MediaItems support helper all in place.
    $mediaUxReady = false;

    try {
        $mediaUxReady = $mediaMetadataReady
            && $featuredImageModalReady
            && class_exists(\App\Filament\Admin\Support\MediaItems::class);
    } catch (\Throwable) {
        $mediaUxReady = false;
    }

    // Settings Polish (v0.9.6): the settings sections + permalink service.
    $settingsPolishReady = false;
    $settingsSections = [
        'general' => false,
        'reading' => false,
        'writing' => false,
        'media' => false,
        'seo' => false,
        'permalinks' => false,
    ];

    try {
        if (Schema::hasTable('cms_settings')) {
            $manager = app('cms.settings');
            $settingsSections = [
                'general' => $manager->has('general.site_name'),
                'reading' => $manager->has('reading.posts_per_page'),
                'writing' => $manager->has('writing.default_post_status'),
                'media' => $manager->has('media.organize_uploads_by_date'),
                'seo' => $manager->has('seo.robots_default'),
                'permalinks' => $manager->has('permalink.post_base'),
            ];
        }

        $settingsPolishReady = app()->bound('cms.permalink')
            && class_exists(\TheNguyen\CMS\Services\PermalinkManager::class)
            && class_exists(\App\Filament\Admin\Pages\SettingsPage::class);
    } catch (\Throwable) {
        $settingsPolishReady = false;
    }

    // Language Manager (v0.9.0).
    $languagesReady = false;
    $languagesCount = null;
    $activeLanguagesCount = null;
    $defaultLanguage = null;
    $currentLanguage = null;

    try {
        if (Schema::hasTable('cms_languages')) {
            $languagesCount = \TheNguyen\CMS\Models\Language::query()->count();
            $activeLanguagesCount = \TheNguyen\CMS\Models\Language::query()->where('is_active', true)->count();
            $defaultLanguage = app('cms.language')->defaultCode();
            $currentLanguage = app('cms.language')->currentCode();
            $languagesReady = app()->bound('cms.language') && $languagesCount > 0;
        }
    } catch (\Throwable) {
        $languagesReady = false;
        $languagesCount = null;
        $activeLanguagesCount = null;
        $defaultLanguage = null;
        $currentLanguage = null;
    }

    // Users / Roles / Permissions Core (v1.0.0-beta.3).
    $rolesPermissionsReady = false;
    $rolesCount = null;
    $permissionsCount = null;
    $superAdminsCount = null;

    try {
        if (Schema::hasTable('cms_roles') && Schema::hasTable('cms_permissions')) {
            $rolesCount = \TheNguyen\CMS\Models\Role::query()->count();
            $permissionsCount = \TheNguyen\CMS\Models\Permission::query()->count();
            $superAdminsCount = app('cms.permission')->superAdminUserCount();
            $rolesPermissionsReady = app()->bound('cms.permission')
                && $rolesCount > 0
                && $permissionsCount > 0;
        }
    } catch (\Throwable) {
        $rolesPermissionsReady = false;
        $rolesCount = null;
        $permissionsCount = null;
        $superAdminsCount = null;
    }

    // Maintenance Mode Core (v1.0.0-beta.4). Never exposes allowed IPs.
    $maintenanceReady = false;
    $maintenanceEnabled = false;
    $maintenanceMode = null;

    try {
        $maintenanceReady = app()->bound('cms.maintenance')
            && class_exists(\TheNguyen\CMS\Services\MaintenanceManager::class)
            && class_exists(\TheNguyen\CMS\Http\Middleware\CheckMaintenanceMode::class);

        $manager = app('cms.maintenance');
        $maintenanceEnabled = $manager->isEnabled();
        $maintenanceMode = $manager->mode();
    } catch (\Throwable) {
        $maintenanceReady = false;
        $maintenanceEnabled = false;
        $maintenanceMode = null;
    }

    // Web Installer Core (v1.0.0-beta.6). Reports readiness + lock state only —
    // never installer paths or any collected credentials.
    $webInstallerReady = false;
    $cmsInstalled = false;
    $installerLocked = false;

    try {
        $webInstallerReady = app()->bound('cms.installer')
            && class_exists(\TheNguyen\CMS\Services\InstallerManager::class)
            && class_exists(\TheNguyen\CMS\Http\Controllers\InstallController::class)
            && Route::has('cms.install.welcome');

        $installerLocked = app('cms.installer')->isInstalled();
        $cmsInstalled = $installerLocked;
    } catch (\Throwable) {
        $webInstallerReady = false;
        $cmsInstalled = false;
        $installerLocked = false;
    }

    // Extension Translation Framework (v1.0.0-beta.5). Never exposes absolute
    // paths — only file counts and a readiness flag.
    $extensionTranslationReady = false;
    $coreTranslationFilesCount = null;
    $themeTranslationFilesCount = null;
    $pluginTranslationFilesCount = null;

    try {
        $extensionTranslationReady = app()->bound('cms.extension_translation')
            && class_exists(\TheNguyen\CMS\Services\ExtensionTranslationManager::class);

        $counts = app('cms.extension_translation')->fileCounts();
        $coreTranslationFilesCount = $counts['core'];
        $themeTranslationFilesCount = $counts['theme'];
        $pluginTranslationFilesCount = $counts['plugin'];
    } catch (\Throwable) {
        $extensionTranslationReady = false;
        $coreTranslationFilesCount = null;
        $themeTranslationFilesCount = null;
        $pluginTranslationFilesCount = null;
    }

    // Admin Translation Completion (v1.0.0-beta.5.1). admin_translation_ready
    // reflects that the core dictionary is populated; filament_translation_ready
    // reflects that Filament ships (or does not need) translations for the current
    // framework locale. The core_translation_* counts come from
    // coreTranslationStats(): keys_count is the size of the default core
    // dictionary, missing_/untranslated_keys_count summarise non-default active
    // locale coverage. core_translation_keys is retained for backward
    // compatibility and mirrors core_translation_keys_count. All guarded and
    // path-free.
    $adminTranslationReady = false;
    $filamentTranslationReady = false;
    $coreTranslationKeysCount = 0;
    $coreTranslationMissingKeysCount = 0;
    $coreTranslationUntranslatedKeysCount = 0;

    try {
        $manager = app('cms.extension_translation');
        $adminTranslationReady = $manager->adminTranslationReady();

        $coreTranslationStats = $manager->coreTranslationStats();
        $coreTranslationKeysCount = $coreTranslationStats['keys_count'];
        $coreTranslationMissingKeysCount = $coreTranslationStats['missing_keys_count'];
        $coreTranslationUntranslatedKeysCount = $coreTranslationStats['untranslated_keys_count'];

        $locale = app()->getLocale();
        // English needs no shipped Filament translations; other locales are
        // ready when Filament publishes a lang directory for them.
        $filamentTranslationReady = $locale === 'en'
            || (glob(base_path('vendor/filament/*/resources/lang/'.preg_replace('/[^a-zA-Z0-9_-]/', '', $locale)), GLOB_ONLYDIR) ?: []) !== [];
    } catch (\Throwable) {
        $adminTranslationReady = false;
        $filamentTranslationReady = false;
        $coreTranslationKeysCount = 0;
        $coreTranslationMissingKeysCount = 0;
        $coreTranslationUntranslatedKeysCount = 0;
    }

    // Localized Settings Framework (v1.0.0-beta.6.2). Count-only health: whether
    // the translation table exists and how many distinct localized keys/locales
    // are stored. Never exposes keys, locales, values, or paths. Never throws.
    $localizedSettings = ['ready' => false, 'keys_count' => 0, 'locales_count' => 0];

    try {
        $localizedSettings = app('cms.settings')->localizedHealth();
    } catch (\Throwable $e) {
        report($e);
        $localizedSettings = ['ready' => false, 'keys_count' => 0, 'locales_count' => 0];
    }

    // Public Content Resolution Cache (v1.0.0-beta.6.3). Count-free, path-free
    // health: whether the cache layer is wired, whether it is enabled (TTL > 0),
    // its TTL, and the current invalidation version. Never throws.
    $publicCache = ['ready' => false, 'enabled' => false, 'ttl' => 0, 'version' => 0];
    $settingsHotPathOptimized = false;

    try {
        $publicCache = app('cms.public_cache')->health();
        $settingsHotPathOptimized = app('cms.settings')->hotPathOptimized();
    } catch (\Throwable $e) {
        report($e);
        $publicCache = ['ready' => false, 'enabled' => false, 'ttl' => 0, 'version' => 0];
        $settingsHotPathOptimized = false;
    }

    // Frontend runtime optimization (CORE-OPTIMIZE-3). Path-, credential-, token-
    // and driver-free public subset (booleans, a bounded TTL, status strings).
    $runtimeOptimization = [
        'response_optimization' => false,
        'response_public_html_ttl' => 0,
        'response_status' => 'disabled',
        'static_assets_fingerprinted' => false,
        'static_assets_status' => 'unavailable',
    ];

    try {
        $runtimeOptimization = app('cms.runtime_diagnostics')->publicSnapshot();
    } catch (\Throwable $e) {
        report($e);
    }

    // Health Output Cleanup (v1.0.0-beta.5.2). Absolute filesystem paths are
    // NEVER exposed by default — not even when APP_DEBUG is on. An operator must
    // explicitly opt in with CMS_HEALTH_SHOW_PATHS=true (config cms.health.show_paths)
    // for local debugging only, so the public health endpoint leaks no server paths.
    $debugPathsEnabled = config('cms.health.show_paths') === true;
    $debugPaths = $debugPathsEnabled
        ? ['base_path' => base_path(), 'public_path' => public_path()]
        : [];

    // Summary flag: the canonical metric set is deduplicated structurally, no
    // absolute filesystem paths are exposed (debug paths are opt-in and flip this
    // to false), and the translation health metrics resolved to valid integers.
    // Never throws.
    $healthOutputClean = ! $debugPathsEnabled
        && $extensionTranslationReady
        && $adminTranslationReady
        && is_int($coreTranslationKeysCount)
        && $coreTranslationMissingKeysCount >= 0
        && $coreTranslationUntranslatedKeysCount >= 0;

    // Security Posture (v1.0.0-beta.6.4). BOOLEANS ONLY. This block deliberately
    // never exposes filesystem paths, allowed/denied extension lists, MIME lists,
    // disk names, or any other server internal — only whether each control is in
    // force. Never throws.
    $mediaPolicy = (array) config('cms.media', []);
    $mediaUploadSecure = is_array($mediaPolicy['allowed_extensions'] ?? null)
        && ($mediaPolicy['allowed_extensions'] ?? []) !== []
        && is_array($mediaPolicy['denied_extensions'] ?? null)
        && ($mediaPolicy['denied_extensions'] ?? []) !== []
        && (int) ($mediaPolicy['max_upload_size'] ?? 0) > 0;

    // Script-execution / dotfile guards present in the public upload tree.
    $publicFileSecure = is_file(public_path('uploads/.htaccess'))
        && is_file(public_path('uploads/web.config'));

    // Uploads are confined to the web root behind the guards above, and the
    // default filesystem disk is private (not 'public').
    $storageSecure = $publicFileSecure
        && config('filesystems.default') !== 'public';

    // The installer lock is wired: the detection service is bound AND the guard
    // middleware that blocks the wizard once installed is present. When the site
    // is installed, that state is also reflected as locked.
    $installerSecure = app()->bound('cms.installer')
        && class_exists(\TheNguyen\CMS\Http\Middleware\RedirectIfInstalled::class)
        && (! $cmsInstalled || $installerLocked);

    // The HTML sanitizer is wired and available to neutralize stored markup.
    $sanitizerSecure = $htmlSanitizerReady === true;

    // The public content cache layer is wired (its keys are namespaced by
    // version + locale + path and it excludes authenticated/draft responses).
    $cacheSecure = ($publicCache['ready'] ?? false) === true;

    // Performance posture (v1.0.0-beta.6.4.2). BOOLEANS ONLY — wiring health, not
    // values. Never throws.
    $bootSafe = method_exists(\TheNguyen\CMS\Services\InstallerManager::class, 'isInstalledQuick');
    $settingsHotPath = property_exists(\TheNguyen\CMS\Services\SettingsManager::class, 'translationsTableExists')
        && property_exists(\TheNguyen\CMS\Services\SettingsManager::class, 'settingsTableExists');
    $languageHotPath = property_exists(\TheNguyen\CMS\Services\LanguageManager::class, 'languageTableExists');
    $extensionRegistryCache = method_exists(\TheNguyen\CMS\Services\ThemeManager::class, 'flushRegistry')
        && method_exists(\TheNguyen\CMS\Services\ExtensionManager::class, 'flushRegistry');
    $queryBudgetReady = class_exists(\TheNguyen\CMS\Http\Middleware\QueryInsights::class)
        && (bool) config('cms.performance.query_budget.enabled', false)
        && (array) config('cms.performance.query_budget.budgets', []) !== [];
    $queryProfilerReady = class_exists(\TheNguyen\CMS\Http\Middleware\QueryInsights::class);

    // Widget Foundation (v1.0.0-beta.7). Count-only health: whether the widget
    // layer is wired and how many areas / widget instances exist. No settings,
    // no paths, no rendered HTML. Never throws.
    $widgetsReady = false;
    $widgetCount = 0;
    $widgetAreaCount = 0;
    $activeWidgetCount = 0;

    try {
        $widgetsReady = app()->bound('cms.widget')
            && class_exists(\TheNguyen\CMS\Services\WidgetManager::class);

        if (Schema::hasTable('cms_widgets')) {
            $widgetCount = \TheNguyen\CMS\Models\Widget::query()->count();
            $activeWidgetCount = \TheNguyen\CMS\Models\Widget::query()->where('is_active', true)->count();
        }

        if (Schema::hasTable('cms_widget_areas')) {
            $widgetAreaCount = \TheNguyen\CMS\Models\WidgetArea::query()->count();
        }
    } catch (\Throwable) {
        $widgetsReady = false;
        $widgetCount = 0;
        $widgetAreaCount = 0;
        $activeWidgetCount = 0;
    }

    // Hooks & Shortcodes Foundation (v1.0.0-beta.7.1.11) — readiness + counts
    // only. Never exposes hook/shortcode callback class names. Never throws.
    $hooksReady = false;
    $shortcodesReady = false;
    $registeredShortcodeCount = 0;

    // Hook Context & Extensibility API (v1.0.0-beta.7.1.11.1) — definition and
    // callback COUNTS only. Never exposes hook/callback class names.
    $hookDefinitionsCount = 0;
    $definedActionCount = 0;
    $definedFilterCount = 0;
    $hookCallbackCount = 0;

    try {
        $hooksReady = app()->bound('cms.hooks')
            && class_exists(\TheNguyen\CMS\Services\HookManager::class);
        $shortcodesReady = app()->bound('cms.shortcodes')
            && class_exists(\TheNguyen\CMS\Services\ShortcodeManager::class);

        if ($shortcodesReady) {
            $registeredShortcodeCount = count(app('cms.shortcodes')->all());
        }

        if ($hooksReady) {
            $hooks = app('cms.hooks');
            $hookDefinitionsCount = $hooks->definitionCount();
            $definedActionCount = count($hooks->definedActions());
            $definedFilterCount = count($hooks->definedFilters());
            $hookCallbackCount = $hooks->callbackCount();
        }
    } catch (\Throwable) {
        $hooksReady = false;
        $shortcodesReady = false;
        $registeredShortcodeCount = 0;
        $hookDefinitionsCount = 0;
        $definedActionCount = 0;
        $definedFilterCount = 0;
        $hookCallbackCount = 0;
    }

    // Preview API (v1.0.0-beta.7.1.12.1) — readiness + COUNTS only. Never
    // exposes preview URLs, keys, or record data. Never throws.
    $previewReady = false;
    $previewTypes = 0;
    $previewHooks = 0;
    $previewContextReady = false;

    try {
        $previewReady = app()->bound('cms.preview')
            && class_exists(\TheNguyen\CMS\Services\PreviewManager::class)
            && app('cms.preview')->enabled();

        if (app()->bound('cms.preview')) {
            $previewTypes = app('cms.preview')->registeredCount();
        }

        $previewContextReady = class_exists(\TheNguyen\CMS\Support\Preview\PreviewContext::class);

        if (app()->bound('cms.hooks')) {
            foreach (array_keys(app('cms.hooks')->definitions()) as $hookName) {
                if (str_starts_with((string) $hookName, 'cms.preview.')) {
                    $previewHooks++;
                }
            }
        }
    } catch (\Throwable) {
        $previewReady = false;
        $previewTypes = 0;
        $previewHooks = 0;
        $previewContextReady = false;
    }

    // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2) — readiness + COUNTS only.
    // Never exposes callbacks. Never throws.
    $formHooksReady = false;
    $formHookPoints = 0;
    $adminHookPoints = 0;

    try {
        $formHooksReady = app()->bound('cms.form_hooks')
            && class_exists(\TheNguyen\CMS\Services\FormHookBridge::class);

        if (app()->bound('cms.hooks')) {
            foreach (array_keys(app('cms.hooks')->definitions()) as $hookName) {
                if (str_starts_with((string) $hookName, 'cms.form.')) {
                    $formHookPoints++;
                } elseif (str_starts_with((string) $hookName, 'cms.admin.')) {
                    $adminHookPoints++;
                }
            }
        }
    } catch (\Throwable) {
        $formHooksReady = false;
        $formHookPoints = 0;
        $adminHookPoints = 0;
    }

    // Global Script Manager (v1.0.0-beta.7.1.13) — readiness + COUNTS only.
    // Never exposes script contents, URLs, keys, or values. Never throws.
    $scriptManagerHealth = [
        'script_manager_ready' => false,
        'registered_head_assets' => 0,
        'registered_footer_assets' => 0,
        'registered_meta' => 0,
        'registered_json_ld' => 0,
        'registered_verifications' => 0,
        'registered_embeds' => 0,
        // Source/warning observability (v1.0.0-beta.7.1.13.3) — COUNTS only.
        'script_source_count' => 0,
        'script_warning_count' => 0,
        'plugin_script_sources' => 0,
        'theme_script_sources' => 0,
    ];

    try {
        if (app()->bound('cms.scripts')
            && class_exists(\TheNguyen\CMS\Services\ScriptManager::class)) {
            $scriptManagerHealth = app('cms.scripts')->healthSnapshot();
        }
    } catch (\Throwable) {
        $scriptManagerHealth['script_manager_ready'] = false;
    }

    // Global Script Settings UI (v1.0.0-beta.7.1.13.2) — readiness + COUNTS only.
    // Counts configured/enabled settings-managed entries; never exposes any
    // script value, URL, or key. Never throws.
    $scriptSettingsHealth = [
        'script_settings_ready' => false,
        'script_settings_enabled' => false,
        'script_settings_verification_count' => 0,
        'script_settings_json_ld_count' => 0,
        'script_settings_inline_count' => 0,
        'script_settings_external_count' => 0,
        'script_settings_embed_count' => 0,
    ];

    try {
        if (app()->bound('cms.script_settings')
            && class_exists(\TheNguyen\CMS\Services\ScriptSettingsRegistrar::class)) {
            $scriptSettingsHealth = app('cms.script_settings')->healthSnapshot();
        }
    } catch (\Throwable) {
        $scriptSettingsHealth['script_settings_ready'] = false;
    }

    // Asset Registry (v1.0.0-beta.7.1.13.1) — readiness + COUNTS only.
    // Never exposes handles, URLs, or inline code. Never throws.
    $assetRegistryHealth = [
        'asset_registry_ready' => false,
        'registered_asset_count' => 0,
        'enqueued_frontend_asset_count' => 0,
        'enqueued_admin_asset_count' => 0,
        // Source/warning observability (v1.0.0-beta.7.1.13.3) — COUNTS only.
        'asset_source_count' => 0,
        'asset_warning_count' => 0,
    ];

    try {
        if (app()->bound('cms.assets')
            && class_exists(\TheNguyen\CMS\Services\AssetRegistry::class)) {
            $assetRegistryHealth = app('cms.assets')->healthSnapshot();
        }
    } catch (\Throwable) {
        $assetRegistryHealth['asset_registry_ready'] = false;
    }

    // Theme Custom CSS (v1.0.0-beta.7.1.13.4) — enabled flag + byte SIZES only.
    // Never exposes the CSS contents. Never throws.
    $themeCustomCssHealth = [
        'theme_custom_css_enabled' => false,
        'theme_custom_css_frontend_size' => 0,
        'theme_custom_css_admin_size' => 0,
    ];

    try {
        if (app()->bound('cms.theme_custom_css')
            && class_exists(\TheNguyen\CMS\Services\ThemeCustomCssManager::class)) {
            $themeCustomCssHealth = app('cms.theme_custom_css')->healthSnapshot();
        }
    } catch (\Throwable) {
        $themeCustomCssHealth['theme_custom_css_enabled'] = false;
    }

    // Frontend Authentication (v1.0.0-beta.7.1.14) — readiness/policy flags only.
    // No emails, session ids, or tokens.
    $frontendAuthHealth = [
        'frontend_auth_ready' => false,
        'frontend_registration_enabled' => false,
        'frontend_default_role_configured' => false,
        'frontend_session_policy_ready' => false,
        'frontend_email_verification_available' => false,
    ];

    try {
        if (app()->bound('cms.frontend_auth')
            && class_exists(\TheNguyen\CMS\Services\FrontendAuthManager::class)) {
            $frontendAuthHealth = app('cms.frontend_auth')->healthSnapshot();
        }
    } catch (\Throwable) {
        $frontendAuthHealth['frontend_auth_ready'] = false;
    }

    // Account Foundation (v1.0.0-beta.7.1.15) — readiness/policy flags only.
    // No user data. Reports whether the shell, routes, hooks, and profile
    // fields are wired for the logged-in frontend account area.
    $accountHealth = [
        'account_foundation_ready' => false,
        'account_routes_ready' => false,
        'account_hooks_ready' => false,
        'account_profile_fields_ready' => false,
    ];

    try {
        if (app()->bound('cms.account')
            && class_exists(\TheNguyen\CMS\Services\AccountManager::class)) {
            $accountHealth = app('cms.account')->healthSnapshot();
        }
    } catch (\Throwable) {
        $accountHealth['account_foundation_ready'] = false;
    }

    return response()->json([
        'status' => 'ok',
        'health_output_clean' => $healthOutputClean,
        'health_output_debug_paths_enabled' => $debugPathsEnabled,
        'app' => CmsInfo::name(),
        'cms_version' => CmsInfo::version(),
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
        ...$debugPaths,
        'active_theme' => $activeTheme,
        'active_theme_effective' => $activeThemeEffective,
        'active_theme_required' => $activeThemeRequired,
        'theme_system_ready' => $themeSystemReady,
        'theme_count' => $themesCount,
        'invalid_theme_count' => $invalidThemesCount,
        'extension_framework_ready' => $extensionFrameworkReady,
        'plugin_count' => $pluginsCount,
        'active_plugin_count' => $activePluginsCount,
        'active_plugins' => $activePluginSlugs,
        'invalid_plugin_count' => $invalidPluginsCount,
        'plugin_manager_ui_ready' => $pluginManagerUiReady,
        'installer_ready' => $extensionInstallerReady,
        'extension_installer_ready' => $extensionInstallerReady,
        'plugin_installer_ready' => $pluginInstallerReady,
        'theme_installer_ready' => $themeInstallerReady,
        'theme_options_ready' => $themeOptionsReady,
        'active_theme_has_options' => $activeThemeHasOptions,
        'theme_options_count' => $themeOptionsCount,
        'active_theme_views_ready' => $activeThemeViewsReady,
        'frontend_ready' => $frontendReady,
        'seo_ready' => $seoReady,
        'sitemap_ready' => $sitemapReady,
        'robots_ready' => $robotsReady,
        'rich_editor_ready' => $richEditorReady,
        'html_sanitizer_ready' => $htmlSanitizerReady,
        'media_modal_ready' => $mediaModalReady,
        'editor_polish_ready' => $editorPolishReady,
        'languages_ready' => $languagesReady,
        'language_count' => $languagesCount,
        'active_language_count' => $activeLanguagesCount,
        'default_language' => $defaultLanguage,
        'current_language' => $currentLanguage,
        'deployment_mode' => CmsInfo::deploymentMode(),
        'settings_cache_key' => 'cms.settings.autoload',
        'settings_cached' => Cache::has('cms.settings.autoload'),
        'settings_loaded' => $settingsLoaded,
        'site_name' => $siteName,
        'admin_brand_name' => $adminBrandName,
        'content_tables_ready' => $contentTablesReady,
        'pages_count' => $pagesCount,
        'posts_count' => $postsCount,
        'taxonomies_count' => $taxonomiesCount,
        'terms_count' => $termsCount,
        'media_count' => $mediaCount,
        'image_count' => $imageCount,
        'media_metadata_ready' => $mediaMetadataReady,
        'featured_image_modal_ready' => $featuredImageModalReady,
        'media_ux_ready' => $mediaUxReady,
        // Server-side image search behind the logo/favicon/featured-image
        // pickers, so large media libraries stay fully searchable.
        'media_picker_search_ready' => Route::has('filament.admin.cms-media-search'),
        // Term archives (category/tag) paginate via the theme pagination partial,
        // honouring reading.posts_per_page (floored at 1, capped at 100).
        'taxonomy_archive_pagination_ready' => view()->exists('theme::partials.pagination'),
        'settings_polish_ready' => $settingsPolishReady,
        'settings_sections' => $settingsSections,
        'menus_count' => $menusCount,
        'menu_items_count' => $menuItemsCount,
        'roles_permissions_ready' => $rolesPermissionsReady,
        'role_count' => $rolesCount,
        'permission_count' => $permissionsCount,
        'super_admins_count' => $superAdminsCount,
        'maintenance_ready' => $maintenanceReady,
        'maintenance_enabled' => $maintenanceEnabled,
        'maintenance_mode' => $maintenanceMode,
        'web_installer_ready' => $webInstallerReady,
        'cms_installed' => $cmsInstalled,
        'installer_locked' => $installerLocked,
        'extension_translation_ready' => $extensionTranslationReady,
        'core_translation_files_count' => $coreTranslationFilesCount,
        'theme_translation_files_count' => $themeTranslationFilesCount,
        'plugin_translation_files_count' => $pluginTranslationFilesCount,
        'admin_translation_ready' => $adminTranslationReady,
        'filament_translation_ready' => $filamentTranslationReady,
        // Deprecated alias of core_translation_keys_count, retained for backward
        // compatibility; will be removed in a future release.
        'core_translation_keys' => $coreTranslationKeysCount,
        'core_translation_keys_count' => $coreTranslationKeysCount,
        'core_translation_missing_keys_count' => $coreTranslationMissingKeysCount,
        'core_translation_untranslated_keys_count' => $coreTranslationUntranslatedKeysCount,
        'localized_settings_ready' => $localizedSettings['ready'],
        'localized_settings_keys_count' => $localizedSettings['keys_count'],
        'localized_settings_locales_count' => $localizedSettings['locales_count'],
        'public_cache_ready' => $publicCache['ready'],
        'public_cache_enabled' => $publicCache['enabled'],
        'public_cache_ttl' => $publicCache['ttl'],
        'public_cache_version' => $publicCache['version'],
        'settings_hot_path_optimized' => $settingsHotPathOptimized,

        // Frontend runtime optimization (CORE-OPTIMIZE-3) — booleans / bounded
        // TTL / status only; no driver, path, credential, token or package location.
        'response_optimization' => $runtimeOptimization['response_optimization'],
        'response_public_html_ttl' => $runtimeOptimization['response_public_html_ttl'],
        'response_optimization_status' => $runtimeOptimization['response_status'],
        'static_assets_fingerprinted' => $runtimeOptimization['static_assets_fingerprinted'],
        'static_assets_status' => $runtimeOptimization['static_assets_status'],

        // Security posture (v1.0.0-beta.6.4) — booleans only.
        'storage_secure' => $storageSecure,
        'media_upload_secure' => $mediaUploadSecure,
        'installer_secure' => $installerSecure,
        'cache_secure' => $cacheSecure,
        'sanitizer_secure' => $sanitizerSecure,
        'public_file_secure' => $publicFileSecure,

        // Performance posture (v1.0.0-beta.6.4.2) — booleans only.
        'boot_safe' => $bootSafe,
        'settings_hot_path' => $settingsHotPath,
        'language_hot_path' => $languageHotPath,
        'extension_registry_cache' => $extensionRegistryCache,
        'query_budget_ready' => $queryBudgetReady,
        'query_profiler_ready' => $queryProfilerReady,

        // Widget Foundation (v1.0.0-beta.7) — counts only.
        'widgets_ready' => $widgetsReady,
        'widget_count' => $widgetCount,
        'widget_area_count' => $widgetAreaCount,
        'active_widget_count' => $activeWidgetCount,

        // Hooks & Shortcodes Foundation (v1.0.0-beta.7.1.11) — readiness + count.
        'hooks_ready' => $hooksReady,
        'shortcodes_ready' => $shortcodesReady,
        'registered_shortcode_count' => $registeredShortcodeCount,

        // Hook Context & Extensibility API (v1.0.0-beta.7.1.11.1) — counts only.
        'hook_definitions_count' => $hookDefinitionsCount,
        'defined_action_count' => $definedActionCount,
        'defined_filter_count' => $definedFilterCount,
        'hook_callback_count' => $hookCallbackCount,

        // Preview API (v1.0.0-beta.7.1.12.1) — readiness + counts only.
        'preview_ready' => $previewReady,
        'preview_types' => $previewTypes,
        'preview_hooks' => $previewHooks,
        'preview_context' => $previewContextReady,

        // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2) — readiness + counts.
        'form_hooks_ready' => $formHooksReady,
        'form_hook_points' => $formHookPoints,
        'admin_hook_points' => $adminHookPoints,

        // Global Script Manager (v1.0.0-beta.7.1.13) — readiness + counts only.
        ...$scriptManagerHealth,

        // Global Script Settings UI (v1.0.0-beta.7.1.13.2) — readiness + counts.
        ...$scriptSettingsHealth,

        // Asset Registry (v1.0.0-beta.7.1.13.1) — readiness + counts only.
        ...$assetRegistryHealth,

        // Theme Custom CSS (v1.0.0-beta.7.1.13.4) — enabled flag + sizes only.
        ...$themeCustomCssHealth,

        // Frontend Authentication (v1.0.0-beta.7.1.14) — readiness/policy only.
        ...$frontendAuthHealth,

        // Account Foundation (v1.0.0-beta.7.1.15) — readiness only.
        ...$accountHealth,
    ]);
});
