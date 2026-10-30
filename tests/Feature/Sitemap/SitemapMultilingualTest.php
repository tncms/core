<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-R1 Gate F: locale-correct URLs from Core's locale authority,
 * valid hreflang, no links to missing translations, no cross-locale
 * duplicates, and no phantom sets when a new locale is activated before
 * plugins translate it.
 */
final class SitemapMultilingualTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    public function test_default_locale_is_unprefixed_and_others_are_prefixed(): void
    {
        $post = $this->smPost('Bilingual');

        $this->assertSame([url('/')], $this->chunkLocs('core-home-vi-1'));
        $this->assertSame(['http://localhost/en'], $this->chunkLocs('core-home-en-1'));
        $this->assertSame([url(content_url($post, 'vi'))], $this->chunkLocs('core-posts-vi-1'));
        $this->assertStringNotContainsString('/vi/', $this->chunkLocs('core-posts-vi-1')[0]);
        $this->assertStringStartsWith('http://localhost/en/', $this->chunkLocs('core-posts-en-1')[0]);
    }

    public function test_untranslated_content_is_absent_from_other_locales_and_has_no_alternates(): void
    {
        $viOnly = $this->smPost('Only Vietnamese', english: false);
        $both = $this->smPost('Both');

        $vi = $this->get('/sitemaps/core-posts-vi-1.xml')->streamedContent();
        $this->assertSame([url(content_url($viOnly, 'vi')), url(content_url($both, 'vi'))], $this->xmlLocs($vi, 'urlset'));
        $this->assertSame([url(content_url($both, 'en'))], $this->chunkLocs('core-posts-en-1'));

        // The vi-only entry carries no alternates; the bilingual one carries vi, en and x-default.
        $doc = new \DOMDocument;
        $doc->loadXML($vi);
        $urls = $doc->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'url');
        $this->assertSame(0, $urls->item(0)->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link')->length);
        $this->assertSame(3, $urls->item(1)->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link')->length);
    }

    public function test_hreflang_matches_the_on_page_alternates(): void
    {
        $post = $this->smPost('Parity');
        $html = $this->get(parse_url(url(content_url($post, 'vi')), PHP_URL_PATH))->assertOk()->getContent();
        $xml = $this->get('/sitemaps/core-posts-vi-1.xml')->streamedContent();

        preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', $html, $page, PREG_SET_ORDER);
        preg_match_all('/hreflang="([^"]+)" href="([^"]+)"/', $xml, $map, PREG_SET_ORDER);
        $norm = static fn (array $set) => collect($set)->map(static fn ($m) => $m[1].'='.html_entity_decode($m[2]))->sort()->values()->all();

        $this->assertNotEmpty($page);
        $this->assertSame($norm($page), $norm($map));
    }

    public function test_no_url_appears_in_two_locales(): void
    {
        $this->smPost('One');
        $this->smPost('Two');
        app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'Tin', 'slug' => 'tin']);

        $locs = $this->allLocs();
        $this->assertSame(count($locs), count(array_unique($locs)));
    }

    public function test_prefix_default_setting_prefixes_the_default_locale(): void
    {
        settings()->set('language.prefix_default', true, 'boolean');
        $post = $this->smPost('Prefixed');

        $this->assertSame(['http://localhost/vi'], $this->chunkLocs('core-home-vi-1'));
        $this->assertStringStartsWith('http://localhost/vi/', $this->chunkLocs('core-posts-vi-1')[0]);
        $this->assertSame(url(content_url($post, 'vi')), $this->chunkLocs('core-posts-vi-1')[0]);
    }

    public function test_activating_a_new_locale_adds_no_phantom_sets(): void
    {
        $this->smPost('Existing');
        $this->smPlugin('sm-lib');
        $this->smItems('sm-lib', ['books' => [1 => 'one']]);
        $this->smRoutes('sm-lib');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $before = $this->indexNames();

        app('cms.language')->create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => true, 'sort_order' => 3]);
        app()->forgetInstance('cms.language');
        // The plugin's routes were not rebuilt for fr: like a route source refused for a missing locale.
        $names = $this->indexNames();

        $this->assertContains('core-home-fr-1', $names, 'the localized home exists in every public locale');
        $this->assertNotContains('core-posts-fr-1', $names, 'no fr translations, no fr set');
        $this->assertNotContains('sm-lib-books-fr-1', $names, 'plugin has no fr route, no fr set');
        $this->assertSame([], array_values(array_diff($before, $names)), 'existing sets unchanged');
        $this->get('/sitemaps/sm-lib-books-fr-1.xml')->assertNotFound();
        $this->assertSame(['http://localhost/lib-sm-lib/books/one'], $this->chunkLocs('sm-lib-books-vi-1'));
    }

    public function test_a_plugin_whose_routes_were_refused_contributes_nothing(): void
    {
        $this->smPlugin('sm-lib');
        $this->smItems('sm-lib', ['books' => [1 => 'one']]);
        $this->smRoutes(); // R2 refusal: the plugin serves no routes at all.
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $names = $this->indexNames();
        $this->assertEmpty(array_filter($names, static fn ($n) => str_starts_with($n, 'sm-lib-')));
        $this->assertContains('core-home-vi-1', $names);
    }
}
