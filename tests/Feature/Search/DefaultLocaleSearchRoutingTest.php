<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CORE-PLATFORM-SEAMS-R2 Gate A — search routing when the DEFAULT locale has a
 * dictionary projection for the search base (vi default: "search" → "tim-kiem").
 *
 * Core contract (PlatformRouteDictionary, content/term/commerce resolvers): the
 * default locale renders the canonical route key unprefixed (/search); every
 * other locale renders its projection under its prefix (/en/search when en has
 * no projection). Any other spelling 301s, in ONE hop, to the canonical URL.
 */
final class DefaultLocaleSearchRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $language->setCurrent('vi');

        // Route registration read the language table at boot (before these rows
        // existed); rebuild the search + frontend routes the way a real boot would.
        $this->rebootRoutes();

        app('cms.content')->create(['type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'Kết quả xổ số', 'slug' => 'ket-qua-xo-so', 'content' => '<p>bài viết tiếng Việt</p>'], \Tests\Support\ContentFixtures::context());
        app('cms.content')->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Lottery results', 'slug' => 'lottery-results', 'content' => '<p>english post</p>'], \Tests\Support\ContentFixtures::context());
    }

    private function rebootRoutes(): void
    {
        $router = app('router');
        $router->setRoutes(new \Illuminate\Routing\RouteCollection);

        foreach ([\TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry::class, \TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }

        (new \App\Providers\SearchServiceProvider(app()))->boot();
        app('cms.localized_routes')->finalize();
        require base_path('packages/thenguyen/cms-core/routes/frontend.php');
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    public function test_default_locale_canonical_search_resolves(): void
    {
        $this->get('/search?q='.rawurlencode('xổ'))->assertOk()->assertSee('Kết quả xổ số');
    }

    public function test_default_prefixed_localized_search_redirects_once_to_the_canonical_url(): void
    {
        $response = $this->get('/vi/tim-kiem?q=xo%20s%E1%BB%91&page=2');

        $response->assertStatus(301);
        $location = (string) $response->headers->get('Location');
        $this->assertSame('/search', parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['page' => '2', 'q' => 'xo số'], $query, 'query preserved (normalised order, as cms.canonical-locale always did)');
        $this->get($response->headers->get('Location'))->assertOk();
    }

    public function test_unprefixed_default_projection_redirects_once_to_the_canonical_url(): void
    {
        $response = $this->get('/tim-kiem?q=xo');

        $response->assertStatus(301);
        $this->assertSame('/search?q=xo', $response->headers->get('Location'));
        $this->get('/search?q=xo')->assertOk();
    }

    public function test_default_prefixed_canonical_key_redirects_to_unprefixed(): void
    {
        $response = $this->get('/vi/search?q=xo');

        $response->assertStatus(301);
        $this->assertSame('/search?q=xo', $response->headers->get('Location'));
    }

    public function test_non_default_locale_without_projection_has_a_search_route(): void
    {
        $this->assertNotNull(Route::getRoutes()->getByName('cms.search.localized.en'));
        $this->assertSame(url('/en/search'), route('cms.search.localized.en', ['locale' => 'en']));

        $response = $this->get('/en/search?q=lottery');

        $response->assertOk()->assertSee('Lottery results')->assertDontSee('Kết quả xổ số');
        $this->assertSame('en', app()->getLocale());
    }

    public function test_search_url_helper_generates_inbound_matching_urls_per_locale(): void
    {
        $this->assertSame(url('/search'), cms_search_url('vi'));
        $this->assertSame(url('/en/search'), cms_search_url('en'));

        foreach (['vi', 'en'] as $locale) {
            $path = parse_url(cms_search_url($locale), PHP_URL_PATH);
            $this->get($path.'?q=x')->assertOk();
        }
    }

    public function test_search_form_on_a_non_default_locale_targets_that_locale(): void
    {
        $html = $this->get('/en/search?q=lottery')->getContent();

        $this->assertStringContainsString('action="'.url('/en/search').'"', $html);
    }

    public function test_canonical_metadata_and_no_duplicate_canonical(): void
    {
        $html = $this->get('/search?q=xo&page=1')->getContent();
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContainsString('rel="canonical" href="'.url('/search').'"', $html);
        $this->assertStringContainsString('noindex', $html);

        $en = $this->get('/en/search?q=lottery')->getContent();
        $this->assertStringContainsString('rel="canonical" href="'.url('/en/search').'"', $en);
    }

    public function test_empty_and_unicode_queries(): void
    {
        $this->get('/search')->assertOk();
        $this->get('/search?q=')->assertOk();
        $this->get('/search?q='.rawurlencode('xổ số'))->assertOk()->assertSee('Kết quả xổ số');
        $this->get('/en/search?q=')->assertOk();
    }

    public function test_locale_switcher_targets_on_search_pages(): void
    {
        $switch = function (string $html, string $locale): ?string {
            preg_match('#href="([^"]+)"\s*hreflang="'.$locale.'"#', $html, $m);

            return $m[1] ?? null;
        };

        $vi = $this->get('/search?q=xo')->getContent();
        $this->assertSame('/en/search?q=xo', $switch($vi, 'en'));
        $this->assertSame('/search?q=xo', $switch($vi, 'vi'));
        $this->assertStringNotContainsString('tim-kiem', $vi);

        $en = $this->get('/en/search?q=lottery')->getContent();
        $this->assertSame('/search?q=lottery', $switch($en, 'vi'), 'default target is the canonical key, never the projection');
        $this->get('/en/search?q=lottery')->assertOk();
        $this->assertStringNotContainsString('rel="alternate" hreflang', $vi, 'search pages are unavailable targets: no hreflang alternates');
    }

    public function test_no_redirect_loop_and_existing_page_slug_wins_over_the_alias(): void
    {
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'vi', 'title' => 'Tìm kiếm', 'slug' => 'tim-kiem', 'content' => '<p>a real page</p>'], \Tests\Support\ContentFixtures::context());

        $this->get('/tim-kiem')->assertOk()->assertSee('a real page');
        $this->get('/search')->assertOk();
    }
}
