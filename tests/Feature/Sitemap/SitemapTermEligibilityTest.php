<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-SITEMAP-S02: a category or tag archive is listed in a locale only when
 * at least one post in it is itself sitemap-eligible in that locale (published,
 * not soft-deleted, discoverable, exact-locale slug). Eligibility is computed
 * live in SQL, never from stored counters.
 */
final class SitemapTermEligibilityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    private function term(string $type, string $name, bool $english = false): Term
    {
        $term = app('cms.taxonomy')->createTerm($type, ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);

        if ($english) {
            $term->translations()->create(['locale' => 'en', 'name' => $name.' EN', 'slug' => Str::slug($name).'-en']);
        }

        return $term;
    }

    private function smTermPost(string $title, string $status, array $termIds, bool $english = false, array $extra = []): Content
    {
        $content = app('cms.content')->create([
            'type' => 'post', 'status' => $status, 'locale' => 'vi', 'title' => $title,
            'slug' => Str::slug($title), 'content' => '<p>x</p>', 'term_ids' => $termIds,
        ] + $extra, \Tests\Support\ContentFixtures::context());

        if ($english) {
            $content = app('cms.content')->update($content, [
                'locale' => 'en', 'title' => $title.' EN', 'slug' => Str::slug($title).'-en', 'content' => '<p>EN</p>',
            ], null, \Tests\Support\ContentFixtures::context());
        }

        return $content;
    }

    /** @return list<string> */
    private function termLocs(string $set): array
    {
        $out = [];
        foreach ($this->indexNames() as $name) {
            if (str_starts_with($name, 'core-'.$set.'-')) {
                array_push($out, ...$this->chunkLocs($name));
            }
        }

        return $out;
    }

    public function test_category_cases(): void
    {
        $empty = $this->term('category', 'Rong');
        $draft = $this->term('category', 'Chi nhap');
        $private = $this->term('category', 'Rieng tu');
        $pending = $this->term('category', 'Cho duyet');
        $future = $this->term('category', 'Tuong lai');
        $public = $this->term('category', 'Cong khai');

        $this->smTermPost('Nhap', 'draft', [$draft->id]);
        $this->smTermPost('Rieng', 'private', [$private->id]);
        $this->smTermPost('Cho', 'pending', [$pending->id]);
        // CORE-PUBLISH-1: a published post with a future date is scheduled; it is
        // not public (404) until then, so an archive holding only it is not listed.
        $this->smTermPost('Hen gio', 'published', [$future->id], false, ['published_at' => now()->addDays(30)]);
        $this->smTermPost('Cong', 'published', [$public->id]);

        $locs = $this->termLocs('categories');
        $this->assertNotContains('http://localhost/category/rong', $locs);
        $this->assertNotContains('http://localhost/category/chi-nhap', $locs);
        $this->assertNotContains('http://localhost/category/rieng-tu', $locs);
        $this->assertNotContains('http://localhost/category/cho-duyet', $locs);
        $this->assertNotContains('http://localhost/category/tuong-lai', $locs);
        $this->assertContains('http://localhost/category/cong-khai', $locs);
        $this->get('/blog/hen-gio')->assertNotFound();
        $this->assertNotNull($empty);
    }

    public function test_tag_cases(): void
    {
        $empty = $this->term('tag', 'The rong');
        $draft = $this->term('tag', 'The nhap');
        $public = $this->term('tag', 'The cong khai');
        $this->smTermPost('Bai nhap', 'draft', [$draft->id]);
        $this->smTermPost('Bai cong khai', 'published', [$public->id]);

        $locs = $this->termLocs('tags');
        $this->assertNotContains('http://localhost/tag/the-rong', $locs);
        $this->assertNotContains('http://localhost/tag/the-nhap', $locs);
        $this->assertContains('http://localhost/tag/the-cong-khai', $locs);
        $this->assertNotNull($empty);
    }

    public function test_archive_eligibility_is_per_locale(): void
    {
        $viOnly = $this->term('category', 'Chi viet', english: true);
        $both = $this->term('category', 'Song ngu', english: true);
        $this->smTermPost('Bai viet', 'published', [$viOnly->id], english: false);
        $this->smTermPost('Bai song ngu', 'published', [$both->id], english: true);

        $vi = $this->termLocs('categories');
        $this->assertContains('http://localhost/category/chi-viet', $vi);
        $this->assertContains('http://localhost/category/song-ngu', $vi);
        $this->assertNotContains('http://localhost/en/category/chi-viet-en', $vi, 'no en post, so no en archive');
        $this->assertContains('http://localhost/en/category/song-ngu-en', $vi);
    }

    public function test_archive_becomes_unlisted_after_unpublish_and_soft_delete(): void
    {
        $a = $this->term('category', 'Mot');
        $b = $this->term('category', 'Hai');
        $p1 = $this->smTermPost('Bai mot', 'published', [$a->id]);
        $p2 = $this->smTermPost('Bai hai', 'published', [$b->id]);
        $this->assertContains('http://localhost/category/mot', $this->termLocs('categories'));

        app('cms.content')->update($p1, ['status' => 'draft'], null, \Tests\Support\ContentFixtures::context());
        $p2->delete(); // soft delete

        $locs = $this->termLocs('categories');
        $this->assertNotContains('http://localhost/category/mot', $locs);
        $this->assertNotContains('http://localhost/category/hai', $locs);
        $this->assertTrue(Content::withTrashed()->whereKey($p2->id)->exists());
    }

    public function test_attaching_a_post_to_an_empty_archive_lists_it(): void
    {
        $empty = $this->term('category', 'Se co');
        $other = $this->term('category', 'Khac');
        $p = $this->smTermPost('Bai', 'published', [$other->id]);
        $this->assertNotContains('http://localhost/category/se-co', $this->termLocs('categories'));

        app('cms.content')->update($p, ['term_ids' => [$other->id, $empty->id]], null, \Tests\Support\ContentFixtures::context());

        $this->assertContains('http://localhost/category/se-co', $this->termLocs('categories'));
    }

    public function test_unavailable_plugin_owned_posts_do_not_make_an_archive_eligible(): void
    {
        $this->smPlugin('sm-owner');
        $cat = $this->term('category', 'So huu');
        $owned = $this->smCode('sm-owner')->own([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Bai so huu', 'slug' => 'bai-so-huu', 'content' => '<p>x</p>', 'term_ids' => [$cat->id],
        ]);
        $this->assertContains('http://localhost/category/so-huu', $this->termLocs('categories'));

        $this->smCode('sm-owner')->availability($owned->id, ContentAvailability::Unavailable);

        $this->assertNotContains('http://localhost/category/so-huu', $this->termLocs('categories'));
    }

    public function test_the_term_child_query_count_does_not_grow_with_terms_or_posts(): void
    {
        $counts = [];
        foreach ([3, 30] as $n) {
            foreach (range(1, $n) as $i) {
                $t = $this->term('category', "Nhom {$n} {$i}");
                $this->smTermPost("Bai {$n} {$i}", 'published', [$t->id]);
            }
            $this->get('/sitemap.xml')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/sitemaps/core-categories-vi-1.xml')->assertOk()->streamedContent();
            $counts[$n] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($counts[3], $counts[30], 'bounded queries per child: '.json_encode($counts));
    }
}
