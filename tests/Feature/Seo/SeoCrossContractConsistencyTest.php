<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-SEO-RELEASE-HARDENING-R1, Track H3: one authority across canonical,
 * robots, hreflang and the sitemap for Core resources (post, page, category,
 * tag, home, paginated archive). Every URL the sitemap lists renders 200 with
 * exactly one canonical equal to that URL, an indexable robots tag and the
 * same alternates; every indexable public resource is listed once; noindex,
 * draft and missing-translation URLs are never listed.
 */
final class SeoCrossContractConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: true);
        settings()->set('reading.posts_per_page', 2, 'integer');
    }

    private function fresh(): void
    {
        app('cms.localization.current_resource')->clear();
        app('cms.seo')->reset();
    }

    private function term(string $type, string $name, bool $english = true, array $extra = []): Term
    {
        $term = app('cms.taxonomy')->createTerm($type, ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)] + $extra);
        if ($english) {
            $term->translations()->create(['locale' => 'en', 'name' => $name.' EN', 'slug' => Str::slug($name).'-en']);
        }

        return $term;
    }

    /** @return array<string, array<string, string>> loc => alternates (hreflang => href) */
    private function sitemapEntries(): array
    {
        $this->fresh();
        app('cms.sitemap')->invalidateCore();
        $entries = [];

        foreach ($this->indexNames() as $name) {
            $this->fresh();
            $doc = new DOMDocument;
            $this->assertTrue($doc->loadXML($this->get('/sitemaps/'.$name.'.xml')->streamedContent(), LIBXML_NONET));
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $xpath->registerNamespace('x', 'http://www.w3.org/1999/xhtml');

            foreach ($xpath->query('//s:url') as $url) {
                $loc = $xpath->query('s:loc', $url)->item(0)->textContent;
                $this->assertArrayNotHasKey($loc, $entries, 'each URL is listed once: '.$loc);
                $alternates = [];
                foreach ($xpath->query('x:link', $url) as $link) {
                    $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
                }
                $entries[$loc] = $alternates;
            }
        }

        return $entries;
    }

    /** @return array{status: int, canonical: list<string>, robots: list<string>, alternates: array<string, string>} */
    private function page(string $url): array
    {
        $this->fresh();
        $response = $this->get($url);
        $html = (string) $response->getContent();
        preg_match_all('/<link rel="canonical" href="([^"]*)"/', $html, $c);
        preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $r);
        preg_match_all('/<link rel="alternate" hreflang="([^"]*)" href="([^"]*)"/', $html, $a);

        return ['status' => $response->getStatusCode(), 'canonical' => $c[1], 'robots' => $r[1],
            'alternates' => array_combine($a[1], $a[2]) ?: []];
    }

    public function test_every_listed_url_is_its_own_indexable_canonical_with_the_same_alternates(): void
    {
        $category = $this->term('category', 'Chuyen muc');
        $tag = $this->term('tag', 'The', english: false);
        $this->smPost('Trang chu', english: true, type: 'page');
        $post = $this->smPost('Bai song ngu', english: true, extra: ['term_ids' => [$category->id, $tag->id]]);
        $viOnly = $this->smPost('Bai mot ngu', english: false, extra: ['term_ids' => [$category->id]]);
        $this->smPost('Bai ba', english: false, extra: ['term_ids' => [$category->id]]);
        $page = $this->smPost('Gioi thieu', english: true, type: 'page');

        // Not indexable or not public: never listed.
        $noindexPost = $this->smPost('Bai an', english: true, extra: ['seo_robots' => 'noindex', 'term_ids' => [$category->id]]);
        $noindexTag = $this->term('tag', 'The an', english: false, extra: ['seo_robots' => 'noindex']);
        $this->smPost('Bai the an', english: false, extra: ['term_ids' => [$noindexTag->id]]);
        $draft = $this->smPost('Ban nhap', english: true);
        $draftUrl = url(content_url($draft, 'vi'));
        app('cms.content')->changeStatus($draft, 'draft', null, ContentFixtures::context());

        $entries = $this->sitemapEntries();

        $expected = ['http://localhost', 'http://localhost/en',
            url(content_url($post, 'vi')), url(content_url($post, 'en')), url(content_url($viOnly, 'vi')),
            url(content_url($page, 'vi')), url(content_url($page, 'en')),
            url(term_url($category, 'vi')), url(term_url($category, 'en')), url(term_url($tag, 'vi'))];
        foreach ($expected as $loc) {
            $this->assertArrayHasKey($loc, $entries, 'listed: '.$loc);
        }
        foreach ([url(content_url($noindexPost, 'vi')), url(content_url($noindexPost, 'en')), url(term_url($noindexTag, 'vi')), $draftUrl, 'http://localhost/trang-chu'] as $loc) {
            $this->assertArrayNotHasKey($loc, $entries, 'never listed: '.$loc);
        }
        $this->assertArrayNotHasKey(url('/en/'.ltrim((string) parse_url(content_url($viOnly, 'vi'), PHP_URL_PATH), '/')), $entries, 'no missing translation');

        foreach ($entries as $loc => $alternates) {
            $head = $this->page($loc);
            $this->assertSame(200, $head['status'], $loc);
            $this->assertSame([$loc], $head['canonical'], 'one canonical equal to the listed URL: '.$loc);
            $this->assertCount(1, $head['robots'], $loc);
            $this->assertStringStartsWith('index', $head['robots'][0], 'a listed URL is indexable: '.$loc);

            if ($alternates !== []) {
                $this->assertEquals($alternates, $head['alternates'], 'HTML hreflang matches the sitemap: '.$loc);
            } else {
                $this->assertSame([], array_diff(array_values($head['alternates']), [$loc]), 'a single-language URL only points at itself: '.$loc);
            }
        }

        // A noindex post does not noindex its category or tag (they stay listed above).
        $this->assertSame(['noindex,follow'], $this->page(content_url($noindexPost, 'vi'))['robots']);
    }

    public function test_a_paginated_archive_page_is_self_canonical_and_only_page_one_is_listed(): void
    {
        $category = $this->term('category', 'Luu tru', english: false);
        for ($i = 1; $i <= 5; $i++) {
            $this->smPost('Bai '.$i, english: false, extra: ['term_ids' => [$category->id]]);
        }
        $base = url(term_url($category, 'vi'));

        $two = $this->page($base.'?page=2');
        $this->assertSame([$base.'?page=2'], $two['canonical']);
        $this->assertStringStartsWith('index', $two['robots'][0]);

        $entries = $this->sitemapEntries();
        $this->assertArrayHasKey($base, $entries);
        $this->assertSame([], array_filter(array_keys($entries), static fn (string $loc): bool => str_contains($loc, '?')), 'no query-string URLs are listed');
    }

    public function test_an_empty_archive_is_not_listed_but_stays_reachable(): void
    {
        $empty = $this->term('category', 'Trong', english: false);
        $this->smPost('Mot bai', english: false);

        $this->assertArrayNotHasKey(url(term_url($empty, 'vi')), $this->sitemapEntries());
        $this->assertSame(200, $this->page(term_url($empty, 'vi'))['status']);
    }
}
