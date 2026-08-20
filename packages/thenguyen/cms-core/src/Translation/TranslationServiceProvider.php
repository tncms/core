<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Taxonomy\Translation\Adapters\TaxonomyTranslationWriteAdapter;
use TheNguyen\CMS\Taxonomy\Translation\Adapters\TermTranslationReadAdapter;
use TheNguyen\CMS\Taxonomy\Translation\Drivers\TermTranslationDriver;
use TheNguyen\CMS\Translation\Adapters\PageTranslationReadAdapter;
use TheNguyen\CMS\Translation\Adapters\PageTranslationWriteAdapter;
use TheNguyen\CMS\Translation\Adapters\PostTranslationReadAdapter;
use TheNguyen\CMS\Translation\Adapters\PostTranslationWriteAdapter;
use TheNguyen\CMS\Translation\Admin\Actions\ClearLocaleValue;
use TheNguyen\CMS\Translation\Admin\Actions\CopyFromDefaultLocale;
use TheNguyen\CMS\Translation\Admin\FallbackPreviewResolver;
use TheNguyen\CMS\Translation\Admin\LocaleOptionsResolver;
use TheNguyen\CMS\Translation\Admin\LocalizedAdminManager;
use TheNguyen\CMS\Translation\Admin\LocalizedSlugGenerator;
use TheNguyen\CMS\Translation\Admin\LocalizedStateHydrator;
use TheNguyen\CMS\Translation\Admin\LocalizedValidationRules;
use TheNguyen\CMS\Translation\Admin\TranslationStatusResolver;
use TheNguyen\CMS\Translation\Cache\RuntimeTranslationCache;
use TheNguyen\CMS\Translation\Content\LocalizedContentManager;
use TheNguyen\CMS\Translation\Content\LocalizedContentRegistry;
use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationRepositoryInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\Diagnostics\TranslationRolloutDiagnostics;
use TheNguyen\CMS\Translation\Drivers\ContentTranslationDriver;
use TheNguyen\CMS\Translation\Drivers\NullTranslationDriver;
use TheNguyen\CMS\Translation\Drivers\TranslationDriverRegistry;
use TheNguyen\CMS\Translation\DTOs\LocaleDefinition;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityRepositoryInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityResolverInterface;
use TheNguyen\CMS\Translation\Entity\EntityFieldMapper;
use TheNguyen\CMS\Translation\Entity\LocalizedEntityManager;
use TheNguyen\CMS\Translation\Entity\LocalizedEntityQuery;
use TheNguyen\CMS\Translation\Entity\LocalizedEntityRepository;
use TheNguyen\CMS\Translation\Entity\LocalizedEntityResolver;
use TheNguyen\CMS\Translation\Locale\LocaleRegistry;
use TheNguyen\CMS\Translation\Repositories\NullTranslationRepository;
use TheNguyen\CMS\Translation\Resolver\TranslationResolver;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedSerializerInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Drivers\DatabaseLocalizedStorage;
use TheNguyen\CMS\Translation\Storage\Drivers\StorageTranslationDriver;
use TheNguyen\CMS\Translation\Storage\LocalizedStorageRepository;
use TheNguyen\CMS\Translation\Storage\Serialization\JsonLocalizedSerializer;
use TheNguyen\CMS\Translation\Storage\Validation\LocalizedValueValidator;
use TheNguyen\CMS\Translation\Support\FallbackChain;

/**
 * Wires the Translation Engine into the container (Phase 8.0), plus the Localized
 * Storage layer (Phase 8.1).
 *
 * Additive and self-contained: registered once from CmsServiceProvider. Binds
 * the registry, cache, driver registry, resolver and manager as singletons plus
 * the public contracts, then seeds the locale registry and the null driver from
 * config('translation'). Phase 8.1 additionally binds the storage backend,
 * serializer, validator and repository, and registers a non-default
 * storage-backed read driver so the resolver CAN consume stored values without
 * changing any existing default. Touches no existing module.
 */
