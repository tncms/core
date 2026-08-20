<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\FrontendController;
use TheNguyen\CMS\Http\Middleware\CheckMaintenanceMode;
use TheNguyen\CMS\Http\Middleware\QueryInsights;
use TheNguyen\CMS\Services\LanguageManager;

/*
|--------------------------------------------------------------------------
| CMS Frontend Routes
|--------------------------------------------------------------------------
| Public website rendered with the active theme.
|
| Three layers, registered in this order:
|   1. Default (unprefixed) routes — the default language always works here.
|      The post/category/tag base routes are registered ONLY when their base is
|      set; an empty base means the record resolves through the generic /{slug}
|      route instead (base-less / WordPress-style permalinks).
|   2. Optional locale-prefixed routes — {locale} is constrained to ACTIVE
|      language codes only (vi|en|...), resolved at registration time.
|   3. The generic catch-all resolver — MUST stay LAST and keeps its
|      reserved-prefix constraint so it never swallows admin/cms-health/etc.
|      It dispatches via cms_slugs to a page, post, or term archive.
*/

// Active language codes, e.g. ['vi', 'en']. Fully guarded so route
// registration is safe before the table exists / during migrations.
$activeLocales = explode('|', LanguageManager::routeLocalePattern());

// Configurable permalink bases. A base may be empty (base-less); the dedicated
// /{base}/{slug} route is registered only when the base is set. Fully guarded:
// settings() returns defaults before the table exists. Changing a base requires
// a route cache clear (php artisan route:clear) to take effect.
$permalink = app('cms.permalink');
$postBase = $permalink->postBase();
$categoryBase = $permalink->categoryBase();
$tagBase = $permalink->tagBase();

// P5H.1B — the ONE Route Segment Dictionary, used to GENERATE localized-base routes below and
// to 301 canonical bases to their localized projection (via the cms.localized-base middleware).
$dictionary = app('cms.localization.dictionary');

// Reserved single-segment prefixes the catch-all must never capture.
$pageSlugConstraint = '^(?!(admin|install|cms-health|livewire|filament|storage|uploads|themes|vendor|up|robots\.txt|sitemap\.xml)$).+$';

// The maintenance gate is applied to the frontend group ONLY (v1.0.0-beta.4),
// so admin/Livewire/cms-health/robots.txt/sitemap.xml — registered outside this
// group — are never blocked. The middleware no-ops when maintenance is disabled.
Route::middleware(['web', CheckMaintenanceMode::class, QueryInsights::class])->group(function () use ($activeLocales, $pageSlugConstraint, $postBase, $categoryBase, $tagBase, $dictionary): void {
    // 1. Default (unprefixed) routes. Base routes only when the base is set.
    Route::get('/', [FrontendController::class, 'home'])->name('cms.home');

    if ($postBase !== '') {
        Route::get($postBase.'/{slug}', [FrontendController::class, 'post'])->name('cms.post');
    }

    if ($categoryBase !== '') {
        Route::get($categoryBase.'/{slug}', [FrontendController::class, 'category'])->name('cms.category');
    }

    if ($tagBase !== '') {
        Route::get($tagBase.'/{slug}', [FrontendController::class, 'tag'])->name('cms.tag');
    }

    // P5H.1B — dictionary-GENERATED localized-base routes (reverse matching). Registered BEFORE
    // the localized catch-all below so /{locale}/{localized-base}/{slug} wins over /{locale}/{slug}.
    // {locale} stays a parameter (constrained per locale) so the controller resolves the locale;
    // the base is the dictionary projection (e.g. 'category' → 'danh-muc', 'post' → 'bai-viet').
    foreach ([
        [$postBase, [FrontendController::class, 'post'], 'cms.post'],
        [$categoryBase, [FrontendController::class, 'category'], 'cms.category'],
        [$tagBase, [FrontendController::class, 'tag'], 'cms.tag'],
    ] as [$dictBase, $dictAction, $dictName]) {
        if ($dictBase === '') {
            continue;
        }

        foreach ($activeLocales as $dictLocale) {
            $dictSegment = $dictionary->projectBase($dictBase, $dictLocale);

            if ($dictSegment === $dictBase) {
                continue; // default locale or no localized projection — nothing to generate.
            }

            Route::middleware('cms.canonical-locale')
                ->get('{locale}/'.$dictSegment.'/{slug}', $dictAction)
                ->whereIn('locale', [$dictLocale])
                ->name($dictName.'.localized.dict.'.$dictLocale);
        }
    }

    // 2. Locale-prefixed routes (active codes only). The canonical-locale
    //    middleware 301-redirects a duplicate default-locale prefix
    //    (e.g. /vi/blog/x → /blog/x) and no-ops for non-default locales.
    //    cms.localized-base 301s a canonical base to its dictionary segment
    //    (/vi/category/x → /vi/danh-muc/x).
    Route::prefix('{locale}')
        ->whereIn('locale', $activeLocales)
        ->middleware(['cms.canonical-locale', 'cms.localized-base'])
        ->group(function () use ($postBase, $categoryBase, $tagBase): void {
            Route::get('/', [FrontendController::class, 'home'])->name('cms.home.localized');

            if ($postBase !== '') {
                Route::get($postBase.'/{slug}', [FrontendController::class, 'post'])->name('cms.post.localized');
            }

            if ($categoryBase !== '') {
                Route::get($categoryBase.'/{slug}', [FrontendController::class, 'category'])->name('cms.category.localized');
            }

            if ($tagBase !== '') {
                Route::get($tagBase.'/{slug}', [FrontendController::class, 'tag'])->name('cms.tag.localized');
            }

            // Generic localized resolver (pages + base-less posts/terms).
            Route::get('/{slug}', [FrontendController::class, 'resolveSlug'])
                ->where('slug', '.+')
                ->name('cms.resolve.localized');
        });

    // 3. Generic catch-all resolver — MUST be registered last. Resolves via
    //    cms_slugs to a page, base-less post, or base-less term archive.
    Route::get('/{slug}', [FrontendController::class, 'resolveSlug'])
        ->where('slug', $pageSlugConstraint)
        ->name('cms.resolve');
});
