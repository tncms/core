<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-R1 Gates B, C and H: /sitemap.xml is a standards-compliant
 * index of chunked children; chunks partition the key space exactly; queries
 * per child are bounded; manifests are cached and invalidated by version.
 */
final class SitemapIndexContractTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    public function test_index_is_a_sitemapindex_of_absolute_unique_child_urls(): void
    {
        $this->smPost('Alpha');
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $locs = $this->xmlLocs($response->getContent(), 'sitemapindex');
        $this->assertSame($locs, array_values(array_unique($locs)), 'no duplicate references');

        foreach ($locs as $loc) {
            $this->assertMatchesRegularExpression('#^http://localhost/sitemaps/[a-z0-9_-]+\.xml$#', $loc);
        }

        $this->assertSame(
            ['core-home-vi-1', 'core-home-en-1', 'core-posts-vi-1', 'core-posts-en-1'],
            $this->indexNames(),
            'deterministic order; no phantom pages/categories/tags sets',
        );
    }

    public function test_child_is_a_urlset_with_canonical_locations_lastmod_and_alternates(): void
    {
        $post = $this->smPost('Alpha');
        $response = $this->get('/sitemaps/core-posts-vi-1.xml')->assertOk();
        $xml = $response->streamedContent();

        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $this->assertSame([url(content_url($post, 'vi'))], $this->xmlLocs($xml, 'urlset'));
        $this->assertStringContainsString('<lastmod>'.$post->updated_at->format(DATE_ATOM).'</lastmod>', $xml);
        $this->assertStringContainsString('hreflang="vi" href="'.url(content_url($post, 'vi')).'"', $xml);
        $this->assertStringContainsString('hreflang="en" href="'.url(content_url($post, 'en')).'"', $xml);
        $this->assertStringContainsString('hreflang="x-default" href="'.url(content_url($post, 'vi')).'"', $xml);
        $this->assertSame([url(content_url($post, 'en'))], $this->chunkLocs('core-posts-en-1'));
        $this->assertStringStartsWith('http://localhost/en/', url(content_url($post, 'en')));
    }

    public function test_unknown_or_out_of_range_children_are_404(): void
    {
        $this->smPost('Alpha');

        foreach (['core-posts-vi-2', 'core-pages-vi-1', 'core-posts-fr-1', 'nope', 'core-posts-vi-0'] as $name) {
            $response = $this->get('/sitemaps/'.$name.'.xml')->assertNotFound();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        $this->get('/sitemaps/CORE-POSTS-VI-1.xml')->assertNotFound();
        $this->get('/sitemaps/core-posts-vi-1.txt')->assertNotFound();
    }

    public function test_site_level_noindex_disables_index_and_children(): void
    {
        $this->smPost('Alpha');
        settings()->set('seo.noindex_site', true, 'boolean');
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/sitemaps/core-posts-vi-1.xml')->assertNotFound();

        settings()->set('seo.noindex_site', false, 'boolean');
        settings()->set('seo.robots_default', 'noindex,follow');
        $this->get('/sitemap.xml')->assertNotFound();

        settings()->set('seo.robots_default', 'index,follow');
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_chunks_partition_the_set_exactly_with_stable_names(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $ids = $this->smBulkPosts(1100);

        $names = array_values(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-posts-')));
        $this->assertSame(['core-posts-vi-1', 'core-posts-vi-2', 'core-posts-vi-3'], $names);

        $seen = [];
        $sizes = [];
        foreach ($names as $name) {
            $locs = $this->chunkLocs($name);
            $sizes[] = count($locs);
            array_push($seen, ...$locs);
        }

        $this->assertSame([500, 500, 100], $sizes);
        $expected = array_map(fn (int $id) => 'http://localhost/blog/bulk-'.$id, $ids);
        $this->assertSame($expected, $seen, 'every URL exactly once, in key order');
        $this->assertSame($this->smBulkLoc($ids[0]), $seen[0], 'same URL as the permalink authority');
    }

    public function test_insertions_and_deletions_rebuild_boundaries_without_gaps(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $ids = $this->smBulkPosts(1000);
        $this->assertCount(2, array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-posts-')));

        $more = $this->smBulkPosts(10);
        $this->assertCount(3, array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-posts-')));
        $all = [...$ids, ...$more];
        $this->assertSame(array_map(static fn (int $id) => 'http://localhost/blog/bulk-'.$id, $all), $this->postLocs());

        // Model-event writes: a delete and an unpublish rebuild the boundaries.
        app('cms.content')->delete(\TheNguyen\CMS\Models\Content::query()->findOrFail($ids[10]), null, \Tests\Support\ContentFixtures::context());
        app('cms.content')->changeStatus(\TheNguyen\CMS\Models\Content::query()->findOrFail($ids[700]), 'draft', null, \Tests\Support\ContentFixtures::context());
        $expected = array_values(array_diff($all, [$ids[10], $ids[700]]));
        $this->assertSame(array_map(static fn (int $id) => 'http://localhost/blog/bulk-'.$id, $expected), $this->postLocs());
    }

    public function test_the_open_last_chunk_absorbs_inserts_before_a_rebuild(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $this->smBulkPosts(500);
        $this->assertSame(['core-home-vi-1', 'core-home-en-1', 'core-posts-vi-1'], $this->indexNames());

        // A write that bypasses model events (e.g. a raw import): no version bump.
        $late = \TheNguyen\CMS\Models\Content::withoutEvents(fn () => $this->smPost('Late', english: false));
        $this->assertSame(['core-home-vi-1', 'core-home-en-1', 'core-posts-vi-1'], $this->indexNames(), 'manifest not rebuilt');
        $this->assertContains(url(content_url($late, 'vi')), $this->chunkLocs('core-posts-vi-1'));
    }

    public function test_unpublished_content_disappears_from_a_child_even_with_a_stale_manifest(): void
    {
        $post = $this->smPost('Alpha', english: false);
        $this->smPost('Beta', english: false);
        $this->indexNames();

        // Hide without any model event: the manifest is stale, the child is live.
        DB::table('cms_contents')->where('id', $post->id)->update(['status' => 'draft']);

        $this->assertNotContains(url(content_url($post, 'vi')), $this->chunkLocs('core-posts-vi-1'));
    }

    public function test_a_child_runs_a_bounded_number_of_queries_independent_of_size(): void
    {
        config(['cms.sitemap.chunk_size' => 50]);
        for ($i = 1; $i <= 5; $i++) {
            $this->smPost('Small '.$i);
        }
        $this->indexNames();
        $small = $this->countQueries(fn () => $this->chunkLocs('core-posts-vi-1'));

        for ($i = 1; $i <= 40; $i++) {
            $this->smPost('Large '.$i);
        }
        $this->indexNames();
        $large = $this->countQueries(fn () => $this->chunkLocs('core-posts-vi-1'));

        $this->assertSame($small, $large, 'queries do not grow with entries');
        $this->assertLessThanOrEqual(16, $large);
    }

    public function test_manifest_is_cached_and_invalidated_by_content_writes(): void
    {
        $this->smPost('Alpha');
        $this->indexNames();

        $cached = $this->countQueries(fn () => $this->get('/sitemap.xml')->assertOk());
        $this->smPost('Beta');
        $rebuilt = $this->countQueries(fn () => $this->get('/sitemap.xml')->assertOk());

        $this->assertGreaterThan($cached, $rebuilt, 'a content write forces one rebuild');
        $this->assertSame($cached, $this->countQueries(fn () => $this->get('/sitemap.xml')->assertOk()), 'then cached again');
    }

    public function test_a_concurrent_rebuild_serves_the_last_good_manifest(): void
    {
        $this->smPost('Alpha');
        $before = $this->indexNames();

        $this->smPost('Beta', english: false, extra: [], type: 'page');
        $lock = Cache::store()->lock('cms.sitemap.build.core', 120);
        $this->assertTrue($lock->get(), 'simulate another request rebuilding');

        try {
            $this->assertSame($before, $this->indexNames(), 'stale manifest served while locked');
        } finally {
            $lock->release();
        }

        $this->assertContains('core-pages-vi-1', $this->indexNames());
    }

    public function test_host_header_does_not_mint_manifest_cache_entries(): void
    {
        $this->smPost('Alpha');
        $this->indexNames();

        $queries = $this->countQueries(fn () => $this->get('http://attacker.example/sitemap.xml')->assertOk());
        $this->assertSame($this->countQueries(fn () => $this->get('/sitemap.xml')->assertOk()), $queries);
    }

    public function test_legacy_seo_sitemap_api_is_a_bounded_projection_of_the_core_provider(): void
    {
        $post = $this->smPost('Alpha');
        $draft = $this->smPost('Draft');
        app('cms.content')->changeStatus($draft, 'draft', null, \Tests\Support\ContentFixtures::context());

        $locs = array_column(app('cms.seo')->sitemap(), 'loc');
        $this->assertContains(url('/'), $locs);
        $this->assertContains(url(content_url($post, 'vi')), $locs);
        $this->assertContains(url(content_url($post, 'en')), $locs);
        $this->assertNotContains(url(content_url($draft, 'vi')), $locs);
        $this->assertStringContainsString('<urlset', app('cms.seo')->sitemapXml());
    }

    public function test_robots_txt_still_advertises_the_index(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: http://localhost/sitemap.xml', false);
    }

    /** @return list<string> */
    private function postLocs(): array
    {
        $out = [];
        foreach (array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-posts-vi-')) as $name) {
            array_push($out, ...$this->chunkLocs($name));
        }

        return $out;
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(static function ($q) use (&$count): void {
            if (! str_contains($q->sql, 'cache')) {
                $count++;
            }
        });
        $run();
        DB::flushQueryLog();
        app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $count;
    }
}