final class TranslationServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../config/translation.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'translation');

        $this->app->singleton('cms.translation.registry', fn () => new LocaleRegistry);
        $this->app->alias('cms.translation.registry', LocaleRegistry::class);
        $this->app->alias('cms.translation.registry', LocaleRegistryInterface::class);

        $this->app->singleton('cms.translation.cache', fn () => new RuntimeTranslationCache);
        $this->app->alias('cms.translation.cache', RuntimeTranslationCache::class);
        $this->app->alias('cms.translation.cache', TranslationCacheInterface::class);

        $this->app->singleton('cms.translation.drivers', fn () => new TranslationDriverRegistry);
        $this->app->alias('cms.translation.drivers', TranslationDriverRegistry::class);

        $this->app->singleton(FallbackChain::class, fn () => new FallbackChain);

        $this->app->singleton('cms.translation.resolver', function (Application $app) {
            $config = (array) $app['config']->get('translation', []);

            return new TranslationResolver(
                $app->make('cms.translation.registry'),
                $app->make('cms.translation.drivers'),
                $app->make('cms.translation.cache'),
                $app->make(FallbackChain::class),
                [
                    'enabled' => (bool) ($config['enabled'] ?? true),
                    'cache' => (bool) ($config['cache']['enabled'] ?? true),
                    'default_driver' => $config['default_driver'] ?? null,
                    'fallback_chain' => $config['fallback_chain'] ?? null,
                ],
            );
        });
        $this->app->alias('cms.translation.resolver', TranslationResolver::class);
        $this->app->alias('cms.translation.resolver', TranslationResolverInterface::class);

        // Persistence seam — the no-op stays the default binding for backward
        // compatibility. The Phase 8.1 storage repository is a separate binding
        // (below) that callers opt into; nothing is rebound here.
        $this->app->singleton(TranslationRepositoryInterface::class, fn () => new NullTranslationRepository);

        $this->registerStorage();
        $this->registerEntity();
        $this->registerAdmin();
        $this->registerContent();
        $this->registerContentDriver();
        $this->registerTaxonomyDriver();

        $this->app->singleton('cms.translation', function (Application $app) {
            return new TranslationManager(
                $app->make('cms.translation.registry'),
                $app->make('cms.translation.resolver'),
                $app->make('cms.translation.cache'),
                $app->make('cms.translation.drivers'),
            );
        });
        $this->app->alias('cms.translation', TranslationManager::class);
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('translation.php')], 'cms-translation-config');

        $this->seedDrivers();
        $this->seedLocales();
        $this->seedStorageDriver();
        $this->seedContentDriver();
        $this->seedTaxonomyDriver();
    }

    /**
     * Bind the Phase 8.1 storage layer: serializer, backend, validator and the
     * orchestrating repository. All additive singletons; none rebinds an existing
     * engine contract.
     */
    private function registerStorage(): void
    {
        $this->app->singleton(LocalizedSerializerInterface::class, fn () => new JsonLocalizedSerializer);
        $this->app->alias(LocalizedSerializerInterface::class, 'cms.translation.storage.serializer');

        $this->app->singleton(LocalizedStorageInterface::class, fn () => new DatabaseLocalizedStorage);
        $this->app->alias(LocalizedStorageInterface::class, 'cms.translation.storage');

        $this->app->singleton(LocalizedValueValidator::class, fn (Application $app) => new LocalizedValueValidator(
            $app->make('cms.translation.registry'),
            (array) $app['config']->get('translation.storage.validation', []),
        ));

        $this->app->singleton('cms.translation.storage.repository', fn (Application $app) => new LocalizedStorageRepository(
            $app->make(LocalizedStorageInterface::class),
            $app->make(LocalizedValueValidator::class),
            $app->make('cms.translation.cache'),
            (array) $app['config']->get('translation.storage', []),
        ));
        $this->app->alias('cms.translation.storage.repository', LocalizedStorageRepository::class);
    }

    /**
     * Bind the Phase 8.2 Localized Entity Foundation: the mapping layer, the
     * entity repository/resolver, the query helper and the manager (single entry
     * point). All additive singletons over the Phase 8.1 storage; nothing rebinds
     * an existing contract.
     */
    private function registerEntity(): void
    {
        $this->app->singleton(EntityFieldMapper::class, fn () => new EntityFieldMapper);
        $this->app->alias(EntityFieldMapper::class, 'cms.translation.entity.mapper');

        $this->app->singleton(LocalizedEntityRepositoryInterface::class, fn (Application $app) => new LocalizedEntityRepository(
            $app->make(LocalizedStorageInterface::class),
            $app->make(LocalizedValueValidator::class),
            $app->make(EntityFieldMapper::class),
        ));
        $this->app->alias(LocalizedEntityRepositoryInterface::class, LocalizedEntityRepository::class);
        $this->app->alias(LocalizedEntityRepositoryInterface::class, 'cms.translation.entity.repository');

        $this->app->singleton(LocalizedEntityResolverInterface::class, fn (Application $app) => new LocalizedEntityResolver(
            $app->make(TranslationResolverInterface::class),
            $app->make(EntityFieldMapper::class),
            (string) $app['config']->get('translation.storage.driver', 'database'),
        ));
        $this->app->alias(LocalizedEntityResolverInterface::class, LocalizedEntityResolver::class);
        $this->app->alias(LocalizedEntityResolverInterface::class, 'cms.translation.entity.resolver');

        $this->app->singleton(LocalizedEntityQuery::class, fn (Application $app) => new LocalizedEntityQuery(
            $app->make(LocalizedStorageInterface::class),
            $app->make(EntityFieldMapper::class),
        ));
        $this->app->alias(LocalizedEntityQuery::class, 'cms.translation.entity.query');

        $this->app->singleton('cms.translation.entity', fn (Application $app) => new LocalizedEntityManager(
            $app->make(LocalizedEntityRepositoryInterface::class),
            $app->make(LocalizedEntityResolverInterface::class),
            $app->make(EntityFieldMapper::class),
            $app->make('cms.translation.cache'),
            (array) $app['config']->get('translation.storage', []),
        ));
        $this->app->alias('cms.translation.entity', LocalizedEntityManager::class);
    }

    /**
     * Bind the Phase 8.3 Localized Admin layer: the locale resolver, state
     * hydrator, status/fallback resolvers, slug generator, validation rules, the
     * copy/clear operations, and the aggregating manager (`cms.translation.admin`)
     * that the Filament components resolve. All additive singletons over the
     * engine + entity foundation; nothing rebinds an existing contract.
     */
    private function registerAdmin(): void
    {
        $this->app->singleton(LocaleOptionsResolver::class, fn (Application $app) => new LocaleOptionsResolver(
            $app->make('cms.translation.registry'),
            (array) $app['config']->get('translation.admin', []),
        ));
        $this->app->alias(LocaleOptionsResolver::class, 'cms.translation.admin.locales');

        $this->app->singleton(LocalizedStateHydrator::class, fn (Application $app) => new LocalizedStateHydrator(
            $app->make(LocaleOptionsResolver::class),
        ));

        $this->app->singleton(TranslationStatusResolver::class, fn () => new TranslationStatusResolver);

        $this->app->singleton(FallbackPreviewResolver::class, fn (Application $app) => new FallbackPreviewResolver(
            $app->make(TranslationResolverInterface::class),
        ));

        $this->app->singleton(LocalizedSlugGenerator::class, fn (Application $app) => new LocalizedSlugGenerator(
            $app->make('cms.slug'),
        ));

        $this->app->singleton(LocalizedValidationRules::class, fn (Application $app) => new LocalizedValidationRules(
            (array) $app['config']->get('translation.admin', []),
        ));

        $this->app->singleton(CopyFromDefaultLocale::class, fn () => new CopyFromDefaultLocale);
        $this->app->singleton(ClearLocaleValue::class, fn () => new ClearLocaleValue);

        $this->app->singleton('cms.translation.admin', fn (Application $app) => new LocalizedAdminManager(
            $app->make(LocaleOptionsResolver::class),
            $app->make(LocalizedStateHydrator::class),
            $app->make(TranslationStatusResolver::class),
            $app->make(FallbackPreviewResolver::class),
            $app->make(LocalizedSlugGenerator::class),
            $app->make(LocalizedValidationRules::class),
            $app->make(CopyFromDefaultLocale::class),
            $app->make(ClearLocaleValue::class),
        ));
        $this->app->alias('cms.translation.admin', LocalizedAdminManager::class);
    }

    /**
     * Bind the Phase 8.4 Localized Content Contract layer: the registry (where
     * modules declare their localized fields) and the manager (compatibility-aware
     * resolver). Additive singletons; core registers NO content type itself
     * (no module adoption).
     */
    private function registerContent(): void
    {
        $this->app->singleton(LocalizedContentRegistry::class, fn () => new LocalizedContentRegistry);
        $this->app->alias(LocalizedContentRegistry::class, 'cms.translation.content.registry');

        $this->app->singleton('cms.translation.content', fn (Application $app) => new LocalizedContentManager(
            $app->make(LocalizedContentRegistry::class),
            $app->make(LocalizedEntityRepositoryInterface::class),
            $app->make(LocalizedEntityResolverInterface::class),
        ));
        $this->app->alias('cms.translation.content', LocalizedContentManager::class);
    }

    /**
     * Bind the Phase 9.0B Content Translation Driver — the first production
     * relational driver over cms_content_translations. Additive singleton; it is
     * registered into the driver registry (never as default) in boot. Binds no
     * business module.
     */
    private function registerContentDriver(): void
    {
        $this->app->singleton('cms.translation.driver.content', fn (Application $app) => new ContentTranslationDriver(
            $app['config']->get('translation.content_driver.connection'),
        ));
        $this->app->alias('cms.translation.driver.content', ContentTranslationDriver::class);

        // Phase 9.0C — Posts Read Adapter. The module-specific compatibility layer
        // that reads a Post's localized fields through the content driver while
        // reproducing legacy semantics exactly. Dormant until
        // translation.modules.posts.driver leaves 'legacy'.
        $this->app->singleton('cms.translation.posts_adapter', fn (Application $app) => new PostTranslationReadAdapter(
            $app->make('cms.translation.driver.content'),
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.translation.posts_adapter', PostTranslationReadAdapter::class);

        // Phase 9.1C — Pages Read Adapter. The Pages analog of the Posts read
        // adapter: reads a Page's localized fields through the SAME content driver,
        // reproducing legacy semantics exactly. Dormant until
        // translation.modules.pages.read_driver leaves 'legacy'.
        $this->app->singleton('cms.translation.pages_adapter', fn (Application $app) => new PageTranslationReadAdapter(
            $app->make('cms.translation.driver.content'),
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.translation.pages_adapter', PageTranslationReadAdapter::class);

        // Phase 9.0D — Posts Write Adapter. Persists a post's translation row
        // through the content driver from inside ContentManager's transaction.
        // Dormant until translation.modules.posts.write_driver = adapter.
        $this->app->singleton('cms.translation.posts_write_adapter', fn (Application $app) => new PostTranslationWriteAdapter(
            $app->make('cms.translation.driver.content'),
        ));
        $this->app->alias('cms.translation.posts_write_adapter', PostTranslationWriteAdapter::class);

        // Phase 9.1D — Pages Write Adapter. The Pages analog of the Posts write
        // adapter: persists a page's translation row through the SAME content
        // driver from inside ContentManager's transaction. Dormant until
        // translation.modules.pages.write_driver = adapter.
        $this->app->singleton('cms.translation.pages_write_adapter', fn (Application $app) => new PageTranslationWriteAdapter(
            $app->make('cms.translation.driver.content'),
        ));
        $this->app->alias('cms.translation.pages_write_adapter', PageTranslationWriteAdapter::class);
    }

    /**
     * Register the Content Translation Driver into the driver registry with
     * `asDefault: false` (Driver Standard §12.2) — the Null driver stays the
     * engine default and no module resolves through this driver yet. Config-gated
     * and best-effort: a registration failure must never break boot.
     */
    private function seedContentDriver(): void
    {
        if ($this->app['config']->get('translation.content_driver.register', true) !== true) {
            return;
        }

        try {
            /** @var TranslationDriverRegistry $drivers */
            $drivers = $this->app->make('cms.translation.drivers');
            $driver = $this->app->make('cms.translation.driver.content');

            if (! $drivers->has($driver->name())) {
                $drivers->register($driver, asDefault: false);
            }
        } catch (\Throwable) {
            // Best-effort registration; a bad driver must not break boot.
        }
    }

    /**
     * Bind the Phase 9.2C Term Translation Driver — the first Taxonomy Translation
     * Platform driver over cms_term_translations, shared by every taxonomy.
     * Additive singleton; it is registered into the driver registry (never as
     * default) in boot. Binds no taxonomy.
     */
    private function registerTaxonomyDriver(): void
    {
        $this->app->singleton('cms.translation.driver.term', fn (Application $app) => new TermTranslationDriver(
            $app['config']->get('translation.taxonomy_driver.connection'),
        ));
        $this->app->alias('cms.translation.driver.term', TermTranslationDriver::class);

        // Phase 9.2D — Taxonomy (Term) Read Adapter. ONE adapter serves EVERY
        // taxonomy: it reproduces the legacy Term read semantics through the term
        // driver over the shared cms_term_translations store. Dormant until
        // translation.modules.terms.read_driver leaves 'legacy'.
        $this->app->singleton('cms.translation.terms_read_adapter', fn (Application $app) => new TermTranslationReadAdapter(
            $app->make('cms.translation.driver.term'),
            $app->make('cms.language'),
        ));
        $this->app->alias('cms.translation.terms_read_adapter', TermTranslationReadAdapter::class);

        // Phase 9.2E — Taxonomy (Term) Write Adapter. ONE adapter serves EVERY
        // taxonomy: it persists a term's translation row through the term driver
        // from inside TaxonomyManager's transaction, keyed only by term id. Dormant
        // until translation.modules.terms.write_driver = adapter.
        $this->app->singleton('cms.translation.terms_write_adapter', fn (Application $app) => new TaxonomyTranslationWriteAdapter(
            $app->make('cms.translation.driver.term'),
        ));
        $this->app->alias('cms.translation.terms_write_adapter', TaxonomyTranslationWriteAdapter::class);

        // Phase 9.2I — production rollout diagnostics. A single, fault-isolated,
        // read-only monitoring surface aggregating every certified module's adapter
        // diagnostics + revision status. Never breaks runtime.
        $this->app->singleton('cms.translation.rollout_diagnostics', fn (Application $app) => new TranslationRolloutDiagnostics($app));
        $this->app->alias('cms.translation.rollout_diagnostics', TranslationRolloutDiagnostics::class);
    }

    /**
     * Register the Term Translation Driver into the driver registry with
     * `asDefault: false` (Driver Standard §12.2) — the Null driver stays the
     * engine default and no taxonomy resolves through this driver yet. Config-gated
     * and best-effort: a registration failure must never break boot.
     */
    private function seedTaxonomyDriver(): void
    {
        if ($this->app['config']->get('translation.taxonomy_driver.register', true) !== true) {
            return;
        }

        try {
            /** @var TranslationDriverRegistry $drivers */
            $drivers = $this->app->make('cms.translation.drivers');
            $driver = $this->app->make('cms.translation.driver.term');

            if (! $drivers->has($driver->name())) {
                $drivers->register($driver, asDefault: false);
            }
        } catch (\Throwable) {
            // Best-effort registration; a bad driver must not break boot.
        }
    }

    /**
     * Register the storage backend with the engine as a read driver so the
     * resolver can consume stored values. Registered under its backend name and,
     * by default, NOT as the resolver default — existing resolution is unchanged.
     */
    private function seedStorageDriver(): void
    {
        $config = (array) $this->app['config']->get('translation.storage', []);

        if (($config['enabled'] ?? true) !== true || ($config['register_translation_driver'] ?? true) !== true) {
            return;
        }

        /** @var TranslationDriverRegistry $drivers */
        $drivers = $this->app->make('cms.translation.drivers');
        $name = (string) ($config['driver'] ?? 'database');

        if ($drivers->has($name)) {
            return;
        }

        $storage = $this->app->make(LocalizedStorageInterface::class);

        $drivers->register(
            new StorageTranslationDriver($storage, $name),
            asDefault: (bool) ($config['as_default_driver'] ?? false),
        );
    }

    private function seedDrivers(): void
    {
        /** @var TranslationDriverRegistry $drivers */
        $drivers = $this->app->make('cms.translation.drivers');

        if (! $drivers->has('null')) {
            $drivers->register(new NullTranslationDriver, asDefault: true);
        }

        $default = (string) $this->app['config']->get('translation.default_driver', 'null');
        if ($drivers->has($default)) {
            $drivers->setDefault($default);
        }
    }

    private function seedLocales(): void
    {
        /** @var LocaleRegistryInterface $registry */
        $registry = $this->app->make('cms.translation.registry');
        $config = $this->app['config'];

        $default = (string) $config->get('translation.default_locale', 'en');
        $fallback = (string) $config->get('translation.fallback_locale', $default);

        $configured = (array) $config->get('translation.locales', []);
        if ($configured === []) {
            // Seed just the default + fallback so the engine always has locales,
            // without hardcoding any specific locale.
            $configured = [$default => [], $fallback => []];
        }

        foreach ($configured as $code => $meta) {
            $code = (string) $code;
            if ($code === '' || $registry->has($code)) {
                continue;
            }

            $meta = is_array($meta) ? $meta : [];
            $registry->register(new LocaleDefinition(
                $code,
                (string) ($meta['label'] ?? $code),
                (bool) ($meta['enabled'] ?? true),
                isset($meta['native']) ? (string) $meta['native'] : null,
            ));
        }

        if ($registry->has($default)) {
            $registry->setDefault($default);
        }
        if ($registry->has($fallback)) {
            $registry->setFallback($fallback);
        }
    }
}
