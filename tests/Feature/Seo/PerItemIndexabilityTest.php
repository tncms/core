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
use TheNguyen\CMS\Sitemap\SitemapSettings;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1, Track A: a per-item robots restriction on
 * Post, Page, Category and Tag (`seo_robots`). It can only add noindex/nofollow to
 * the inherited site policy, never loosen it; a noindex item stays reachable,
 * leaves the sitemap, and changes no other resource.
 */
final class PerItemIndexabilityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: false);
    }

    private function term(string $type, string $name, array $extra = []): Term
    {
        return app('cms.taxonomy')->createTerm($type, ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)] + $extra);
    }

    private function item(string $title, array $extra = [], string $type = 'post'): Content
    {
        return $this->smPost($title, english: false, extra: $extra, type: $type);
    }

    private function path(Content|Term $item): string
    {
        $url = $item instanceof Term ? term_url($item, 'vi') : content_url($item, 'vi');

        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function loc(Content|Term $item): string
    {
        return url($this->path($item));
    }

    /** @return list<string> every robots meta value on the page */
    private function robotsOf(string $path): array
    {
        $response = $this->get($path);
        $response->assertOk();
        preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', (string) $response->getContent(), $m);

        return $m[1];
    }

    public function test_existing_items_keep_the_inherited_default(): void
    {
        $post = $this->item('Mac dinh');

        $this->assertNull($post->fresh()->seo_robots);
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($post)));
        $this->assertContains($this->loc($post), $this->allLocs());
    }

    public function test_a_noindex_post_stays_reachable_leaves_the_sitemap_and_changes_nothing_else(): void
    {
        $category = $this->term('category', 'Chuyen muc');
        $hidden = $this->item('An di', ['seo_robots' => 'noindex', 'term_ids' => [$category->id]]);
        $other = $this->item('Con lai');

        $this->assertSame('noindex', $hidden->fresh()->seo_robots);
        $this->assertSame(['noindex,follow'], $this->robotsOf($this->path($hidden)), 'one coherent tag');
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($other)));

        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($hidden), $locs);
        $this->assertContains($this->loc($other), $locs);
        // Archive eligibility is public-content presence: the noindex post still counts.
        $this->assertContains($this->loc($category), $locs);
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($category)));
    }

    public function test_nofollow_keeps_the_item_indexed_and_listed(): void
    {
        $post = $this->item('Khong theo', ['seo_robots' => 'nofollow']);

        $this->assertSame(['index,nofollow'], $this->robotsOf($this->path($post)));
        $this->assertContains($this->loc($post), $this->allLocs());

        $both = $this->item('Ca hai', ['seo_robots' => 'noindex,nofollow']);
        $this->assertSame(['noindex,nofollow'], $this->robotsOf($this->path($both)));
        $this->assertNotContains($this->loc($both), $this->allLocs());
    }

    public function test_a_noindex_page_leaves_the_pages_sitemap(): void
    {
        $page = $this->item('Trang an', ['seo_robots' => 'noindex'], type: 'page');
        $visible = $this->item('Trang hien', type: 'page');

        $this->assertSame(['noindex,follow'], $this->robotsOf($this->path($page)));
        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($page), $locs);
        $this->assertContains($this->loc($visible), $locs);
    }

    public function test_a_noindex_category_or_tag_archive_leaves_the_sitemap_but_its_posts_stay(): void
    {
        $category = $this->term('category', 'Danh muc an', ['seo_robots' => 'noindex']);
        $tag = $this->term('tag', 'The an', ['seo_robots' => 'noindex,nofollow']);
        $open = $this->term('category', 'Danh muc mo');
        $post = $this->item('Bai viet', ['term_ids' => [$category->id, $tag->id, $open->id]]);

        $this->assertSame('noindex', $category->fresh()->seo_robots);
        $this->assertSame(['noindex,follow'], $this->robotsOf($this->path($category)));
        $this->assertSame(['noindex,nofollow'], $this->robotsOf($this->path($tag)));
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($post)), 'an archive policy never reaches its posts');

        $locs = $this->allLocs();
        $this->assertNotContains($this->loc($category), $locs);
        $this->assertNotContains($this->loc($tag), $locs);
        $this->assertContains($this->loc($open), $locs);
        $this->assertContains($this->loc($post), $locs);
    }

    public function test_site_wide_noindex_wins_and_an_item_cannot_loosen_the_site_default(): void
    {
        $post = $this->item('Bai', ['seo_robots' => 'nofollow']);
        settings()->set('seo.robots_default', 'noindex,follow', 'string');
        $this->assertSame(['noindex,nofollow'], $this->robotsOf($this->path($post)), 'restriction merges onto the default');

        settings()->set('seo.robots_default', 'index,follow', 'string');
        settings()->set('seo.noindex_site', true, 'boolean');
        $plain = $this->item('Thuong');
        $this->assertSame(['noindex,nofollow'], $this->robotsOf($this->path($plain)));
    }

    public function test_sitemap_type_exclusion_never_changes_html_robots(): void
    {
        $post = $this->item('Loai tru');
        settings()->set(SitemapSettings::EXCLUDED_TYPES, ['posts'], 'array');

        $this->assertNotContains($this->loc($post), $this->allLocs());
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($post)));
    }

    public function test_changing_the_policy_reaches_the_sitemap_without_a_manual_flush(): void
    {
        $post = $this->item('Doi');
        $this->assertContains($this->loc($post), $this->allLocs());

        app('cms.content')->update($post, ['seo_robots' => 'noindex'], null, ContentFixtures::context());
        $this->assertNotContains($this->loc($post), $this->allLocs());

        app('cms.content')->update($post->fresh(), ['seo_robots' => null], null, ContentFixtures::context());
        $this->assertNull($post->fresh()->seo_robots);
        $this->assertContains($this->loc($post), $this->allLocs());
    }

    public function test_an_update_that_omits_the_field_preserves_it(): void
    {
        $post = $this->item('Giu', ['seo_robots' => 'noindex']);
        app('cms.content')->update($post, ['locale' => 'vi', 'title' => 'Giu moi'], null, ContentFixtures::context());
        $this->assertSame('noindex', $post->fresh()->seo_robots);

        $term = $this->term('tag', 'Giu the', ['seo_robots' => 'nofollow']);
        app('cms.taxonomy')->updateTerm($term, ['locale' => 'vi', 'name' => 'Giu the moi']);
        $this->assertSame('nofollow', $term->fresh()->seo_robots);
    }

    public function test_an_invalid_value_is_rejected_at_the_manager(): void
    {
        foreach (['index', 'all', 'noindex, nofollow ', '<script>', 'NOINDEX'] as $bad) {
            try {
                $this->item('Sai '.md5($bad), ['seo_robots' => $bad]);
                $this->fail('accepted '.$bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }

            try {
                $this->term('tag', 'Sai '.md5($bad), ['seo_robots' => $bad]);
                $this->fail('term accepted '.$bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Content::query()->whereNotNull('seo_robots')->count());
        $this->assertSame(0, Term::query()->whereNotNull('seo_robots')->count());
    }

    public function test_an_unrecognised_stored_value_inherits_the_site_policy(): void
    {
        // Content write authority is the ContentManager + write gate; a stored
        // value that is not a valid restriction is ignored (inherit), never trusted.
        $post = $this->item('Hong');
        Content::query()->whereKey($post->id)->update(['seo_robots' => 'bogus']);
        $this->assertSame(['index,follow'], $this->robotsOf($this->path($post)));
        $this->assertContains($this->loc($post), $this->allLocs());
    }
}
