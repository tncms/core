<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\RobotsRestriction;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * Owner contract A: noindex is a property of the individual URL. A public,
 * published post that its owner marks noindex is left out of the sitemap, but
 * it still makes its category/tag archive non-empty; the archive stays listed.
 * Non-public posts (draft/private/deleted/unavailable) never count.
 */
final class SitemapArchiveNoindexSemanticsTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private string $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPlugin('sm-owner');
        $this->policy = $this->smClass('sm-owner', 'SeoPolicy');
        $this->assertTrue($this->smCode('sm-owner')->registerSeo(new $this->policy));
        $this->policy::$exclude = [];
        $this->policy::$robots = null;
    }

    private function term(string $type, string $name, bool $english = false): Term
    {
        $term = app('cms.taxonomy')->createTerm($type, ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);
        if ($english) {
            $term->translations()->create(['locale' => 'en', 'name' => $name.' EN', 'slug' => Str::slug($name).'-en']);
        }

        return $term;
    }

    /** An owner-managed published post attached to $terms. */
    private function ownedIn(string $title, array $terms, string $status = 'published'): Content
    {
        return $this->smCode('sm-owner')->own([
            'type' => 'post', 'status' => $status, 'locale' => 'vi', 'title' => $title,
            'slug' => Str::slug($title), 'content' => '<p>x</p>', 'term_ids' => array_map(static fn (Term $t) => $t->id, $terms),
        ]);
    }

    private function noindex(Content ...$items): void
    {
        $this->policy::$exclude = array_map(static fn (Content $c) => $c->id, $items);
        $this->policy::$robots = RobotsRestriction::NoIndex;
        app('cms.sitemap')->invalidateCore();
    }

    /** @return list<string> */
    private function locsOf(string $set): array
    {
        $out = [];
        foreach ($this->indexNames() as $name) {
            if (str_starts_with($name, 'core-'.$set.'-')) {
                array_push($out, ...$this->chunkLocs($name));
            }
        }

        return $out;
    }

    public function test_a_public_noindex_post_is_excluded_but_keeps_its_archives_listed(): void
    {
        $cat = $this->term('category', 'Chu de an');
        $tag = $this->term('tag', 'The an');
        $post = $this->ownedIn('Bai noindex', [$cat, $tag]);
        $this->assertSame([$cat->id, $tag->id], DB::table('cms_content_terms')->where('content_id', $post->id)->orderBy('term_id')->pluck('term_id')->map(fn ($v) => (int) $v)->all());
        $this->noindex($post);

        $this->assertNotContains('http://localhost/blog/bai-noindex', $this->locsOf('posts'), 'the noindex item itself is excluded');
        $this->assertContains('http://localhost/category/chu-de-an', $this->locsOf('categories'), 'the archive is not noindexed by its child');
        $this->assertContains('http://localhost/tag/the-an', $this->locsOf('tags'));

        // The archive page is itself public and indexable.
        $this->get('/category/chu-de-an')->assertOk();
    }

    public function test_child_noindex_never_propagates_but_non_public_children_never_count(): void
    {
        $withNoindex = $this->term('category', 'Co noindex');
        $draftOnly = $this->term('category', 'Chi ban nhap');
        $privateOnly = $this->term('category', 'Chi rieng tu');
        $empty = $this->term('category', 'Trong');

        $visible = $this->ownedIn('Noindex cong khai', [$withNoindex]);
        $draft = $this->ownedIn('Nhap', [$draftOnly], 'draft');
        $private = $this->ownedIn('Rieng', [$privateOnly], 'private');
        $this->noindex($visible, $draft, $private);

        $cats = $this->locsOf('categories');
        $this->assertContains('http://localhost/category/co-noindex', $cats);
        $this->assertNotContains('http://localhost/category/chi-ban-nhap', $cats);
        $this->assertNotContains('http://localhost/category/chi-rieng-tu', $cats);
        $this->assertNotContains('http://localhost/category/trong', $cats);
        $this->assertNotNull($empty);
    }

    public function test_withdrawing_the_only_noindex_child_removes_the_archive(): void
    {
        $cat = $this->term('category', 'Tam thoi');
        $post = $this->ownedIn('Duy nhat', [$cat]);
        $this->noindex($post);
        $this->assertContains('http://localhost/category/tam-thoi', $this->locsOf('categories'));

        // The owner withdraws it through its supported seam (owner-managed content
        // refuses raw Eloquent deletes): it is no longer public, so it stops counting.
        $this->smCode('sm-owner')->availability($post->id, \TheNguyen\CMS\Content\Extension\ContentAvailability::Gone);
        app('cms.sitemap')->invalidateCore();
        $this->assertNotContains('http://localhost/category/tam-thoi', $this->locsOf('categories'));
    }

    public function test_noindex_children_are_evaluated_per_locale(): void
    {
        $cat = $this->term('category', 'Song ngu', english: true);
        $post = $this->ownedIn('Chi tieng viet noindex', [$cat]); // vi translation only
        $this->noindex($post);

        $this->assertContains('http://localhost/category/song-ngu', $this->locsOf('categories'));
        $this->assertNotContains('http://localhost/en/category/song-ngu-en', $this->locsOf('categories'), 'no public en post: not listed in en');
    }

    public function test_archive_eligibility_query_count_does_not_grow_with_noindex_children(): void
    {
        $measure = function (int $n): int {
            DB::table('cms_content_terms')->delete();
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $t = $this->term('category', 'Q'.$n.'x'.$i);
                $ids[] = $this->ownedIn('Q post '.$n.'x'.$i, [$t])->id;
            }
            $this->policy::$exclude = $ids;
            app('cms.sitemap')->invalidateCore();
            $this->indexNames();

            $count = 0;
            DB::listen(static function () use (&$count): void {
                $count++;
            });
            $this->chunkLocs('core-categories-vi-1');

            return $count;
        };

        $small = $measure(3);
        $large = $measure(15);
        $this->assertSame($small, $large);
    }
}
