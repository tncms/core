<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\RobotsRestriction;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-37-INTEGRATION-R1 Gates B and C: one indexability decision for the
 * page and the sitemap — the site policy (SeoManager::siteIndexable) drives
 * robots.txt and the sitemap; the owner's batched ContentSitemapPolicy drives
 * both the page robots and sitemap membership; the chunk size is a validated
 * Core setting.
 */
final class SitemapIndexabilityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: false);
        $this->smPlugin('sm-owner');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$exclude = [];
        $policy::$throw = false;
        $policy::$calls = [];
        $policy::$robots = null;
    }

    private function owned(string $title): Content
    {
        return $this->smCode('sm-owner')->own([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => $title, 'slug' => Str::slug($title), 'content' => '<p>x</p>',
        ]);
    }

    private function loc(Content $content): string
    {
        return url(content_url($content, 'vi'));
    }

    private function pageRobots(Content $content): string
    {
        $html = $this->get(parse_url($this->loc($content), PHP_URL_PATH))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<meta name="robots" content="([^"]+)"/', (string) $html, $m));

        return $m[1];
    }

    private function registerPolicy(): void
    {
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $this->assertTrue($this->smCode('sm-owner')->registerSeo(new $policy));
    }

    // ── Gate B: whole-site noindex ───────────────────────────────────────────

    public function test_site_noindex_disables_sitemap_and_robots_txt_never_advertises_it(): void
    {
        $this->smPost('Alpha', english: false);
        $this->get('/robots.txt')->assertSee('Sitemap: http://localhost/sitemap.xml', false);

        foreach (['noindex,follow', 'noindex,nofollow', 'none', ' NoIndex , follow '] as $robots) {
            settings()->set('seo.robots_default', $robots);
            $this->assertFalse(app('cms.seo')->siteIndexable(), $robots);
            $this->get('/sitemap.xml')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
            $this->get('/sitemaps/core-posts-vi-1.xml')->assertNotFound();
            $txt = $this->get('/robots.txt')->assertOk()->getContent();
            $this->assertStringNotContainsString('Sitemap:', (string) $txt, "robots.txt must not advertise a disabled sitemap ({$robots})");
        }

        settings()->set('seo.robots_default', 'index,follow');
        settings()->set('seo.noindex_site', true, 'boolean');
        $this->assertSame("User-agent: *\nDisallow: /\n", $this->get('/robots.txt')->getContent());
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_re_enabling_indexing_restores_the_sitemap_immediately(): void
    {
        $this->smPost('Alpha', english: false);
        $before = $this->indexNames();

        settings()->set('seo.noindex_site', true, 'boolean');
        $this->get('/sitemap.xml')->assertNotFound();

        settings()->set('seo.noindex_site', false, 'boolean');
        $this->assertSame($before, $this->indexNames(), 'no stale 404 and no rebuild needed');
        $this->get('/robots.txt')->assertSee('Sitemap: http://localhost/sitemap.xml', false);
        $this->assertSame('index,follow', app('cms.seo')->robots());
    }

    // ── Gate C: owner indexability ───────────────────────────────────────────

    public function test_owner_seo_provider_without_batch_policy_keeps_owned_content_out_of_the_sitemap(): void
    {
        $owned = $this->owned('Owned');
        $core = $this->smPost('Core', english: false);
        $seoOnly = $this->smClass('sm-owner', 'SeoOnly');
        $this->assertTrue($this->smCode('sm-owner')->registerSeo(new $seoOnly));

        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($owned), $locs, 'robots unknown in batch: fail closed');
        $this->assertContains($this->loc($core), $locs);
        $this->assertSame('index,follow', $this->pageRobots($owned), 'the page itself is unaffected');
    }

    public function test_owner_without_any_seo_provider_is_listed_and_indexable(): void
    {
        $owned = $this->owned('Owned');

        $this->assertContains($this->loc($owned), $this->allLocs());
        $this->assertSame('index,follow', $this->pageRobots($owned));
    }

    public function test_policy_exclusion_makes_the_page_noindex_and_removes_it_from_the_sitemap(): void
    {
        $this->registerPolicy();
        $hidden = $this->owned('Hidden');
        $shown = $this->owned('Shown');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$exclude = [$hidden->id];

        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($hidden), $locs);
        $this->assertContains($this->loc($shown), $locs);
        $this->assertSame('noindex,follow', $this->pageRobots($hidden));
        $this->assertSame('index,follow', $this->pageRobots($shown));
    }

    public function test_page_policy_call_is_one_batched_call_with_the_page_id(): void
    {
        $this->registerPolicy();
        $item = $this->owned('Item');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$calls = [];

        $this->pageRobots($item);

        $this->assertSame([[$item->id]], $policy::$calls);
    }

    public function test_override_nofollow_is_kept_when_the_policy_adds_noindex(): void
    {
        $this->registerPolicy();
        $item = $this->owned('Item');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$exclude = [$item->id];
        $policy::$robots = RobotsRestriction::NoFollow;

        $this->assertSame('noindex,nofollow', $this->pageRobots($item));
    }

    public function test_a_throwing_policy_fails_closed_on_the_page_and_in_the_sitemap(): void
    {
        $this->registerPolicy();
        $item = $this->owned('Item');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$throw = true;

        $this->assertContains('core-posts-vi-1', $this->indexNames());
        $this->assertSame([], $this->chunkLocs('core-posts-vi-1'), 'listed part renders empty, never a 404');
        $this->assertSame('noindex,follow', $this->pageRobots($item));
    }

    public function test_override_noindex_with_an_indexable_policy_answer_is_logged_as_a_contract_violation(): void
    {
        $this->registerPolicy();
        $item = $this->owned('Item');
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$robots = RobotsRestriction::NoIndex;

        Log::spy();
        $this->assertSame('noindex,follow', $this->pageRobots($item), 'the owner restriction is never weakened');
        Log::shouldHaveReceived('warning')->withArgs(
            static fn ($message, $context) => $message === 'TN CMS content indexability mismatch' && $context === ['owner' => 'sm-owner', 'content_id' => $item->id],
        );
    }

    public function test_inactive_owner_with_core_render_fallback_is_indexable_and_listed(): void
    {
        $this->registerPolicy();
        $item = $this->smCode('sm-owner')->own([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'Fallback', 'slug' => 'fallback', 'content' => '<p>x</p>',
        ]);
        \Illuminate\Support\Facades\DB::table('cms_content_owner_policies')->where('content_id', $item->id)
            ->update(['fallback_policy' => ContentFallbackPolicy::CoreRender->value]);
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$exclude = [$item->id];
        app('cms.extension')->deactivatePlugin('sm-owner');
        app('cms.sitemap')->invalidateCore();
        $policy::$calls = [];

        $this->assertContains($this->loc($item), $this->allLocs(), 'Core renders it with Core SEO');
        $this->assertSame('index,follow', $this->pageRobots($item));
        $this->assertSame([], $policy::$calls, 'an inactive owner is never consulted');
    }

    // ── Gate B: chunk size setting ───────────────────────────────────────────

    public function test_chunk_size_defaults_to_2000_and_the_setting_is_validated(): void
    {
        config(['cms.sitemap.chunk_size' => null]);
        $this->assertSame(2000, app('cms.sitemap')->chunkSize());

        $cases = ['700' => 700, ' 900 ' => 900, 'abc' => 2000, '-5' => 2000, '12.5' => 2000, '' => 2000, '99' => 500, '9999999' => 50000];
        foreach ($cases as $value => $expected) {
            settings()->set('seo.sitemap_chunk_size', (string) $value, 'string');
            $this->assertSame($expected, app('cms.sitemap')->chunkSize(), "setting '{$value}'");
        }

        settings()->set('seo.sitemap_chunk_size', 1500, 'integer');
        config(['cms.sitemap.chunk_size' => 800]);
        $this->assertSame(1500, app('cms.sitemap')->chunkSize(), 'the Core setting wins over config');
        settings()->forget('seo.sitemap_chunk_size');
        $this->assertSame(800, app('cms.sitemap')->chunkSize());
    }

    public function test_changing_the_chunk_size_re_partitions_without_a_flush(): void
    {
        $ids = $this->smBulkPosts(2500);
        $this->assertSame(['core-posts-vi-1', 'core-posts-vi-2'], $this->postNames(), '2,000 + 500');

        settings()->set('seo.sitemap_chunk_size', 1000, 'integer');
        $names = $this->postNames();
        $this->assertSame(['core-posts-vi-1', 'core-posts-vi-2', 'core-posts-vi-3'], $names);
        $seen = [];
        foreach ($names as $name) {
            array_push($seen, ...$this->chunkLocs($name));
        }
        $this->assertSame(array_map(static fn (int $id) => 'http://localhost/blog/bulk-'.$id, $ids), $seen);
    }

    public function test_a_last_good_manifest_served_across_a_chunk_size_change_renders_whole_parts(): void
    {
        $this->smBulkPosts(2500);
        $this->assertSame(['core-posts-vi-1', 'core-posts-vi-2'], $this->postNames());

        settings()->set('seo.sitemap_chunk_size', 500, 'integer');
        $lock = Cache::lock('cms.sitemap.build.core', 120);
        $this->assertTrue($lock->get(), 'another request is rebuilding');

        try {
            $this->assertSame(['core-posts-vi-1', 'core-posts-vi-2'], $this->postNames(), 'last-good served');
            $this->assertCount(2000, $this->chunkLocs('core-posts-vi-1'), 'the limit recorded with the part, not the new one');
        } finally {
            $lock->release();
        }

        $this->assertCount(5, $this->postNames(), 'then re-partitioned at 500');
    }

    /** @return list<string> */
    private function postNames(): array
    {
        return array_values(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-posts-')));
    }
}
