<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\FrontPage;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-SITEMAP-S02: the page that renders at `/` is listed once, as the home
 * entry, and never again under its own slug. Exclusion follows the exact
 * FrontendController::home() decision; when that decision is not provable
 * (no theme, section layout) nothing is excluded.
 */
final class SitemapFrontPageTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'smfp_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function theme(bool $home = false, bool $page = true): void
    {
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        if ($page) {
            File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        }
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        if ($home) {
            File::put($dir.'/views/pages/home.blade.php', 'HOME');
        }
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'SM FP', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    /** @return list<string> every page loc across both locales */
    private function pageLocs(): array
    {
        $out = [];
        foreach ($this->indexNames() as $name) {
            if (str_starts_with($name, 'core-pages-')) {
                array_push($out, ...$this->chunkLocs($name));
            }
        }

        return $out;
    }

    private function conventionalHome(): Content
    {
        return $this->smPost('Trang chu', true, ['slug' => 'trang-chu'], 'page');
    }

    public function test_conventional_home_page_is_listed_once_as_the_home_entry(): void
    {
        $this->theme();
        $home = $this->conventionalHome();
        $about = $this->smPost('Gioi thieu', true, [], 'page');

        $this->assertSame('PAGE', $this->get('/')->assertOk()->getContent(), '/ renders the conventional home page');
        $this->assertSame($home->id, FrontPage::renderedAtRootId());

        $locs = $this->pageLocs();
        $this->assertNotContains('http://localhost/trang-chu', $locs);
        $this->assertNotContains('http://localhost/en/trang-chu-en', $locs);
        $this->assertContains('http://localhost/gioi-thieu', $locs);
        $this->assertContains('http://localhost/en/gioi-thieu-en', $locs);

        // One home entry per locale with reciprocal alternates.
        $this->assertSame(['http://localhost'], $this->chunkLocs('core-home-vi-1'));
        $this->assertSame(['http://localhost/en'], $this->chunkLocs('core-home-en-1'));

        // The page itself is untouched; its slug URL now 301s to `/` (owner contract B,
        // CORE-SITEMAP-SEO-ADOPTION; S02 had deliberately left it at 200).
        $this->get('/trang-chu')->assertStatus(301)->assertHeader('Location', '/');
        $this->assertNotNull(Content::query()->find($home->id));
        $this->assertNotNull($about);
    }

    public function test_static_front_page_is_excluded_and_the_conventional_page_is_not(): void
    {
        $this->theme();
        $conventional = $this->conventionalHome();
        $static = $this->smPost('Trang tinh', true, [], 'page');
        app('cms.settings')->set('reading.homepage_display', 'static_page');
        app('cms.settings')->set('reading.homepage_page_id', $static->id);

        $this->assertSame($static->id, FrontPage::renderedAtRootId());

        $locs = $this->pageLocs();
        $this->assertNotContains('http://localhost/trang-tinh', $locs);
        $this->assertNotContains('http://localhost/en/trang-tinh-en', $locs);
        $this->assertContains('http://localhost/trang-chu', $locs, 'not at / in static mode, so not a duplicate');
        $this->assertNotNull($conventional);
    }

    public function test_changing_the_front_page_re_partitions_without_a_flush(): void
    {
        $this->theme();
        $a = $this->smPost('Trang A', false, [], 'page');
        $b = $this->smPost('Trang B', false, [], 'page');
        app('cms.settings')->set('reading.homepage_display', 'static_page');
        app('cms.settings')->set('reading.homepage_page_id', $a->id);

        $locs = $this->pageLocs();
        $this->assertNotContains('http://localhost/trang-a', $locs);
        $this->assertContains('http://localhost/trang-b', $locs);

        app('cms.settings')->set('reading.homepage_page_id', $b->id);

        $locs = $this->pageLocs();
        $this->assertContains('http://localhost/trang-a', $locs);
        $this->assertNotContains('http://localhost/trang-b', $locs);
    }

    public function test_a_site_whose_only_page_is_the_front_page_lists_no_empty_pages_child(): void
    {
        $this->theme();
        $this->conventionalHome();

        $names = $this->indexNames();
        $this->assertNotContains('core-pages-vi-1', $names);
        $this->assertNotContains('core-pages-en-1', $names);
        $this->assertContains('core-home-vi-1', $names);
        foreach ($names as $name) {
            $this->get('/sitemaps/'.$name.'.xml')->assertOk();
        }
    }

    public function test_front_page_settings_invalidate_the_core_manifest_and_unrelated_settings_do_not(): void
    {
        $this->theme();
        $only = $this->conventionalHome();
        $this->assertNotContains('core-pages-vi-1', $this->indexNames());
        $version = static fn () => \Illuminate\Support\Facades\Cache::get('cms.sitemap.version.core');

        $before = $version();
        app('cms.settings')->set('general.site_name', 'Khong lien quan');
        $this->assertSame($before, $version(), 'unrelated setting');

        // latest_posts: the page is no longer at /, so it is listed again at once.
        app('cms.settings')->set('reading.homepage_display', 'latest_posts');
        $this->assertNotSame($before, $version());
        $this->assertContains('core-pages-vi-1', $this->indexNames());
        $this->assertContains('http://localhost/trang-chu', $this->chunkLocs('core-pages-vi-1'));

        $this->assertNotNull($only);
    }

    public function test_latest_posts_home_excludes_nothing(): void
    {
        $this->theme();
        $this->conventionalHome();
        app('cms.settings')->set('reading.homepage_display', 'latest_posts');

        $this->assertSame('ARCHIVE', $this->get('/')->assertOk()->getContent());
        $this->assertNull(FrontPage::renderedAtRootId());
        $this->assertContains('http://localhost/trang-chu', $this->pageLocs());
    }

    public function test_a_section_layout_homepage_excludes_nothing(): void
    {
        // Whether sections or the page render at / is only known by rendering:
        // the sitemap keeps the page (conservative; never hides a page).
        $this->theme(home: true);
        $this->conventionalHome();
        app('cms.settings')->set('theme.'.self::THEME.'.homepage_layout', (string) json_encode(['sections' => [['type' => 'hero', 'data' => []]]]));

        $this->assertTrue(app('cms.homepage')->hasLayout());

        $this->assertNull(FrontPage::renderedAtRootId());
        $this->assertContains('http://localhost/trang-chu', $this->pageLocs());
    }

    public function test_an_unpublished_front_page_choice_excludes_nothing(): void
    {
        $this->theme();
        $draft = app('cms.content')->create([
            'type' => 'page', 'status' => 'draft', 'locale' => 'vi', 'title' => 'Nhap', 'slug' => 'nhap', 'content' => '<p>x</p>',
        ], \Tests\Support\ContentFixtures::context());
        $conventional = $this->conventionalHome();
        app('cms.settings')->set('reading.homepage_display', 'static_page');
        app('cms.settings')->set('reading.homepage_page_id', $draft->id);

        // home() falls through to the conventional page, so that one is at /.
        $this->assertSame($conventional->id, FrontPage::renderedAtRootId());
        $this->assertNotContains('http://localhost/trang-chu', $this->pageLocs());
    }
}
