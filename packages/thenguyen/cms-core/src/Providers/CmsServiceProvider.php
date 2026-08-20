<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Console\Commands\ClearSettingsCacheCommand;
use TheNguyen\CMS\Console\Commands\DiagnoseLocalizationCommand;
use TheNguyen\CMS\Console\Commands\DemoImportCommand;
use TheNguyen\CMS\Console\Commands\DemoListCommand;
use TheNguyen\CMS\Console\Commands\DemoResetCommand;
use TheNguyen\CMS\Console\Commands\MenuLocalizedUrlRepairCommand;
use TheNguyen\CMS\Console\Commands\PluginActivateCommand;
use TheNguyen\CMS\Console\Commands\PluginDeactivateCommand;
use TheNguyen\CMS\Console\Commands\PluginListCommand;
use TheNguyen\CMS\Console\Commands\SlugRebuildCommand;
use TheNguyen\CMS\Console\Commands\ThemePublishCommand;
use TheNguyen\CMS\Http\Middleware\RedirectDefaultLocalePrefix;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;
use TheNguyen\CMS\Models\MenuItemTranslation;
use TheNguyen\CMS\Models\MenuTranslation;
use TheNguyen\CMS\Models\Setting;
use TheNguyen\CMS\Models\SettingTranslation;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;
use TheNguyen\CMS\Models\Widget as WidgetModel;
use TheNguyen\CMS\Models\WidgetArea;
use TheNguyen\CMS\Models\WidgetTranslation;
use TheNguyen\CMS\Registries\ComponentRegistry;
use TheNguyen\CMS\Registries\SectionRegistry;
use TheNguyen\CMS\Services\AccountManager;
use TheNguyen\CMS\Services\AssetRegistry;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\DemoMenuImporter;
use TheNguyen\CMS\Services\ExtensionInstaller;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Services\ExtensionTranslationManager;
use TheNguyen\CMS\Services\FormHookBridge;
use TheNguyen\CMS\Services\FrontendAuthManager;
use TheNguyen\CMS\Services\HomepageResolver;
use TheNguyen\CMS\Services\HookManager;
use TheNguyen\CMS\Services\HtmlSanitizer;
use TheNguyen\CMS\Services\InstallerManager;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\Services\LayoutEditor;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\Contracts\PublicLocaleContextContract;
use TheNguyen\CMS\Localization\CurrentLocalizationContextFactory;
use TheNguyen\CMS\Localization\CurrentResourceContext;
use TheNguyen\CMS\Localization\CurrentResourcePublisher;
use TheNguyen\CMS\Localization\LanguageConfiguration;
use TheNguyen\CMS\Localization\LocaleSwitchTargetService;
use TheNguyen\CMS\Localization\LocalizationStrategyRegistry;
use TheNguyen\CMS\Localization\LocalizedResourceResolverRegistry;
use TheNguyen\CMS\Localization\LocalizedUrlGenerator;
use TheNguyen\CMS\Localization\PublicLocaleContext;
use TheNguyen\CMS\Localization\Resolvers\CategoryResolver;
use TheNguyen\CMS\Localization\Resolvers\HomeResolver;
use TheNguyen\CMS\Localization\Resolvers\PageResolver;
use TheNguyen\CMS\Localization\Resolvers\PostResolver;
use TheNguyen\CMS\Localization\Resolvers\TagResolver;
use TheNguyen\CMS\Localization\Strategies\PrefixLocalizationStrategy;
use TheNguyen\CMS\Localization\Strategies\SessionLocalizationStrategy;
use TheNguyen\CMS\Services\LocalePreferenceManager;
use TheNguyen\CMS\Services\MaintenanceManager;
use TheNguyen\CMS\Services\MediaManager;
use TheNguyen\CMS\Services\MenuManager;
use TheNguyen\CMS\Services\MenuMegaDataProvider;
use TheNguyen\CMS\Services\PermalinkManager;
use TheNguyen\CMS\Services\PermissionManager;
use TheNguyen\CMS\Services\PluginAssetPublisher;
use TheNguyen\CMS\Services\PluginDatabaseManager;
use TheNguyen\CMS\Services\PluginInstallationGuard;
use TheNguyen\CMS\Services\PluginLifecycleManager;
use TheNguyen\CMS\Services\PresetRepository;
use TheNguyen\CMS\Services\LocalizedContentUrlService;
use TheNguyen\CMS\Services\PreviewManager;
use TheNguyen\CMS\Services\PreviewUrlService;
use TheNguyen\CMS\Services\PublicContentCacheManager;
use TheNguyen\CMS\Services\ScriptManager;
use TheNguyen\CMS\Services\ScriptSettingsRegistrar;
use TheNguyen\CMS\Services\SectionDataProvider;
use TheNguyen\CMS\Services\SectionFieldLocalizer;
use TheNguyen\CMS\Services\SectionResolver;
use TheNguyen\CMS\Services\SeoManager;
use TheNguyen\CMS\Services\SettingsManager;
use TheNguyen\CMS\Services\ShortcodeManager;
use TheNguyen\CMS\Services\SlugManager;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeAssetPublisher;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Services\ThemeCustomCssManager;
use TheNguyen\CMS\Services\ThemeOptionManager;
use TheNguyen\CMS\Services\WidgetManager;
use TheNguyen\CMS\Support\Hooks\HookContext;
use TheNguyen\CMS\Support\Hooks\HookDefinition;
use TheNguyen\CMS\Widgets\CategoriesWidget;
use TheNguyen\CMS\Widgets\HtmlWidget;
use TheNguyen\CMS\Widgets\RecentPostsWidget;
use TheNguyen\CMS\Widgets\TextWidget;

class CmsServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../config/cms.php';

    private const CONFIG_SECTIONS_PATH = __DIR__.'/../../config/cms-sections.php';

    private const CONFIG_COMPONENTS_PATH = __DIR__.'/../../config/cms-components.php';

    private const ROUTES_WEB = __DIR__.'/../../routes/web.php';

    private const ROUTES_INSTALLER = __DIR__.'/../../routes/installer.php';

    private const ROUTES_UPGRADE = __DIR__.'/../../routes/upgrade.php';

    private const ROUTES_AUTH = __DIR__.'/../../routes/auth.php';

    private const ROUTES_ACCOUNT = __DIR__.'/../../routes/account.php';

    private const ROUTES_FRONTEND = __DIR__.'/../../routes/frontend.php';

    private const ROUTES_PREVIEW = __DIR__.'/../../routes/preview.php';

    private const MIGRATIONS_PATH = __DIR__.'/../../database/migrations';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'cms');
        $this->mergeConfigFrom(self::CONFIG_SECTIONS_PATH, 'cms-sections');
        $this->mergeConfigFrom(self::CONFIG_COMPONENTS_PATH, 'cms-components');

        // Translation Engine foundation (Phase 8.0): the reusable localization
        // platform service. Additive — nothing consumes it yet.
        $this->app->register(\TheNguyen\CMS\Translation\TranslationServiceProvider::class);

        // Revision Foundation (Phase 9.0A): the reusable, locale-aware content
        // revision platform. Additive and OFF by default (revisions.enabled) —
        // nothing consumes it yet and no existing behaviour changes.
        $this->app->register(\TheNguyen\CMS\Revision\RevisionServiceProvider::class);

        $this->app->singleton('cms.settings', fn () => new SettingsManager);
        $this->app->alias('cms.settings', SettingsManager::class);

        // Core Mail Platform (CORE-MAIL-1 · ADR-CORE-MAIL-001/002/003). One resolver, one
        // settings authority (cms_settings via SettingsManager), one secret authority
        // (Laravel Crypt). Laravel Mail remains the transport; these auto-wire SettingsManager.
        $this->app->singleton(\TheNguyen\CMS\Mail\MailSettingsRepository::class);
        $this->app->singleton(\TheNguyen\CMS\Mail\MailSettingsValidator::class);
        $this->app->singleton(\TheNguyen\CMS\Mail\MailConfigurationResolver::class);
        $this->app->singleton(\TheNguyen\CMS\Mail\MailConfigurationBridge::class);
        $this->app->singleton(\TheNguyen\CMS\Mail\MailConnectionTester::class);

        $this->app->singleton('cms.slug', fn () => new SlugManager);
        $this->app->alias('cms.slug', SlugManager::class);

        // Public content resolution cache (v1.0.0-beta.6.3).
        $this->app->singleton('cms.public_cache', fn () => new PublicContentCacheManager);
        $this->app->alias('cms.public_cache', PublicContentCacheManager::class);

        // Preview infrastructure (v1.0.0-beta.7.1.16): secure temporary signed
        // previews of unpublished content for core + plugin content types.
        $this->app->singleton('cms.preview', fn () => new PreviewManager);
        $this->app->alias('cms.preview', PreviewManager::class);

        // Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2): lets plugins reshape
        // TN CMS-owned admin form schemas and append regions via filters.
        $this->app->singleton('cms.form_hooks', fn () => new FormHookBridge);
        $this->app->alias('cms.form_hooks', FormHookBridge::class);

        $this->app->singleton('cms.html', fn () => new HtmlSanitizer);
        $this->app->alias('cms.html', HtmlSanitizer::class);

        // Global Script Manager (v1.0.0-beta.7.1.13): safe frontend head/footer
        // scripts, verification meta, JSON-LD, and trusted iframe embeds.
        $this->app->singleton('cms.scripts', fn () => new ScriptManager);
        $this->app->alias('cms.scripts', ScriptManager::class);

        // Global Script Settings render bridge (v1.0.0-beta.7.1.13.2): registers
        // admin-managed scripts.* settings into the ScriptManager at render time.
        $this->app->singleton('cms.script_settings', fn ($app) => new ScriptSettingsRegistrar($app->make('cms.settings')));
        $this->app->alias('cms.script_settings', ScriptSettingsRegistrar::class);

        // Asset Registry (v1.0.0-beta.7.1.13.1): themes/plugins register &
        // enqueue frontend/admin CSS/JS by handle, with dependency resolution.
        $this->app->singleton('cms.assets', fn () => new AssetRegistry);
        $this->app->alias('cms.assets', AssetRegistry::class);

        // Frontend Authentication (v1.0.0-beta.7.1.14): registration, login,
        // logout, password reset, and the session/device invalidation policy on
        // the shared web guard. One users table; roles decide capability.
        $this->app->singleton('cms.frontend_auth', fn () => new FrontendAuthManager);
        $this->app->alias('cms.frontend_auth', FrontendAuthManager::class);

        // Account Foundation (v1.0.0-beta.7.1.15). Profile/email/password/
        // preference updates + extensible account navigation for logged-in
        // frontend users. Owns identity/security/preferences only; customer,
        // order, and membership data belong to plugins via the account hooks.
        $this->app->singleton('cms.account', fn ($app) => new AccountManager($app->make('cms.frontend_auth')));
        $this->app->alias('cms.account', AccountManager::class);

        // Hooks & Shortcodes foundation (v1.0.0-beta.7.1.11). In-process actions /
        // filters and content shortcodes; ShortcodeManager applies the
        // cms.shortcode.output filter through HookManager. NOT a webhook system.
        $this->app->singleton('cms.hooks', fn () => new HookManager);
        $this->app->alias('cms.hooks', HookManager::class);

        $this->app->singleton('cms.shortcodes', fn ($app) => new ShortcodeManager($app->make('cms.hooks')));
        $this->app->alias('cms.shortcodes', ShortcodeManager::class);

        $this->app->singleton('cms.content', fn ($app) => new ContentManager(
            $app->make('cms.slug'),
            $app->make('cms.html'),
        ));
        $this->app->alias('cms.content', ContentManager::class);

        $this->app->singleton('cms.taxonomy', fn ($app) => new TaxonomyManager(
            $app->make('cms.slug'),
            $app->make('cms.html'),
        ));
        $this->app->alias('cms.taxonomy', TaxonomyManager::class);

        $this->app->singleton('cms.media', fn () => new MediaManager);
        $this->app->alias('cms.media', MediaManager::class);

        // Dynamic mega-menu resolver (Phase 11D) — reuses the Editorial Query
        // Builder so post-driven mega sources resolve in core, never in Blade.
        $this->app->singleton('cms.menu_mega', fn ($app) => new MenuMegaDataProvider(
            $app->make('cms.section_data'),
        ));
        $this->app->alias('cms.menu_mega', MenuMegaDataProvider::class);

        $this->app->singleton('cms.menu', fn ($app) => new MenuManager(
            $app->make('cms.slug'),
            $app->make('cms.menu_mega'),
        ));
        $this->app->alias('cms.menu', MenuManager::class);

        // Widget Foundation (v1.0.0-beta.7).
        $this->app->singleton('cms.widget', fn () => new WidgetManager);
        $this->app->alias('cms.widget', WidgetManager::class);

        $this->app->singleton('cms.theme', fn () => new ThemeManager);
        $this->app->alias('cms.theme', ThemeManager::class);

        // Theme asset publisher: copies themes/{slug}/assets into
        // public/themes/{slug} via `php artisan theme:publish` (v1.0.0-beta.7.1.12).
        $this->app->singleton('cms.theme_publisher', fn ($app) => new ThemeAssetPublisher($app->make('cms.theme')));
        $this->app->alias('cms.theme_publisher', ThemeAssetPublisher::class);

        // Theme Options framework: schema from the active theme, values in
        // cms_settings (v0.9.9).
        $this->app->singleton('cms.theme_option', fn ($app) => new ThemeOptionManager($app->make('cms.theme')));
        $this->app->alias('cms.theme_option', ThemeOptionManager::class);

        // Theme Custom CSS (v1.0.0-beta.7.1.13.4): admin-managed frontend/admin
        // CSS stored in the theme_options namespace, rendered through the Asset
        // Registry. Never echoes CSS directly.
        $this->app->singleton('cms.theme_custom_css', fn ($app) => new ThemeCustomCssManager($app->make('cms.theme_option')));
        $this->app->alias('cms.theme_custom_css', ThemeCustomCssManager::class);

        $this->app->singleton('cms.seo', fn () => new SeoManager);
        $this->app->alias('cms.seo', SeoManager::class);

        $this->app->singleton('cms.language', fn () => new LanguageManager);
        $this->app->alias('cms.language', LanguageManager::class);

        $this->app->singleton('cms.locale_preference', fn ($app) => new LocalePreferenceManager(
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.locale_preference', LocalePreferenceManager::class);

        // CORE-L10N.1B — Localization Platform foundation. Read-only facades over the
        // existing language + locale-preference authorities; they introduce NO second
        // configuration or persistence store. Plugins consume these contracts and can
        // never override any global localization value they expose.
        $this->app->singleton('cms.localization.config', fn ($app) => new LanguageConfiguration(
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.localization.config', LanguageConfiguration::class);
        $this->app->bind(LanguageConfigurationContract::class, 'cms.localization.config');

        $this->app->singleton('cms.localization.public_locale', fn ($app) => new PublicLocaleContext(
            $app->make('cms.language'),
            $app->make('cms.locale_preference'),
        ));
        $this->app->alias('cms.localization.public_locale', PublicLocaleContext::class);
        $this->app->bind(PublicLocaleContextContract::class, 'cms.localization.public_locale');

        // CORE-L10N.1B (P3.3) — the ONE request-scoped authority for the current
        // frontend resource, plus the single factory that turns it into a
        // LocalizationContext. REQUEST-SCOPED so state never leaks across requests,
        // queue jobs, Octane requests, or tests. SEO/switcher/canonical/hreflang
        // consume these; none reconstruct the current resource independently.
        $this->app->scoped('cms.localization.current_resource', fn () => new CurrentResourceContext);
        $this->app->alias('cms.localization.current_resource', CurrentResourceContext::class);

        $this->app->scoped('cms.localization.context_factory', fn ($app) => new CurrentLocalizationContextFactory(
            $app->make('cms.localization.current_resource'),
        ));
        $this->app->alias('cms.localization.context_factory', CurrentLocalizationContextFactory::class);

        // P3.3B — the render-boundary writer. The controller / render pipeline
        // publishes the current resource through this (SEO never does).
        // REQUEST-SCOPED so it always writes the current request's authority.
        $this->app->scoped('cms.localization.current_resource_publisher', fn ($app) => new CurrentResourcePublisher(
            $app->make('cms.localization.current_resource'),
        ));
        $this->app->alias('cms.localization.current_resource_publisher', CurrentResourcePublisher::class);

        // CORE-L10N A2 — the ONE canonical locale transition runtime (cms.locale.switch).
        // Single authority for changing the public locale: validate → resolve
        // safe redirect → persist preference → refresh current locale → emit
        // LocaleChanged. Plugins request a change here; they never implement it.
        $this->app->singleton('cms.localization.transition', fn ($app) => new \TheNguyen\CMS\Localization\LocaleTransition(
            $app->make(\TheNguyen\CMS\Services\LanguageManager::class),
            $app->make(\TheNguyen\CMS\Services\LocalePreferenceManager::class),
            $app->make(\TheNguyen\CMS\Localization\SafeInternalRedirect::class),
            $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
        ));
        $this->app->alias('cms.localization.transition', \TheNguyen\CMS\Localization\LocaleTransition::class);

        // CORE-L10N.1B — the single URL builder + the Core-owned routing strategies.
        $this->app->singleton('cms.localization.url_generator', fn ($app) => new LocalizedUrlGenerator(
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.localization.url_generator', LocalizedUrlGenerator::class);

        // P6.1 — the Route Dictionary persistence store (a PHP array file; read ONCE at boot,
        // never during a request). Storage only — no Runtime projection.
        $this->app->singleton(
            \TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface::class,
            fn () => new \TheNguyen\CMS\Localization\Dictionary\Persistence\ArrayFileRouteDictionaryStore(
                (string) config('cms.route_dictionary_path', config_path('cms-route-dictionary.php')),
            ),
        );

        // P6.3 — the boot-time collection point for plugin-contributed Dictionary sources.
        // Plugins register a RouteDictionarySourceInterface here during their provider boot
        // (ExtensionManager registers all plugin providers BEFORE any plugin routes load, so the
        // set is complete before the Dictionary is first composed). The Runtime never sees it.
        $this->app->singleton(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry::class);

        // P6.2/P6.3 — the build-time composer: merges all Dictionary sources into one canonical
        // payload. The persistence store is the base (lowest-priority) source; plugin sources
        // (from the registry above) merge on top by the frozen (priority, id) precedence.
        // Composition happens once at boot — never during a request.
        $this->app->singleton(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer::class, fn ($app) => new \TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer([
            new \TheNguyen\CMS\Localization\Dictionary\Composition\StoreRouteDictionarySource(
                $app->make(\TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface::class),
            ),
            ...$app->make(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry::class)->all(),
        ]));

        // P5H.1/P5H.1B/P6.1/P6.2 — the Route Segment Dictionary, built ONCE from the composed
        // sources via the loader (validation + collision-check at construction). The single source
        // of localized static route segments; resolvers, reverse route generation, the sitemap,
        // and the base-segment redirect consume it — none of them know it is composed/persisted.
        $this->app->singleton('cms.localization.dictionary', fn ($app) => (new \TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryLoader(
            $app->make(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer::class),
        ))->load());
        $this->app->alias('cms.localization.dictionary', \TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary::class);

        // P6.4 — the Project Dictionary administration service. A CONSUMER of the Runtime: it reads
        // the persisted store, validates edits via the frozen composer/loader, saves through the
        // store, and rebuilds the immutable Dictionary. Never part of the Runtime.
        $this->app->singleton(\TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager::class, fn ($app) => new \TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager(
            $app->make(\TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface::class),
            $app->make(\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry::class),
            $app,
            (string) config('cms.route_dictionary_path', config_path('cms-route-dictionary.php')),
        ));

        $this->app->singleton('cms.localization.strategies', function ($app) {
            $registry = new LocalizationStrategyRegistry;
            $config = $app->make('cms.localization.config');
            $generator = $app->make('cms.localization.url_generator');

            $registry->register(new PrefixLocalizationStrategy($config, $generator));
            $registry->register(new SessionLocalizationStrategy($config, $generator, $app->make('cms.locale_preference')));

            return $registry;
        });
        $this->app->alias('cms.localization.strategies', LocalizationStrategyRegistry::class);

        // The active strategy, resolved fresh from the configured `language.routing_strategy`
        // (fail-closed on an unknown key). Bound (not singleton) so a settings change is
        // reflected without rebinding.
        $this->app->bind(LocalizationStrategyContract::class, fn ($app) => $app->make('cms.localization.strategies')
            ->active($app->make('cms.localization.config')->routingStrategy()));

        // CORE-L10N.1B — the localized-resource resolver registry, seeded with the built-in Core
        // resolvers. Core resources register through the SAME abstraction as plugins; plugins add
        // their resolvers to this singleton during boot. Duplicate keys fail fast.
        $this->app->singleton('cms.localization.resolvers', function ($app) {
            $registry = new LocalizedResourceResolverRegistry;
            $permalinks = $app->make('cms.permalink');

            $registry->register(new HomeResolver);
            $registry->register(new PageResolver($permalinks));
            $registry->register(new PostResolver($permalinks));
            $registry->register(new CategoryResolver($permalinks));
            $registry->register(new TagResolver($permalinks));

            return $registry;
        });
        $this->app->alias('cms.localization.resolvers', LocalizedResourceResolverRegistry::class);

        // The single switch-target authority consumed by language_switcher() + SeoManager.
        $this->app->singleton('cms.localization.switch_targets', fn ($app) => new LocaleSwitchTargetService(
            $app->make('cms.localization.config'),
            $app->make('cms.localization.resolvers'),
            $app->make('cms.localization.public_locale'),
            $app->make('cms.localization.dictionary'),
            $app,
        ));
        $this->app->alias('cms.localization.switch_targets', LocaleSwitchTargetService::class);

        // CORE-L10N.1B (P3.2) — the ONE canonical resource→public-URL application
        // service. content_url()/term_url()/menu/sitemap/widgets/EditorUrls and
        // the Preview + SEO services all resolve a localized public URL through
        // here, so there is exactly one slug/route/prefix policy. It composes the
        // frozen platform (resolvers + url_generator) and owns no policy itself.
        $this->app->singleton('cms.localization.content_url', fn ($app) => new LocalizedContentUrlService(
            $app->make('cms.localization.resolvers'),
            $app->make('cms.localization.url_generator'),
            $app->make('cms.localization.config'),
        ));
        $this->app->alias('cms.localization.content_url', LocalizedContentUrlService::class);

        // CORE-L10N.1B (P3.1) — the ONE locale-aware Preview URL producer for the
        // Page/Post editor launcher + list tables. It delegates localized route
        // construction to the canonical content-URL service and signed preview
        // identity to the PreviewManager; it owns no localization policy.
        $this->app->singleton('cms.preview_url', fn ($app) => new PreviewUrlService(
            $app->make('cms.localization.content_url'),
            $app->make('cms.localization.config'),
            $app->make('cms.preview'),
        ));
        $this->app->alias('cms.preview_url', PreviewUrlService::class);

        $this->app->singleton('cms.permalink', fn () => new PermalinkManager);
        $this->app->alias('cms.permalink', PermalinkManager::class);

        // Users / Roles / Permissions Core (v1.0.0-beta.3).
        $this->app->singleton('cms.permission', fn () => new PermissionManager);
        $this->app->alias('cms.permission', PermissionManager::class);

        // Maintenance Mode Core (v1.0.0-beta.4).
        $this->app->singleton('cms.maintenance', fn () => new MaintenanceManager);
        $this->app->alias('cms.maintenance', MaintenanceManager::class);

        // Web Installer Core (v1.0.0-beta.6).
        $this->app->singleton('cms.installer', fn () => new InstallerManager);
        $this->app->alias('cms.installer', InstallerManager::class);

        // Manual Core Upgrade orchestrator (CORE-UPGRADE-1). The single
        // `cms.upgrade` authority: durable state + lock + backup + apply/recovery.
        $this->app->singleton('cms.upgrade', fn () => new \TheNguyen\CMS\Upgrade\UpgradeManager);
        $this->app->alias('cms.upgrade', \TheNguyen\CMS\Upgrade\UpgradeManager::class);

        // Extension Translation Framework (v1.0.0-beta.5): JSON interface
        // translations for core + active theme + active plugins.
        $this->app->singleton('cms.extension_translation', fn ($app) => new ExtensionTranslationManager(
            $app->make('cms.language'),
            $app->make('cms.theme'),
            $app->make('cms.extension'),
        ));
        $this->app->alias('cms.extension_translation', ExtensionTranslationManager::class);

        // Extension Framework Core (orchestrates themes + plugins).
        $this->app->singleton('cms.extension', fn ($app) => new ExtensionManager($app->make('cms.theme')));
        $this->app->alias('cms.extension', ExtensionManager::class);

        // Generic opt-in plugin public-asset provisioning (v1.0.0). Reusable by ANY plugin; holds no
        // plugin-specific logic. Copies a plugin's declared built assets into public/vendor/<slug>.
        $this->app->singleton('cms.plugin_asset_publisher', fn () => new PluginAssetPublisher());
        $this->app->alias('cms.plugin_asset_publisher', PluginAssetPublisher::class);

        // Theme/Plugin ZIP Installer (v1.0.0-beta.2).
        $this->app->singleton('cms.extension_installer', fn ($app) => new ExtensionInstaller(
            $app->make('cms.extension'),
            $app->make('cms.theme'),
            $app->make('cms.plugin_asset_publisher'),
        ));
        $this->app->alias('cms.extension_installer', ExtensionInstaller::class);

        // Plugin lifecycle: installs a plugin's database on activation, keeps
        // dashboards safe when a plugin is not installed, and provides the
        // architectural seams for future upgrade/uninstall flows.
        $this->app->singleton('cms.plugin_database', fn ($app) => new PluginDatabaseManager(
            $app->make('migrator'),
        ));
        $this->app->alias('cms.plugin_database', PluginDatabaseManager::class);

        $this->app->singleton('cms.plugin_installation_guard', fn ($app) => new PluginInstallationGuard(
            $app->make('cms.extension'),
            $app->make('cms.plugin_database'),
        ));
        $this->app->alias('cms.plugin_installation_guard', PluginInstallationGuard::class);

        $this->app->singleton('cms.plugin_lifecycle', fn ($app) => new PluginLifecycleManager(
            $app->make('cms.extension'),
            $app->make('cms.plugin_database'),
            $app->make('cms.plugin_installation_guard'),
        ));
        $this->app->alias('cms.plugin_lifecycle', PluginLifecycleManager::class);

        // Section engine (theme-architecture 13/14/17/18): SectionRegistry +
        // ComponentRegistry are the core catalogs; SectionResolver turns a
        // pagebuilder/06 document into typed ViewModels; PresetRepository reads
        // preset blueprints; HomepageResolver selects the active layout.
        // The section catalog passes through the generic `cms.sections.schema`
        // filter so an extension can add settings/fields (e.g. a new data source
        // option) without core knowing about it. Resolved lazily (after boot), so
        // plugin filters are registered by the time it runs; with no listeners the
        // schema is returned unchanged (core behavior preserved).
        $this->app->singleton('cms.section_registry', function () {
            $schema = (array) config('cms-sections', []);

            if (function_exists('apply_filters')) {
                $filtered = apply_filters('cms.sections.schema', $schema);
                $schema = is_array($filtered) ? $filtered : $schema;
            }

            return new SectionRegistry($schema);
        });
        $this->app->alias('cms.section_registry', SectionRegistry::class);

        $this->app->singleton('cms.component_registry', fn () => new ComponentRegistry(
            (array) config('cms-components', []),
        ));
        $this->app->alias('cms.component_registry', ComponentRegistry::class);

        $this->app->singleton('cms.section_field_localizer', fn ($app) => new SectionFieldLocalizer(
            $app->make('cms.section_registry'),
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.section_field_localizer', SectionFieldLocalizer::class);

        $this->app->singleton('cms.section_data', fn ($app) => new SectionDataProvider(
            $app->make('cms.theme'),
        ));
        $this->app->alias('cms.section_data', SectionDataProvider::class);

        $this->app->singleton('cms.section_resolver', fn ($app) => new SectionResolver(
            $app->make('cms.section_registry'),
            $app->make('cms.media'),
            $app->make('cms.section_field_localizer'),
            $app->make('cms.section_data'),
        ));
        $this->app->alias('cms.section_resolver', SectionResolver::class);

        $this->app->singleton('cms.preset', fn ($app) => new PresetRepository($app->make('cms.theme')));
        $this->app->alias('cms.preset', PresetRepository::class);

        $this->app->singleton('cms.homepage', fn ($app) => new HomepageResolver(
            $app->make('cms.theme'),
            $app->make('cms.preset'),
            $app->make('cms.section_resolver'),
            $app->make('cms.settings'),
        ));
        $this->app->alias('cms.homepage', HomepageResolver::class);

        // Layout Editor (theme-architecture 18 / pagebuilder/06): the reusable
        // homepage-layout editing core used by every preset.
        $this->app->singleton('cms.layout_editor', fn ($app) => new LayoutEditor(
            $app->make('cms.settings'),
            $app->make('cms.preset'),
            $app->make('cms.section_registry'),
            $app->make('cms.section_resolver'),
            $app->make('cms.theme'),
            $app->make('cms.media'),
            $app->make('cms.section_field_localizer'),
        ));
        $this->app->alias('cms.layout_editor', LayoutEditor::class);

        // Generic Demo Importer (theme-architecture 16, generalized): discovers
        // and imports theme AND active-plugin demo packages.
        $this->app->singleton('cms.demo_menu_importer', fn ($app) => new DemoMenuImporter(
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.demo_menu_importer', DemoMenuImporter::class);

        $this->app->singleton('cms.demo_importer', fn ($app) => new DemoImporter(
            $app->make('cms.settings'),
            $app->make('cms.theme_option'),
            $app->make('cms.section_resolver'),
            $app->make('cms.media'),
            $app->make('cms.extension'),
            $app->make('cms.demo_menu_importer'),
        ));
        $this->app->alias('cms.demo_importer', DemoImporter::class);
    }

    public function boot(): void
    {
        $this->publishes([
            self::CONFIG_PATH => config_path('cms.php'),
        ], 'cms-config');

        $this->ensureDirectoriesExist();

        // Asset Registry (v1.0.0-beta.7.1.13.1): register no-output dependency
        // anchors so plugins/themes can depend on 'tncms.frontend'/'tncms.admin'
        // without a missing-dependency warning. DB-free; safe on a fresh boot.
        $assets = $this->app->make('cms.assets');
        $assets->registerMarker('tncms.frontend', 'frontend');
        $assets->registerMarker('tncms.admin', 'admin');

        // DB-free install check (marker/env only). When false — a fresh,
        // pre-install boot — every database-touching boot step is skipped so the
        // boot performs ZERO queries and cannot fail on an unmigrated database.
        // Installer and core (web.php) routes are always registered below.
        $installed = $this->cmsInstalled();

        // Zero-CLI install (CORE-DIST-1-H2): before the CMS is installed, a fresh
        // shared-hosting extract has no APP_KEY, so cookie/session encryption would
        // throw on the first request and the /install wizard would be unreachable.
        // Prepend a key-bootstrap middleware to the "web" group so it runs ahead of
        // EncryptCookies. Registered ONLY while not installed — zero overhead after.
        if (! $installed) {
            $this->app['router']->prependMiddlewareToGroup(
                'web',
                \TheNguyen\CMS\Http\Middleware\EnsureInstallerAppKey::class,
            );
        }

        // The "theme::" Blade namespace is filesystem-only and must resolve even
        // before the CMS is installed (fresh boot, or the test harness before
        // migrations run) so the frontend can render the default theme. This is
        // DB-free (zero queries). Once installed, registerThemeViews() layers the
        // DB-resolved active theme on top.
        $this->app->make('cms.theme')->registerViewNamespace();

        if ($installed) {
            $this->registerThemeViews();
        }

        // Register the built-in widget types + core areas in memory (no DB) so
        // they are available regardless of install state; plugin widgets then
        // register during loadRoutes() (bootExtensions), and the active theme's
        // widgets/areas are registered + synced afterwards.
        $this->registerCoreWidgets();
        $this->registerBuiltInShortcodes();
        $this->registerCoreHookDefinitions();
        $this->registerCorePreviewTypes();
        $this->registerScriptSettingsRendering();

        $this->loadRoutes($installed);

        if ($installed) {
            $this->registerExtensionTranslations();
            $this->registerThemeWidgets();
            $this->registerThemeCustomCss();

            // Core Mail Platform: bridge persisted mail settings into Laravel Mail once, at
            // boot (guarded; no-op when mail settings are disabled/invalid). CORE-MAIL-1.
            $this->app->make(\TheNguyen\CMS\Mail\MailConfigurationBridge::class)->apply();
        }

        $this->loadMigrationsFrom(self::MIGRATIONS_PATH);

        // Package-owned admin views (the Widgets page, etc.) under the "cms"
        // namespace, so cms-core Filament pages travel with the package
        // (v1.0.0-beta.7.1).
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'cms');

        $this->registerCommands();
        $this->registerPublicCacheInvalidation();
    }

    /**
     * "Is the CMS installed?" check for the boot hot path. Never throws.
     *
     * Fast path: a marker file or TN_CMS_INSTALLED env flag returns true with
     * ZERO database queries — the normal state for any installed site. Only when
     * both are absent does it fall back to a single guarded DB existence check,
     * which both supports sites installed without the web wizard and returns
     * false (skipping all DB-dependent boot steps) on a fresh, unmigrated, or
     * unreachable database instead of failing the boot.
     */
    private function cmsInstalled(): bool
    {
        try {
            return $this->app->make('cms.installer')->isInstalled();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Invalidate the public content cache whenever a record that can change a
     * public URL, archive, or rendered page is written (v1.0.0-beta.6.3).
     *
     * Hooks the Eloquent saved/deleted events — the existing save flow — for
     * content, terms, their translations, slugs, menus, and settings (theme
     * activation and permalink/language settings all persist through Setting,
     * so they are covered here too). Each flush is a single cheap version bump;
     * see {@see PublicContentCacheManager::flush()}.
     */
    private function registerPublicCacheInvalidation(): void
    {
        $flush = static function (): void {
            try {
                app('cms.public_cache')->flush();
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $models = [
            Content::class,
            ContentTranslation::class,
            Term::class,
            TermTranslation::class,
            Slug::class,
            Menu::class,
            MenuTranslation::class,
            MenuItem::class,
            MenuItemTranslation::class,
            Setting::class,
            SettingTranslation::class,
            // Widget Foundation (v1.0.0-beta.7): any change to a widget instance,
            // its translations, or an area can change rendered public output.
            WidgetModel::class,
            WidgetTranslation::class,
            WidgetArea::class,
        ];

        foreach ($models as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }

    /**
     * Register the active theme + plugin JSON translation paths with Laravel's
     * translator. Runs after loadRoutes() (so active plugins are known) and is
     * best-effort — it never breaks boot.
     */
    private function registerExtensionTranslations(): void
    {
        try {
            $this->app->make('cms.extension_translation')->registerActiveTranslationPaths();
        } catch (\Throwable) {
            // Best-effort during boot.
        }
    }

    /**
     * Boot every active plugin (PSR-4 autoload, providers, routes, views,
     * migrations). Runs between the core web routes and the frontend catch-all
     * so plugin routes take precedence. Each plugin is isolated; a broken
     * plugin is logged and skipped, never breaking boot.
     */
    private function bootExtensions(): void
    {
        try {
            $this->app->make('cms.extension')->bootActivePlugins();
        } catch (\Throwable) {
            // Extension booting is best-effort; never break application boot.
        }
    }

    /**
     * Register the active theme's "theme::" Blade namespace (with default
     * theme fallback). Wrapped so a missing/broken theme never breaks boot.
     */
    private function registerThemeViews(): void
    {
        try {
            $this->app->make('cms.theme')->registerViews();
        } catch (\Throwable) {
            // Theme view registration is best-effort during boot.
        }
    }

    /**
     * The core widget areas (sidebars/slots) every site exposes. Themes and
     * plugins may register more. See WidgetManager + CMS_GUIDE §Widgets.
     */
    private const CORE_WIDGET_AREAS = [
        ['slug' => 'sidebar-blog', 'name' => 'Blog Sidebar'],
        ['slug' => 'sidebar-page', 'name' => 'Page Sidebar'],
        ['slug' => 'footer-1', 'name' => 'Footer 1'],
        ['slug' => 'footer-2', 'name' => 'Footer 2'],
        ['slug' => 'footer-3', 'name' => 'Footer 3'],
        ['slug' => 'before-footer', 'name' => 'Before Footer'],
        ['slug' => 'after-post', 'name' => 'After Post'],
        // Mega-menu panel areas (Phase 11C) — a menu item with display=mega and
        // mega_source=widget_area can render one of these inside its panel.
        ['slug' => 'menu.topics', 'name' => 'Mega Menu: Topics'],
        ['slug' => 'menu.resources', 'name' => 'Mega Menu: Resources'],
        ['slug' => 'menu.products', 'name' => 'Mega Menu: Products'],
    ];

    /**
     * Register the built-in widget types and the core widget areas (in-memory).
     * Pure registry work — no database — so it is always safe to run on boot.
     * Never breaks boot.
     */
    private function registerCoreWidgets(): void
    {
        try {
            /** @var WidgetManager $widgets */
            $widgets = $this->app->make('cms.widget');

            $widgets->register(TextWidget::class);
            $widgets->register(HtmlWidget::class);
            $widgets->register(RecentPostsWidget::class);
            $widgets->register(CategoriesWidget::class);

            foreach (self::CORE_WIDGET_AREAS as $i => $area) {
                $widgets->registerArea($area['slug'], $area['name'], [
                    'source' => 'core',
                    'sort_order' => $i,
                ]);
            }

            // Core presets (v1.0.0-beta.7.1) — reusable bundles of built-in
            // widgets an admin can stamp into any area in one click.
            $widgets->registerPreset('blog-sidebar', [
                'name' => 'Blog Sidebar',
                'widgets' => [
                    ['type' => 'categories'],
                    ['type' => 'recent-posts'],
                ],
            ]);
            $widgets->registerPreset('simple-footer', [
                'name' => 'Simple Footer',
                'widgets' => [
                    ['type' => 'text'],
                    ['type' => 'html'],
                ],
            ]);
        } catch (\Throwable) {
            // Widget registration is best-effort; never break application boot.
        }
    }

    /**
     * Register the minimal, safe built-in shortcodes (v1.0.0-beta.7.1.11):
     * [button], [year] and [site_name]. Pure registry work (no database), always
     * safe on boot. Built-ins sanitize their own output; see ShortcodeManager.
     */
    private function registerBuiltInShortcodes(): void
    {
        try {
            /** @var ShortcodeManager $shortcodes */
            $shortcodes = $this->app->make('cms.shortcodes');

            // [button url="…" target="_self|_blank"]Label[/button]
            $shortcodes->register('button', static function (array $attrs, ?string $content): string {
                $url = self::sanitizeShortcodeUrl((string) ($attrs['url'] ?? ($attrs['href'] ?? '')));
                $target = (($attrs['target'] ?? '_self') === '_blank') ? '_blank' : '_self';
                $label = trim((string) ($content ?? ''));
                $label = $label !== '' ? $label : (string) ($attrs['text'] ?? 'Read more');

                $rel = $target === '_blank' ? ' rel="noopener noreferrer"' : '';

                return sprintf(
                    '<a class="tn-shortcode-button" href="%s" target="%s"%s>%s</a>',
                    e($url),
                    e($target),
                    $rel,
                    e($label),
                );
            });

            // [year] -> current year.
            $shortcodes->register('year', static fn (): string => date('Y'));

            // [site_name] -> localized site name (falls back to the global setting
            // then the configured CMS name).
            $shortcodes->register('site_name', static function (): string {
                $name = setting_localized('general.site_name');

                if (! is_string($name) || $name === '') {
                    $name = settings('general.site_name', config('cms.name', 'TN CMS'));
                }

                return e((string) $name);
            });
        } catch (\Throwable) {
            // Built-in shortcode registration is best-effort; never break boot.
        }
    }

    /**
     * Wire admin-managed scripts.* settings into the ScriptManager at render
     * time (v1.0.0-beta.7.1.13.2). The Global Script Manager fires
     * cms.scripts.rendering_head / rendering_footer at the start of each render;
     * we register the configured settings there so they pass through the same
     * validation. Idempotent (ScriptManager de-duplicates by key); never breaks
     * boot or rendering.
     */
    private function registerScriptSettingsRendering(): void
    {
        try {
            $apply = function (): void {
                app('cms.script_settings')->apply();
            };

            add_action('cms.scripts.rendering_head', $apply);
            add_action('cms.scripts.rendering_footer', $apply);
        } catch (\Throwable) {
            // Best-effort wiring; the frontend must still render without it.
        }
    }

    /**
     * Register the admin-managed Theme Custom CSS into the Asset Registry at boot
     * (v1.0.0-beta.7.1.13.4). Inline styles are active on registration and the
     * registry filters by scope at render time, so registering both the
     * frontend- and admin-scoped CSS once here covers every render without a new
     * rendering hook. Best-effort; a failure must never break boot or rendering.
     */
    private function registerThemeCustomCss(): void
    {
        try {
            $this->app->make('cms.theme_custom_css')->apply($this->app->make('cms.assets'));
        } catch (\Throwable) {
            // Custom CSS is optional decoration; never break boot for it.
        }
    }

    /**
     * Document the core action/filter hook points (v1.0.0-beta.7.1.11.1).
     *
     * Definitions are descriptive only — every hook fires with or without one.
     * They give plugin/theme authors (and future Developer Tools) a discoverable
     * catalogue of the extension surface. Best-effort; never breaks boot.
     */
    private function registerCoreHookDefinitions(): void
    {
        try {
            /** @var HookManager $hooks */
            $hooks = $this->app->make('cms.hooks');
            $since = \TheNguyen\CMS\Support\CmsInfo::VERSION;
            $content = \TheNguyen\CMS\Models\Content::class;
            $term = \TheNguyen\CMS\Models\Term::class;
            $media = \TheNguyen\CMS\Models\Media::class;
            $ctx = HookContext::class;

            $actions = [
                ['cms.content.saving', 'Fires before a content item (page/post) is persisted.', ['content' => $content, 'data' => 'array', 'context' => $ctx], 'Content'],
                ['cms.content.saved', 'Fires after a content item has been saved.', ['content' => $content, 'data' => 'array', 'context' => $ctx], 'Content'],
                ['cms.term.saving', 'Fires before a taxonomy term is persisted.', ['term' => $term, 'data' => 'array', 'context' => $ctx], 'Taxonomy'],
                ['cms.term.saved', 'Fires after a taxonomy term has been saved.', ['term' => $term, 'data' => 'array', 'context' => $ctx], 'Taxonomy'],
                ['cms.media.uploaded', 'Fires after a media file has been uploaded.', ['media' => $media, 'context' => $ctx], 'Media'],
                ['cms.post.rendered', 'Fires after a single post body has been rendered.', ['content' => $content, 'context' => $ctx], 'Rendering'],
                ['cms.page.rendered', 'Fires after a single page body has been rendered.', ['content' => $content, 'context' => $ctx], 'Rendering'],
                ['cms.theme.header', 'Theme placeholder rendered inside <body>, before the header.', [], 'Theme'],
                ['cms.theme.before_content', 'Theme placeholder rendered before the main content.', [], 'Theme'],
                ['cms.theme.after_content', 'Theme placeholder rendered after the main content.', [], 'Theme'],
                ['cms.theme.footer', 'Theme placeholder rendered before the closing </body>.', [], 'Theme'],
                ['cms.scripts.settings.loading', 'Fires before admin-managed scripts.* settings are registered into the Global Script Manager, letting plugins inject their own settings-managed scripts. Receives the target ScriptManager (v1.0.0-beta.7.1.13.3).', ['manager' => \TheNguyen\CMS\Services\ScriptManager::class], 'Scripts'],
            ];

            foreach ($actions as [$name, $description, $arguments, $group]) {
                $hooks->defineAction(HookDefinition::action($name, $description, $arguments, $since, 'core', $group));
            }

            $filters = [
                ['cms.content.title', 'Filters a content item title at render time.', ['title' => 'string', 'content' => $content, 'context' => $ctx], 'string', 'Content'],
                ['cms.content.excerpt', 'Filters a content item excerpt at render time.', ['excerpt' => '?string', 'content' => $content, 'context' => $ctx], '?string', 'Content'],
                ['cms.content.body', 'Filters the raw content body before shortcodes run.', ['body' => '?string', 'content' => $content, 'context' => $ctx], '?string', 'Content'],
                ['cms.content.before_shortcode', 'Filters the content body immediately before shortcode expansion.', ['body' => '?string', 'content' => $content, 'context' => $ctx], '?string', 'Content'],
                ['cms.content.after_shortcode', 'Filters the content body immediately after shortcode expansion.', ['body' => 'string', 'content' => $content, 'context' => $ctx], 'string', 'Content'],
                ['cms.shortcode.output', 'Filters a single shortcode\'s rendered output.', ['output' => 'string', 'tag' => 'string', 'attrs' => 'array', 'content' => '?string', 'context' => 'array', 'hook_context' => $ctx], 'string', 'Shortcode'],
                ['cms.menu.resolve', 'Resolves a render-ready menu tree for a frontend location that has no local menu. Return null to skip (renders empty); return an array of menu nodes to inject (e.g. a remote menu).', ['menu' => '?array', 'location' => 'string', 'locale' => '?string'], '?array', 'Menu'],
                ['cms.sections.schema', 'Filters the section catalog (type => settings/fields schema) before the SectionRegistry is built, so an extension can add settings or fields (e.g. a new data source option). Return the schema map unchanged to opt out.', ['schema' => 'array'], 'array', 'Section'],
                ['cms.section.data_source', 'Resolves repeater data for a section whose `data_source` is not a core mode (manual/placeholder/posts). Return null to fall through to authored content; return the injected repeater map (e.g. `["posts" => [...]]`) to render an extension source.', ['data' => '?array', 'sectionType' => 'string', 'mode' => 'string', 'settings' => 'array', 'locale' => '?string', 'currentPost' => '?Content'], '?array', 'Section'],
            ];

            foreach ($filters as [$name, $description, $arguments, $returnType, $group]) {
                $hooks->defineFilter(HookDefinition::filter($name, $description, $arguments, $returnType, $since, 'core', $group));
            }

            // Preview API lifecycle (v1.0.0-beta.7.1.12.1). Every preview callback
            // receives a PreviewContext as its final argument.
            $previewSince = '1.0.0-beta.7.1.12.1';
            $previewable = \TheNguyen\CMS\Contracts\Previewable::class;
            $pctx = \TheNguyen\CMS\Support\Preview\PreviewContext::class;

            $previewActions = [
                ['cms.preview.generating', 'Fires before a signed preview URL is generated.', ['previewable' => $previewable, 'options' => 'array']],
                ['cms.preview.generated', 'Fires after a signed preview URL has been generated.', ['url' => 'string', 'previewable' => $previewable, 'options' => 'array']],
                ['cms.preview.rendering', 'Fires before a resolved preview is rendered.', ['previewable' => $previewable, 'context' => $pctx]],
                ['cms.preview.rendered', 'Fires after a preview response has been built.', ['previewable' => $previewable, 'context' => $pctx]],
                ['cms.preview.denied', 'Fires when a preview request is denied (unknown type or require_login).', ['type' => 'string', 'key' => 'string|int', 'reason' => 'string', 'context' => $pctx]],
                ['cms.preview.expired', 'Fires when an expired preview URL is refused.', ['type' => 'string', 'key' => 'string|int', 'reason' => 'string', 'context' => $pctx]],
            ];

            foreach ($previewActions as [$name, $description, $arguments]) {
                $hooks->defineAction(HookDefinition::action($name, $description, $arguments, $previewSince, 'core', 'Preview'));
            }

            $previewFilters = [
                ['cms.preview.url', 'Filters the generated signed preview URL.', ['url' => 'string', 'previewable' => $previewable, 'options' => 'array'], 'string'],
                ['cms.preview.response', 'Filters the built preview response before headers are re-stamped.', ['response' => \Symfony\Component\HttpFoundation\Response::class, 'previewable' => $previewable, 'context' => $pctx], \Symfony\Component\HttpFoundation\Response::class],
                ['cms.preview.metadata', 'Filters the preview metadata array (plugins may add descriptive fields).', ['metadata' => 'array', 'previewable' => $previewable, 'options' => 'array'], 'array'],
                ['cms.preview.context', 'Filters the immutable PreviewContext before rendering.', ['context' => $pctx, 'previewable' => $previewable], $pctx],
            ];

            foreach ($previewFilters as [$name, $description, $arguments, $returnType]) {
                $hooks->defineFilter(HookDefinition::filter($name, $description, $arguments, $returnType, $previewSince, 'core', 'Preview'));
            }

            // Admin Form Hook Bridge + admin/lifecycle extensions
            // (v1.0.0-beta.7.1.12.2).
            $bridgeSince = '1.0.0-beta.7.1.12.2';
            $content = \TheNguyen\CMS\Models\Content::class;
            $media = \TheNguyen\CMS\Models\Media::class;
            $ctx = \TheNguyen\CMS\Support\Hooks\HookContext::class;

            // New lifecycle actions (existing saving/saved/uploaded remain).
            $lifecycleActions = [
                ['cms.content.deleting', 'Fires before a content record is deleted.', ['content' => $content, 'context' => $ctx], 'Content'],
                ['cms.content.deleted', 'Fires after a content record is deleted.', ['content' => $content, 'context' => $ctx], 'Content'],
                ['cms.media.uploading', 'Fires after validation, before a media file is written.', ['file' => \Illuminate\Http\UploadedFile::class, 'context' => $ctx], 'Media'],
                ['cms.media.deleting', 'Fires before a media record is deleted.', ['media' => $media, 'context' => $ctx], 'Media'],
                ['cms.media.deleted', 'Fires after a media record is deleted.', ['media' => $media, 'context' => $ctx], 'Media'],
                ['cms.admin.assets', 'Echo admin head assets (style/script/meta). Rendered into the admin <head>.', [], 'Admin'],
            ];

            foreach ($lifecycleActions as [$name, $description, $arguments, $group]) {
                $hooks->defineAction(HookDefinition::action($name, $description, $arguments, $bridgeSince, 'core', $group));
            }

            // Admin form schema/region filters, base + TN CMS-owned aliases.
            $formArgs = ['components' => 'array', 'modelClass' => '?string', 'alias' => '?string', 'context' => 'array'];
            $formFilters = [['cms.form.schema', 'Filter a TN CMS admin form component array (all resources).']];
            $regionArgs = ['regions' => 'array', 'modelClass' => '?string', 'alias' => '?string', 'context' => 'array'];
            $regionFilters = [['cms.form.regions', 'Contribute extra full-width form regions (all resources).']];

            foreach (['post', 'page', 'term', 'media'] as $alias) {
                $formFilters[] = ["cms.form.schema.{$alias}", "Filter the {$alias} admin form component array."];
                $regionFilters[] = ["cms.form.regions.{$alias}", "Contribute extra full-width regions to the {$alias} form."];
            }

            foreach ($formFilters as [$name, $description]) {
                $hooks->defineFilter(HookDefinition::filter($name, $description, $formArgs, 'array', $bridgeSince, 'core', 'Admin Forms'));
            }

            foreach ($regionFilters as [$name, $description]) {
                $hooks->defineFilter(HookDefinition::filter($name, $description, $regionArgs, 'array', $bridgeSince, 'core', 'Admin Forms'));
            }

            // Admin panel filters.
            $hooks->defineFilter(HookDefinition::filter('cms.admin.navigation', 'Contribute extra admin navigation items (array of Filament NavigationItem).', ['items' => 'array'], 'array', $bridgeSince, 'core', 'Admin'));
            $hooks->defineFilter(HookDefinition::filter('cms.admin.dashboard.widgets', 'Filter the admin dashboard widget class list.', ['widgets' => 'array'], 'array', $bridgeSince, 'core', 'Admin'));

            // Account Foundation (v1.0.0-beta.7.1.15). Lifecycle actions carry the
            // affected User + a HookContext; the render-area actions (echoed via
            // render_hook in the account views) let plugins inject markup. Filters
            // let plugins extend the navigation, form data, and redirects. Core
            // owns identity/security/preferences only — plugins add their own
            // account sections (Orders/Addresses/Wishlist/…) through these.
            $accountSince = '1.0.0-beta.7.1.15';
            $user = \App\Models\User::class;

            $accountActions = [
                // Lifecycle.
                ['cms.account.profile.updating', 'Fires before a profile is saved.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.profile.updated', 'Fires after a profile has been saved.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.email.updating', 'Fires before the account email changes.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.email.updated', 'Fires after the account email has changed.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.password.updating', 'Fires before the account password changes.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.password.updated', 'Fires after the account password has changed.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.preferences.updating', 'Fires before locale/timezone preferences are saved.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.preferences.updated', 'Fires after locale/timezone preferences are saved.', ['user' => $user, 'context' => $ctx]],
                ['cms.account.sessions.invalidated', 'Fires after other sessions are logged out.', ['user' => $user, 'context' => $ctx]],
                // Render areas (echo markup into the account shell/pages).
                ['cms.account.before', 'Echo markup at the very top of every account page.', ['context' => $ctx]],
                ['cms.account.after', 'Echo markup at the very bottom of every account page.', ['context' => $ctx]],
                ['cms.account.sidebar.before', 'Echo markup above the account sidebar.', ['context' => $ctx]],
                ['cms.account.sidebar.after', 'Echo markup below the account sidebar.', ['context' => $ctx]],
                ['cms.account.navigation', 'Echo extra account navigation markup (below the core items).', ['context' => $ctx]],
                ['cms.account.dashboard.before', 'Echo markup above the dashboard overview.', ['context' => $ctx]],
                ['cms.account.dashboard.after', 'Echo markup below the dashboard overview.', ['context' => $ctx]],
                ['cms.account.profile.after', 'Echo markup below the profile form.', ['context' => $ctx]],
                ['cms.account.security.after', 'Echo markup below the security forms.', ['context' => $ctx]],
                ['cms.account.preferences.after', 'Echo markup below the preferences form.', ['context' => $ctx]],
            ];

            foreach ($accountActions as [$name, $description, $arguments]) {
                $hooks->defineAction(HookDefinition::action($name, $description, $arguments, $accountSince, 'core', 'Account'));
            }

            $accountFilters = [
                ['cms.account.navigation_items', 'Add/remove/reorder account navigation items (array of AccountNavItem or arrays).', ['items' => 'array', 'context' => $ctx], 'array'],
                ['cms.account.profile_data', 'Filter the validated profile payload before it is saved.', ['data' => 'array', 'context' => $ctx], 'array'],
                ['cms.account.preferences_data', 'Filter the validated preferences payload before it is saved.', ['data' => 'array', 'context' => $ctx], 'array'],
                ['cms.account.redirect_after_update', 'Filter the redirect target after an account update.', ['target' => 'string', 'context' => $ctx], 'string'],
            ];

            foreach ($accountFilters as [$name, $description, $arguments, $returnType]) {
                $hooks->defineFilter(HookDefinition::filter($name, $description, $arguments, $returnType, $accountSince, 'core', 'Account'));
            }
        } catch (\Throwable) {
            // Definition registration is best-effort; never break boot.
        }
    }

    /**
     * Register the core "cms.post" and "cms.page" previewable types with the
     * PreviewManager (v1.0.0-beta.7.1.12.1). The resolver loads a content record
     * by id (ANY status) with its translations; the renderer re-uses the SAME
     * frontend pipeline as the published page via
     * {@see \TheNguyen\CMS\Http\Controllers\FrontendController::renderPreviewable()}.
     *
     * Best-effort and fully guarded: if the preview binding is unavailable this
     * simply skips — no preview types, never a boot failure.
     */
    private function registerCorePreviewTypes(): void
    {
        try {
            if (! $this->app->bound('cms.preview')) {
                return;
            }

            /** @var PreviewManager $preview */
            $preview = $this->app->make('cms.preview');

            $resolver = static function (string $type): callable {
                return static function (string|int $key) use ($type): ?\TheNguyen\CMS\Models\Content {
                    return \TheNguyen\CMS\Models\Content::query()
                        ->where('type', $type)
                        ->with('translations')
                        ->find($key);
                };
            };

            $renderer = static function (\TheNguyen\CMS\Models\Content $content, $request) {
                $locale = is_string($request?->query('locale')) ? $request->query('locale') : null;

                return app(\TheNguyen\CMS\Http\Controllers\FrontendController::class)
                    ->renderPreviewable($content, $locale);
            };

            $version = '1.0.0-beta.7.1.12.1';

            $preview->register('cms.post', $resolver('post'), $renderer, ['label' => 'Posts', 'version' => $version]);
            $preview->register('cms.page', $resolver('page'), $renderer, ['label' => 'Pages', 'version' => $version]);
        } catch (\Throwable) {
            // Core preview registration is best-effort; never break boot.
        }
    }

    /**
     * Sanitize a shortcode URL to a safe value: only http(s), mailto, tel and
     * site-relative paths are allowed. Anything else (javascript:, data:, …)
     * collapses to '#', so a built-in shortcode can never emit an executable URL.
     */
    private static function sanitizeShortcodeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '#';
        }

        // Relative paths and anchors are safe.
        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            // Schemeless like "example.com/path" — treat as relative-ish, safe.
            return $url;
        }

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true) ? $url : '#';
    }

    /**
     * Register the active theme's declared widgets + areas (from functions.php)
     * and mirror every registered area into cms_widget_areas. Runs only when the
     * CMS is installed (it touches the database). Never breaks boot.
     */
    private function registerThemeWidgets(): void
    {
        try {
            /** @var WidgetManager $widgets */
            $widgets = $this->app->make('cms.widget');

            $theme = $this->app->make('cms.theme')->active();

            if ($theme !== null) {
                $config = $this->app->make('cms.theme')->themeConfig($theme->slug);

                foreach ((array) ($config['widgets'] ?? []) as $class) {
                    if (is_string($class)) {
                        $widgets->register($class);
                    }
                }

                foreach ((array) ($config['widget_areas'] ?? []) as $area) {
                    if (is_array($area) && isset($area['slug'], $area['name'])
                        && is_string($area['slug']) && is_string($area['name'])) {
                        $widgets->registerArea($area['slug'], $area['name'], [
                            'description' => isset($area['description']) && is_string($area['description']) ? $area['description'] : null,
                            'source' => 'theme',
                            'source_slug' => $theme->slug,
                        ]);
                    }
                }
            }

            // Persist the in-memory area registry so the admin + render paths
            // have stable area ids to assign widgets to.
            $widgets->syncAreas();
        } catch (\Throwable) {
            // Best-effort during boot.
        }
    }

    private function ensureDirectoriesExist(): void
    {
        $paths = [
            base_path('modules'),
            base_path('themes'),
            base_path('plugins'),
            public_path('uploads'),
            public_path('themes'),
            public_path('vendor/cms'),
        ];

        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true, true);
            }
        }
    }

    private function loadRoutes(bool $installed = true): void
    {
        // Canonical default-locale policy: expose a generic alias so the core
        // frontend group AND active plugins can strip a duplicate default-locale
        // prefix (301 → canonical). Registered before any route file loads.
        $this->app['router']->aliasMiddleware('cms.canonical-locale', RedirectDefaultLocalePrefix::class);

        // CORE-L10N A1: the ONE Core locale-apply runtime. Any route group (core
        // or plugin) attaches "cms.locale" to establish the request's public
        // locale via the active strategy — so plugins never write locale
        // bootstrap and Core stays the sole locale runtime.
        $this->app['router']->aliasMiddleware('cms.locale', \TheNguyen\CMS\Http\Middleware\ApplyPublicLocale::class);

        // P5H.1B: 301 a canonical base segment to its localized dictionary projection
        // (/vi/products/x → /vi/san-pham/x). Attached to the localized route groups.
        $this->app['router']->aliasMiddleware('cms.localized-base', \TheNguyen\CMS\Http\Middleware\RedirectToLocalizedBaseSegment::class);

        if (file_exists(self::ROUTES_WEB)) {
            $this->loadRoutesFrom(self::ROUTES_WEB);
        }

        // Secure signed preview endpoint (v1.0.0-beta.7.1.16). Registered before
        // the frontend catch-all so /cms/preview/... resolves to the
        // PreviewController rather than the generic /{slug} page resolver.
        if (file_exists(self::ROUTES_PREVIEW)) {
            $this->loadRoutesFrom(self::ROUTES_PREVIEW);
        }

        // Web Installer routes (v1.0.0-beta.6) — registered before the frontend
        // catch-all and outside the maintenance gate so /install is always
        // reachable, with its own DB-free file session.
        if (file_exists(self::ROUTES_INSTALLER)) {
            $this->loadRoutesFrom(self::ROUTES_INSTALLER);
        }

        // Manual Core Upgrade wizard (CORE-UPGRADE-1) — the inverse of /install:
        // registered ONLY when the site is installed (§9), before the frontend
        // catch-all so /upgrade never resolves as a page slug, and outside the
        // maintenance gate (frontend-only) so it stays reachable during apply.
        if ($installed && file_exists(self::ROUTES_UPGRADE)) {
            $this->loadRoutesFrom(self::ROUTES_UPGRADE);
        }

        // Frontend Authentication (v1.0.0-beta.7.1.14): middleware aliases +
        // literal auth routes registered before the frontend catch-all so
        // /login, /register, /reset-password, /email/verify win over /{slug}.
        $router = $this->app['router'];
        $router->aliasMiddleware('cms.auth', \TheNguyen\CMS\Http\Middleware\FrontendAuthenticate::class);
        $router->aliasMiddleware('cms.guest', \TheNguyen\CMS\Http\Middleware\RedirectIfFrontendAuthenticated::class);
        $router->aliasMiddleware('cms.role', \TheNguyen\CMS\Http\Middleware\RequireRole::class);
        $router->aliasMiddleware('cms.permission', \TheNguyen\CMS\Http\Middleware\RequirePermission::class);
        $router->aliasMiddleware('cms.verified', \TheNguyen\CMS\Http\Middleware\EnsureFrontendEmailVerified::class);
        $router->aliasMiddleware('cms.frontend_session', \TheNguyen\CMS\Http\Middleware\EnforceFrontendSession::class);

        if (file_exists(self::ROUTES_AUTH)) {
            $this->loadRoutesFrom(self::ROUTES_AUTH);
        }

        // Account Foundation routes (v1.0.0-beta.7.1.15) — literal /account paths
        // before the frontend catch-all, so /account never resolves as a page slug.
        if (file_exists(self::ROUTES_ACCOUNT)) {
            $this->loadRoutesFrom(self::ROUTES_ACCOUNT);
        }

        // Active plugin routes register here — after the core infra routes but
        // BEFORE the frontend catch-all, so a plugin's /{path} wins over the
        // generic /{slug} resolver. Booting active plugins reads settings (DB),
        // so it is skipped entirely before install to keep boot query-free.
        if ($installed) {
            $this->bootExtensions();
        }

        // Frontend routes load last so the catch-all page route is registered
        // after cms-health, reserved routes, and plugin routes.
        if (file_exists(self::ROUTES_FRONTEND)) {
            $this->loadRoutesFrom(self::ROUTES_FRONTEND);
        }
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearSettingsCacheCommand::class,
                DemoListCommand::class,
                DemoImportCommand::class,
                DemoResetCommand::class,
                PluginActivateCommand::class,
                PluginDeactivateCommand::class,
                PluginListCommand::class,
                SlugRebuildCommand::class,
                MenuLocalizedUrlRepairCommand::class,
                ThemePublishCommand::class,
                DiagnoseLocalizationCommand::class,
            ]);
        }
    }
}
