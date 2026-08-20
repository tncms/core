<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\SearchController;
use App\Search\Cache\NullSearchCache;
use App\Search\Contracts\SearchCacheInterface;
use App\Search\Contracts\SearchDriverInterface;
use App\Search\Contracts\SearchManagerInterface;
use App\Search\Contracts\SearchRankerInterface;
use App\Search\Drivers\ProviderSearchDriver;
use App\Search\Providers\ContentSearchProvider;
use App\Search\Ranking\RelevanceSearchRanker;
use App\Search\SearchManager;
use App\Search\SearchRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Services\LanguageManager;
use Throwable;

/**
 * Wires the shared search platform into the container (Phase 3.1.6N-B).
 *
 * The registry is the single, host-owned catalog of searchable entities — no
 * plugin owns global search. Plugins register their own {@see \App\Search\Contracts\SearchProvider}
 * against this singleton from their service-provider `boot()`:
 *
 *   app(\App\Search\SearchRegistry::class)->register(new MySearchProvider());
 *
 * Registration binds the singleton only — zero database access — so it is safe on
 * the pre-install hot path.
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchRegistry::class);
        $this->app->alias(SearchRegistry::class, 'cms.search');

        // Execution layer (Phase 3.1.6N-C). Default driver fans out to registered
        // providers; ranker/cache are swappable seams for future drivers.
        $this->app->singleton(SearchDriverInterface::class, ProviderSearchDriver::class);
        $this->app->singleton(SearchRankerInterface::class, RelevanceSearchRanker::class);
        $this->app->singleton(SearchCacheInterface::class, NullSearchCache::class);
        $this->app->singleton(SearchManagerInterface::class, SearchManager::class);
        $this->app->alias(SearchManagerInterface::class, 'cms.search.manager');
    }

    /**
     * Register the public search route (Phase 3.1.6N-D). This provider boots
     * before CmsServiceProvider, so "/search" is registered ahead of the CMS
     * frontend catch-all ("/{slug}") and always wins. GET only, no auth, session
     * via the "web" group, and throttled to protect the public endpoint.
     */
    public function boot(): void
    {
        $this->registerCoreContentProvider();

        Route::middleware(['web', 'throttle:30,1'])
            ->get('/search', [SearchController::class, 'index'])
            ->name('cms.search');

        $this->registerLocalizedSearchRoutes();
    }

    /**
     * CORE-SEARCH-1-H1 — localized search routes GENERATED from the ONE Route
     * Segment Dictionary, exactly like the CMS frontend localized-base routes
     * (cms-core routes/frontend.php). "search" is a dictionary base (e.g.
     * vi → "tim-kiem"), so its canonical localized URL is /{locale}/{segment} —
     * never /{locale}/search — keeping search consistent with /category → /danh-muc.
     *
     * For each active locale whose "search" base has a localized projection, one
     * route is registered at that segment. Nothing is hardcoded: the active locales
     * come from {@see LanguageManager::routeLocalePattern()} and the segments from
     * the dictionary; the default locale (and any locale without a projection) is
     * served by "/search".
     *
     * Middleware reuses the single Core locale-routing authority: "cms.locale"
     * (ApplyPublicLocale) establishes the request locale from the {locale} segment
     * so the transport-only controller's app()->getLocale() is already correct, and
     * "cms.canonical-locale" keeps the default-locale prefix canonical. These routes
     * boot before CmsServiceProvider's localized catch-all, so /{locale}/{segment}
     * wins; that catch-all's own "cms.localized-base" already 301s /{locale}/search
     * to the projected segment, so both URLs reach search with no duplicate route.
     */
    private function registerLocalizedSearchRoutes(): void
    {
        $dictionary = app('cms.localization.dictionary');

        foreach (explode('|', LanguageManager::routeLocalePattern()) as $locale) {
            $segment = $dictionary->projectBase('search', $locale);

            if ($segment === 'search') {
                continue; // Default locale / no projection — served by "/search".
            }

            Route::middleware(['web', 'throttle:30,1', 'cms.locale', 'cms.canonical-locale'])
                ->get('{locale}/'.$segment, [SearchController::class, 'index'])
                ->whereIn('locale', [$locale])
                ->name('cms.search.localized.'.$locale);
        }
    }

    /**
     * Register the Core Post/Page provider (CORE-SEARCH-1, GAP 1). This is the
     * platform default — always present so a plain CMS install can search its own
     * content with no optional plugin. Registration binds objects only (no DB), and
     * is idempotent + best-effort: a registration hiccup never takes down boot.
     */
    private function registerCoreContentProvider(): void
    {
        try {
            $this->app->make(SearchRegistry::class)
                ->register($this->app->make(ContentSearchProvider::class));
        } catch (Throwable) {
            // Non-fatal: the platform still boots without the content scope.
        }
    }
}
