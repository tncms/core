<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-SITEMAP-R1 Gate E: the sitemap honours CORE-CONTENT-EXT-R1 exactly as
 * the single page does — publication, persisted availability, inactive and
 * missing owner fallback, and the owner's noindex policy (batched per chunk).
 */
final class SitemapContentVisibilityTest extends TestCase
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

    public function test_only_published_available_content_is_listed_and_matches_the_single_page(): void
    {
        $core = $this->smPost('Core', english: false);
        $draft = $this->smPost('Draft', english: false);
        app('cms.content')->changeStatus($draft, 'draft', null, \Tests\Support\ContentFixtures::context());
        $visible = $this->owned('Visible');
        $states = [
            'unavailable' => ContentAvailability::Unavailable,
            'gone' => ContentAvailability::Gone,
            'paused' => ContentAvailability::TemporarilyUnavailable,
        ];
        $hidden = [];
        foreach ($states as $title => $state) {
            $hidden[$title] = $this->owned($title);
            $this->smCode('sm-owner')->availability($hidden[$title]->id, $state);
        }

        $locs = $this->allLocs();
        foreach ([$core, $visible] as $item) {
            $this->assertContains($this->loc($item), $locs);
            $this->get(parse_url($this->loc($item), PHP_URL_PATH))->assertOk();
        }

        $this->assertNotContains($this->loc($draft), $locs);
        foreach ($hidden as $title => $item) {
            $this->assertNotContains($this->loc($item), $locs, $title);
            $this->assertNotSame(200, $this->get(parse_url($this->loc($item), PHP_URL_PATH))->status(), $title);
        }
    }

    public function test_availability_change_reaches_the_sitemap_without_a_global_flush(): void
    {
        $item = $this->owned('Toggle');
        $this->assertContains($this->loc($item), $this->allLocs());

        $this->smCode('sm-owner')->availability($item->id, ContentAvailability::Unavailable);
        $this->assertNotContains($this->loc($item), $this->allLocs());

        $this->smCode('sm-owner')->availability($item->id, ContentAvailability::Available);
        $this->assertContains($this->loc($item), $this->allLocs());
    }

    public function test_inactive_and_missing_owner_fallback_hides_owned_content(): void
    {
        $item = $this->owned('Owned');
        $core = $this->smPost('Core', english: false);
        $this->assertContains($this->loc($item), $this->allLocs());

        app('cms.extension')->deactivatePlugin('sm-owner');
        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($item), $locs, 'hide_404 fallback while inactive');
        $this->assertContains($this->loc($core), $locs);

        app('cms.extension')->activatePlugin('sm-owner');
        $this->assertContains($this->loc($item), $this->allLocs(), 'reactivation restores it');

        \Illuminate\Support\Facades\File::deleteDirectory($this->smRoot().DIRECTORY_SEPARATOR.'sm-owner');
        app('cms.extension')->flushRegistry();
        $this->assertNotContains($this->loc($item), $this->allLocs(), 'missing owner hides it');
    }

    public function test_owner_noindex_policy_is_applied_once_per_chunk(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $policy::$robots = null;
        $this->assertTrue($this->smCode('sm-owner')->registerSeo(new $policy));

        $owned = $this->smBulkPosts(1100, owner: 'sm-owner', stem: 'own');
        $core = $this->smBulkPosts(400, stem: 'core');
        $policy::$exclude = [$owned[1], $owned[600], $owned[1099], $core[0]];

        $locs = $this->allLocs();
        foreach ([1, 600, 1099] as $i) {
            $this->assertNotContains('http://localhost/blog/own-'.$owned[$i], $locs, "noindex {$i}");
        }
        foreach ([0, 2, 599, 601, 1098] as $i) {
            $this->assertContains('http://localhost/blog/own-'.$owned[$i], $locs, "listed {$i}");
        }
        $this->assertContains('http://localhost/blog/core-'.$core[0], $locs, 'an owner cannot exclude Core content');

        // 1,500 posts / 500 = 3 chunks; the third holds 100 owned + 400 Core.
        $this->assertCount(3, $policy::$calls, 'one batched call per chunk holding owned items, never per item');
        $this->assertSame([500, 500, 100], array_map('count', $policy::$calls));
    }

    public function test_a_throwing_owner_policy_excludes_only_that_owners_items(): void
    {
        $policy = $this->smClass('sm-owner', 'SeoPolicy');
        $this->assertTrue($this->smCode('sm-owner')->registerSeo(new $policy));
        $policy::$throw = true;
        $item = $this->owned('Owned');
        $core = $this->smPost('Core', english: false);

        Log::spy();
        $locs = $this->allLocs();

        $this->assertNotContains($this->loc($item), $locs, 'fail closed');
        $this->assertContains($this->loc($core), $locs);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn ($message, $context) => $message === 'TN CMS sitemap policy failed' && ! str_contains((string) json_encode($context), 'SM-SECRET'),
        );
    }

    public function test_listing_uses_one_ownership_filter_and_batched_markers(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->owned('Owned '.$i);
        }
        $this->indexNames();

        // Driver-independent: identifier quoting differs ("x" on SQLite, `x` on
        // MySQL), so compare the statement with its quotes removed.
        $markerQueries = 0;
        $batched = 0;
        \Illuminate\Support\Facades\DB::listen(static function ($q) use (&$markerQueries, &$batched): void {
            $sql = str_replace(['"', '`'], '', $q->sql);
            if (str_starts_with($sql, 'select id, managed_by from cms_contents where id in (')) {
                $markerQueries++;
                $batched = max($batched, count($q->bindings));
            }
        });
        $this->chunkLocs('core-posts-vi-1');

        $this->assertSame(1, $markerQueries, 'one ownership-marker query per chunk, never per item');
        $this->assertGreaterThanOrEqual(8, $batched, 'the single query carries every listed id');
    }
}
