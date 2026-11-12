<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1, Track B: the canonical and hreflang
 * contract. One canonical per page; the path comes from Core's resource URL
 * authority with no query string except a valid in-range archive page; hreflang
 * only for real public translations; a foreign Host is never cached or stored.
 * Each check starts from a clean request-scoped resource context, as every
 * production request does (the test client shares one application).
 */
final class CanonicalMultilingualContractTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: true);
        settings()->set('reading.posts_per_page', 2, 'integer');
    }

    private function category(string $name, bool $english = true): Term
    {
        $term = app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);
        if ($english) {
            $term->translations()->create(['locale' => 'en', 'name' => $name.' EN', 'slug' => Str::slug($name).'-en']);
        }

        return $term;
    }

    /** @return array{status: int, canonical: list<string>, robots: list<string>, alternates: array<string, string>, html: string} */
    private function fresh(): void
    {
        app('cms.localization.current_resource')->clear();
        app('cms.seo')->reset();
    }

    private function seoHead(string $url): array
    {
        $this->fresh();
        $response = $this->get($url);
        $html = (string) $response->getContent();
        preg_match_all('/<link rel="canonical" href="([^"]*)"/', $html, $c);
        preg_match_all('/<meta name="robots" content="([^"]*)"/', $html, $r);
        preg_match_all('/<link rel="alternate" hreflang="([^"]*)" href="([^"]*)"/', $html, $a);

        return ['status' => $response->getStatusCode(), 'canonical' => $c[1], 'robots' => $r[1],
            'alternates' => array_combine($a[1], $a[2]) ?: [], 'html' => $html];
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    public function test_a_paginated_archive_canonicalizes_to_itself_and_only_keeps_the_page(): void
    {
        $cat = $this->category('Luu tru');
        for ($i = 1; $i <= 5; $i++) {
            $this->smPost('Bai '.$i, english: false, extra: ['term_ids' => [$cat->id]]);
        }
        $base = $this->path(term_url($cat, 'vi'));

        $this->assertSame(['http://localhost'.$base], $this->seoHead($base)['canonical']);
        $this->assertSame(['http://localhost'.$base.'?page=2'], $this->seoHead($base.'?page=2')['canonical']);
        $this->assertSame(['http://localhost'.$base.'?page=3'], $this->seoHead($base.'?page=3&utm_source=x&token=secret')['canonical'], 'only the page survives');

        foreach (['?page=1', '?page=0', '?page=-2', '?page=abc', '?page=99', '?page[]=2'] as $q) {
            $this->assertSame(['http://localhost'.$base], $this->seoHead($base.$q)['canonical'], $q);
        }
    }

    public function test_content_canonical_never_carries_a_query_string_and_is_unique(): void
    {
        $post = $this->smPost('Mot bai', english: true);
        $path = $this->path(content_url($post, 'vi'));

        $head = $this->seoHead($path.'?utm_source=x&token=secret&page=2');
        $this->assertSame(['http://localhost'.$path], $head['canonical']);
        $this->assertCount(1, $head['robots']);
        $this->assertSame(1, substr_count($head['html'], 'property="og:url"'));
        $this->assertStringContainsString('content="http://localhost'.$path.'"', $head['html'], 'og:url follows the canonical');
    }

    public function test_localized_routes_canonicalize_to_their_own_locale_url(): void
    {
        $post = $this->smPost('Song ngu', english: true);
        $en = $this->path(content_url($post, 'en'));
        $this->assertStringStartsWith('/en/', $en);

        $this->assertSame(['http://localhost'.$en], $this->seoHead($en)['canonical']);
        $this->assertSame(['http://localhost/en'], $this->seoHead('/en/')['canonical'], 'trailing slash normalized');
    }

    public function test_hreflang_lists_only_real_public_translations(): void
    {
        $both = $this->smPost('Hai ngon ngu', english: true);
        $viOnly = $this->smPost('Mot ngon ngu', english: false);

        $alternates = $this->seoHead($this->path(content_url($both, 'vi')))['alternates'];
        $this->assertSame(['vi', 'en', 'x-default'], array_keys($alternates));
        $this->assertSame(url(content_url($both, 'en')), $alternates['en']);
        $this->assertSame($alternates['vi'], $alternates['x-default']);

        $single = $this->seoHead($this->path(content_url($viOnly, 'vi')));
        $this->assertSame(['vi'], array_keys($single['alternates']), 'no en / x-default for a missing translation');

        // The missing translation is not served under the other locale's prefix.
        $missing = $this->seoHead('/en/'.ltrim($this->path(content_url($viOnly, 'vi')), '/'));
        $this->assertSame(404, $missing['status']);
        $this->assertSame([], $missing['alternates']);
    }

    public function test_drafts_and_private_content_never_appear_as_canonical_or_alternate(): void
    {
        $draft = $this->smPost('Ban nhap', english: true);
        $draftUrls = [url(content_url($draft, 'vi')), url(content_url($draft, 'en'))];
        app('cms.content')->changeStatus($draft, 'draft', null, ContentFixtures::context());
        $public = $this->smPost('Cong khai', english: true);

        foreach ($draftUrls as $url) {
            $head = $this->seoHead($this->path($url));
            $this->assertSame(404, $head['status']);
            $this->assertSame([], $head['alternates']);
            $this->assertStringContainsString('noindex', implode(',', $head['robots']));
        }

        $page = $this->seoHead($this->path(content_url($public, 'vi')))['html'];
        foreach ($draftUrls as $url) {
            $this->assertStringNotContainsString($url, $page);
        }
        $this->fresh();
        foreach ($draftUrls as $url) {
            $this->assertNotContains($url, $this->allLocs());
        }
    }

    public function test_the_home_page_hreflang_points_at_the_locale_roots_not_the_page_slug(): void
    {
        $home = $this->smPost('Trang chu', english: true, type: 'page');
        $this->assertSame('trang-chu', $home->translations()->where('locale', 'vi')->value('slug'));

        $head = $this->seoHead('/');
        $this->assertSame(['http://localhost'], $head['canonical']);
        $this->assertSame(['vi' => 'http://localhost', 'en' => 'http://localhost/en', 'x-default' => 'http://localhost'], $head['alternates']);
        $this->assertStringNotContainsString('trang-chu', implode(' ', $head['alternates']));

        $this->fresh();
        $this->get('/trang-chu')->assertStatus(301)->assertHeader('Location', '/');
    }

    public function test_a_foreign_host_never_reaches_canonical_or_sitemap_urls(): void
    {
        $post = $this->smPost('Khach la', english: false);
        $path = $this->path(content_url($post, 'vi'));

        // CORE-SEO-RELEASE-HARDENING-R1 (H1): a host other than the configured
        // APP_URL host (http://localhost here) gets the configured origin, and
        // its responses stay private and uncacheable.
        $foreign = $this->seoHead('http://evil.example'.$path);
        $this->assertSame(['http://localhost'.$path], $foreign['canonical']);
        $this->assertStringNotContainsString('evil.example', $foreign['html']);
        $this->fresh();
        $this->assertStringContainsString('private', (string) $this->get('http://evil.example'.$path)->headers->get('Cache-Control'));

        $this->fresh();
        app('cms.sitemap')->invalidateCore();
        $xml = $this->get('http://evil.example/sitemaps/core-posts-vi-1.xml');
        $this->assertStringContainsString('no-store', (string) $xml->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('evil.example', $xml->streamedContent());
    }

    public function test_forwarded_host_headers_are_ignored_without_trusted_proxies(): void
    {
        $post = $this->smPost('Chuyen tiep', english: false);
        $path = $this->path(content_url($post, 'vi'));
        $this->fresh();
        $html = (string) $this->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https'])->get($path)->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="http://localhost'.$path.'">', $html);
        $this->assertStringNotContainsString('evil.example', $html);
    }

    public function test_one_robots_and_one_canonical_tag_on_every_core_template(): void
    {
        $cat = $this->category('Mot danh muc');
        $post = $this->smPost('Bai co danh muc', english: true, extra: ['term_ids' => [$cat->id]]);
        $page = $this->smPost('Trang thuong', english: true, type: 'page');

        foreach (['/', '/en', $this->path(content_url($post, 'vi')), $this->path(content_url($page, 'en')), $this->path(term_url($cat, 'vi')), '/search?q=x'] as $url) {
            $head = $this->seoHead($url);
            $this->assertCount(1, $head['canonical'], $url);
            $this->assertCount(1, $head['robots'], $url);
        }
        $this->assertInstanceOf(Content::class, $post);
    }
}
