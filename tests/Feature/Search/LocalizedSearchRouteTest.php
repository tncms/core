<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchProvider;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORE-SEARCH-1-H1 — localized Search route integration.
 *
 * The foundation tests proved provider/data locale correctness; this suite
 * proves FRONTEND multilingual correctness. "search" is a Route Segment
 * Dictionary base (vi -> "tim-kiem"), so the canonical localized Search URL is
 * "/vi/tim-kiem" — exactly like "/category" -> "/vi/danh-muc". The reported
 * defect was: "/search" 200 but the Vietnamese Search URL 404 (it 301'd to
 * "/vi/tim-kiem", which had no route). This suite locks the whole chain down:
 * localized URL -> route -> cms.locale context -> SearchQuery locale.
 *
 * "en" is the default (unprefixed) locale; "vi" is active and non-default.
 */
final class LocalizedSearchRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $language->setCurrent('en');
    }

    public function test_default_search_route_returns_200(): void
    {
        $this->get('/search?q=demo&scope=all')->assertOk();
    }

    public function test_canonical_localized_search_route_returns_200(): void
    {
        $this->get('/vi/tim-kiem?q=demo&scope=all')->assertOk();
    }

    public function test_localized_content_scope_returns_200(): void
    {
        $this->get('/vi/tim-kiem?q=demo&scope=content')->assertOk();
    }

    public function test_localized_search_wins_before_the_localized_catch_all(): void
    {
        // A 200 already proves this — the localized catch-all would dispatch
        // resolveSlug('tim-kiem') and 404. The route-name assertion makes the
        // ordering explicit and guards against a future re-order regression.
        $this->get('/vi/tim-kiem?q=demo&scope=all')->assertOk();

        $this->assertSame('cms.search.localized.vi', app('router')->current()?->getName());
    }

    public function test_untranslated_search_prefix_301s_to_the_dictionary_segment(): void
    {
        // The reported failing URL. "/vi/search" is not canonical: the CMS
        // catch-all's cms.localized-base 301s it to the projected segment, and
        // that segment now resolves (previously 404). The user never lands on a
        // 404 and the query string is preserved.
        $response = $this->get('/vi/search?q=demo&scope=all');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringContainsString('/vi/tim-kiem?q=demo&scope=all', (string) $response->headers->get('Location'));

        $this->followingRedirects()->get('/vi/search?q=demo&scope=all')->assertOk();
    }

    public function test_default_locale_prefix_301s_to_the_canonical_unprefixed_url(): void
    {
        // "en" is the default locale: /en/search is a duplicate URL and must
        // 301 to /search with the query string preserved (canonical/SEO policy).
        $response = $this->get('/en/search?q=demo&scope=all');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringContainsString('/search?q=demo&scope=all', (string) $response->headers->get('Location'));
    }

    public function test_localized_route_carries_the_prefixed_locale_into_the_search_query(): void
    {
        // The core chain: localized URL -> cms.locale -> app locale ->
        // SearchController -> SearchQuery locale -> provider. A spy provider
        // records the locale its query actually received.
        $spy = new LocaleSpyProvider;
        app(SearchRegistry::class)->register($spy);

        $this->get('/vi/tim-kiem?q=demo&scope=spy')->assertOk();

        $this->assertSame('vi', $spy->capturedLocale, 'Localized route must resolve the prefixed locale for the SearchQuery.');
    }

    public function test_default_route_carries_the_default_locale_into_the_search_query(): void
    {
        $spy = new LocaleSpyProvider;
        app(SearchRegistry::class)->register($spy);

        $this->get('/search?q=demo&scope=spy')->assertOk();

        $this->assertSame('en', $spy->capturedLocale, 'Default route must not leak a non-default locale into the SearchQuery.');
    }

    public function test_unknown_scope_is_safe_on_the_localized_route(): void
    {
        $this->get('/vi/tim-kiem?q=demo&scope=this-scope-does-not-exist')->assertOk();
    }

    public function test_empty_query_is_safe_on_the_localized_route(): void
    {
        $this->get('/vi/tim-kiem?scope=all')->assertOk();
    }

    public function test_localized_route_preserves_search_throttle_and_security_parity(): void
    {
        $router = app('router');

        $default = $router->getRoutes()->getByName('cms.search');
        $localized = $router->getRoutes()->getByName('cms.search.localized.vi');

        $this->assertNotNull($default);
        $this->assertNotNull($localized);

        // Same throttle as the default surface (no weaker localized endpoint).
        $this->assertContains('throttle:30,1', $default->gatherMiddleware());
        $this->assertContains('throttle:30,1', $localized->gatherMiddleware());

        // Localized surface establishes locale + canonical policy via the ONE
        // Core authority, and is GET-only like the default.
        $localizedMiddleware = $localized->gatherMiddleware();
        $this->assertContains('cms.locale', $localizedMiddleware);
        $this->assertContains('cms.canonical-locale', $localizedMiddleware);
        $this->assertSame(['GET', 'HEAD'], $localized->methods());
    }

    public function test_all_scope_aggregates_under_one_effective_locale_on_the_localized_route(): void
    {
        // Strategy B aggregation: the localized Search runs every active provider
        // with ONE consistent locale (the prefixed one).
        $spy = new LocaleSpyProvider;
        app(SearchRegistry::class)->register($spy);

        $this->get('/vi/tim-kiem?q=demo&scope='.SearchScope::ALL)->assertOk();

        $this->assertSame('vi', $spy->capturedLocale);
    }
}

/**
 * Records the locale of the SearchQuery it is asked to run. Returns no hits, so
 * its entity definition is never hydrated — it is a pure locale-context probe.
 */
final class LocaleSpyProvider implements SearchProvider
{
    public ?string $capturedLocale = null;

    public function key(): string
    {
        return 'locale-spy';
    }

    public function definitions(): array
    {
        return [new LocaleSpyDefinition];
    }

    public function search(SearchQuery $query): array
    {
        $this->capturedLocale = $query->locale;

        return [];
    }
}

final class LocaleSpyDefinition implements SearchableEntityDefinition
{
    public function type(): string
    {
        return 'spy';
    }

    public function label(): string
    {
        return 'Spy';
    }

    public function modelClass(): string
    {
        return \stdClass::class;
    }

    public function searchableFields(): array
    {
        return ['title'];
    }

    public function supportsLocales(): bool
    {
        return true;
    }

    public function visibilityRules(): array
    {
        return ['published'];
    }

    public function resolveTitle(object $entity, string $locale): string
    {
        return '';
    }

    public function resolveUrl(object $entity, string $locale): ?string
    {
        return null;
    }
}
