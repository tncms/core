<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TN CMS — Translation Engine (Phase 8.0)
|--------------------------------------------------------------------------
|
| Configuration for the reusable localization engine in cms-core. This file
| only configures the engine; it does not translate any UI strings and does
| not wire into existing modules. Values fall back to the framework locale
| config so nothing is hardcoded.
|
*/

return [

    // Master switch. When false, the resolver still returns values but skips
    // caching. The engine remains bindable and additive either way.
    'enabled' => env('CMS_TRANSLATION_ENABLED', true),

    // Default + fallback locales for the locale registry. No locale is
    // hardcoded here — these derive from the app locale config by default.
    'default_locale' => env('CMS_DEFAULT_LOCALE', config('app.locale', 'en')),
    'fallback_locale' => env('CMS_FALLBACK_LOCALE', config('app.fallback_locale', 'en')),

    // Locales to seed into the registry. Leave empty to seed just the
    // default + fallback above. Each entry: 'code' => [label, native, enabled].
    //
    //   'en' => ['label' => 'English', 'native' => 'English', 'enabled' => true],
    //   'vi' => ['label' => 'Vietnamese', 'native' => 'Tiếng Việt', 'enabled' => true],
    'locales' => [],

    // Request-scoped resolution cache.
    'cache' => [
        'enabled' => true,
        'store' => 'runtime', // reserved; a future phase may add 'redis'
    ],

    // Ordered fallback chain applied by the resolver:
    // requested locale → site fallback → default locale → raw value.
    'fallback_chain' => ['requested', 'site_fallback', 'default', 'raw'],

    // Driver seams. Phase 8.0 ships only the always-empty 'null' driver;
    // Database / JSON / YAML / Remote / AI drivers arrive in later phases.
    'default_driver' => env('CMS_TRANSLATION_DRIVER', 'null'),
    'drivers' => [
        'null' => [],
    ],

    // Per-module translation adoption flags (Phase 9.0C onward). Reads and writes
    // are switched INDEPENDENTLY, so adoption is incremental and instantly
    // reversible (a flag flip — no migration, same store). Rollback is `legacy`.
    //
    //   read_driver:  legacy | read | adapter (Phase 9.0C — reads through the adapter;
    //                 any non-legacy value activates it)
    //   write_driver: legacy | adapter        (Phase 9.0D — writes through the adapter,
    //                 inside ContentManager's existing transaction)
    //
    // Phase 9.0H cutover: after the Golden Master certification (Phase 9.0G) proved
    // the adapter path byte-identical to legacy, Posts now default to the Translation
    // Platform (`adapter`). Rollback needs no data migration — set the env vars back
    // to `legacy` (CMS_TL_POSTS_READ_DRIVER / CMS_TL_POSTS_WRITE_DRIVER). The store is
    // unchanged either way. `CMS_TL_POSTS_DRIVER` remains a back-compat alias for the
    // read flag.
    'modules' => [
        'posts' => [
            'read_driver' => env('CMS_TL_POSTS_READ_DRIVER', env('CMS_TL_POSTS_DRIVER', 'adapter')),
            'write_driver' => env('CMS_TL_POSTS_WRITE_DRIVER', 'adapter'),
        ],

        // Pages (Phase 9.1B driver foundation → 9.1C read adapter → 9.1D write
        // adapter). Pages reuse the SAME `content_relational` driver and
        // `cms_content_translations` store as Posts (discriminated only by
        // content.type).
        //
        // Phase 9.2I cutover: after the Translation Platform certification
        // (Phase 9.2H) proved the Pages adapter path byte-identical to legacy,
        // Pages now default to the Translation Platform (`adapter`). Rollback
        // needs no data migration — set the env vars back to `legacy`
        // (CMS_TL_PAGES_READ_DRIVER / CMS_TL_PAGES_WRITE_DRIVER). The store is
        // unchanged either way.
        'pages' => [
            'read_driver' => env('CMS_TL_PAGES_READ_DRIVER', 'adapter'),
            'write_driver' => env('CMS_TL_PAGES_WRITE_DRIVER', 'adapter'),
        ],

        // Taxonomy terms (Phase 9.2C driver → 9.2D read adapter → 9.2E write
        // adapter). Terms use the dedicated `term_relational` driver over the shared
        // `cms_term_translations` store — ONE driver/adapter serves EVERY taxonomy
        // (Category, Tag, Brand, Genre, plugin taxonomies), keyed only by term id.
        // The read adapter (9.2D) reproduces the legacy Term read semantics exactly;
        // the write adapter (9.2E) persists a term's translation row through the term
        // driver from inside TaxonomyManager's transaction — TaxonomyManager remains
        // the sole write authority and SlugManager the sole slug authority.
        //
        // Phase 9.2I cutover: after the Translation Platform certification
        // (Phase 9.2H) proved the taxonomy adapter path byte-identical to legacy,
        // taxonomy terms now default to the Translation Platform (`adapter`). ONE
        // driver/adapter serves EVERY taxonomy (Category, Tag, Brand, Genre, plugin
        // taxonomies), keyed only by term id. Rollback needs no data migration — set
        // the env vars back to `legacy` (CMS_TL_TERMS_READ_DRIVER /
        // CMS_TL_TERMS_WRITE_DRIVER). The store is unchanged either way.
        'terms' => [
            'read_driver' => env('CMS_TL_TERMS_READ_DRIVER', 'adapter'),
            'write_driver' => env('CMS_TL_TERMS_WRITE_DRIVER', 'adapter'),
        ],
    ],

    // Content Translation Driver (Phase 9.0B). The first production relational
    // driver, bridging the engine to the existing cms_content_translations table.
    // It is REGISTERED but NEVER the default: the 'null' driver above stays the
    // engine default, and no business module (Posts/Pages) is migrated to it in
    // this phase. It is engaged only when a future module flag names it.
    'content_driver' => [
        // Register the driver into the driver registry on boot. Registration is
        // harmless (it does not change resolution); flip to false to withhold it.
        'register' => env('CMS_TRANSLATION_CONTENT_DRIVER', true),

        // Public selector name (treated as API — never rename a shipped driver).
        'name' => 'content_relational',

        // Database connection the driver reads/writes on. Null = the app default.
        'connection' => env('CMS_TRANSLATION_CONTENT_CONNECTION', null),
    ],

    // Taxonomy (Term) Translation Driver (Phase 9.2C). The first Taxonomy
    // Translation Platform driver, bridging the engine to the existing
    // cms_term_translations table shared by EVERY taxonomy (Category, Tag, Brand,
    // …). Like the content driver it is REGISTERED but NEVER the default: the
    // 'null' driver stays the engine default and no taxonomy is migrated to it in
    // this phase. It is engaged only when a future taxonomy adoption flag names it.
    'taxonomy_driver' => [
        // Register the driver into the driver registry on boot. Registration is
        // harmless (it does not change resolution); flip to false to withhold it.
        'register' => env('CMS_TRANSLATION_TAXONOMY_DRIVER', true),

        // Public selector name (treated as API — never rename a shipped driver).
        'name' => 'term_relational',

        // Database connection the driver reads/writes on. Null = the app default.
        'connection' => env('CMS_TRANSLATION_TAXONOMY_CONNECTION', null),
    ],

    // Emit lightweight diagnostics through the translation lifecycle hooks.
    'diagnostics' => env('CMS_TRANSLATION_DIAGNOSTICS', false),

    // Localized Admin Components (Phase 8.3). Reusable Filament components for
    // multilingual entity content, built on the entity foundation. No locale is
    // hardcoded — the enabled set comes from the locale registry projection.
    'admin' => [
        // Above this many enabled locales, locale tabs switch to the overflow
        // (non-contained/scrollable) presentation.
        'locale_overflow_threshold' => (int) env('CMS_TRANSLATION_ADMIN_LOCALE_OVERFLOW', 5),

        // Default validation posture: the default locale is required, secondary
        // locales are optional. Overridable per component.
        'default_locale_required' => true,
        'secondary_locale_required' => false,
    ],

    // Localized Storage & Field Infrastructure (Phase 8.1). Reusable persistence
    // for localized field values, consumed by the engine through a storage-backed
    // translation driver. Fully additive: the resolver's default driver is NOT
    // changed unless 'as_default_driver' is enabled, so existing behaviour is
    // untouched. Does not duplicate the locale settings above — validation reads
    // the same locale registry.
    'storage' => [
        // Master switch for the storage layer (bindings always register; this
        // gates the engine-driver registration + writes).
        'enabled' => env('CMS_TRANSLATION_STORAGE', true),

        // Active storage backend name. Phase 8.1 ships only 'database'.
        'driver' => env('CMS_TRANSLATION_STORAGE_DRIVER', 'database'),

        // Register the storage backend with the engine as a read driver so the
        // resolver can consume it (selected per-context by name).
        'register_translation_driver' => true,

        // Keep false to preserve the resolver's configured default driver. Set
        // true only when storage should be the default resolution source.
        'as_default_driver' => false,

        // Flush the request-scoped resolution cache after every write.
        'invalidate_cache_on_write' => true,

        // Opt-in write validation (permissive by default — partial translations
        // are valid). See LocalizedValueValidator.
        'validation' => [
            'strict_locales' => false,     // reject a locale the registry doesn't know
            'reject_empty' => false,       // reject empty-string values
            'require_resolvable' => false, // reject values that would always miss
        ],
    ],

];
