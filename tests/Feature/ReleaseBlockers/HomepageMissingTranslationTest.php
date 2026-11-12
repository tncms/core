<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-RELEASE-BLOCKERS-R1 (TD-R3): a static homepage that has no translation for the requested
 * language is not indexed there and is absent from that locale's sitemap. Never copies one translation
 * into another.
 *
 * SUPERSEDED IN PART by owner policy A (CORE-RELEASE-BLOCKERS-R1-CLOSURE, docs/core-release-blockers-r1-closure/02):
 * R1 kept the visible output (200, default-language title, empty body); the owner then chose a localized
 * 404 + noindex with no other-language content. The three `/en` assertions below were updated to that
 * decision; every other R1 guarantee is unchanged. The full policy matrix is HomepagePolicyATest.
 */
final class HomepageMissingTranslationTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'rb1_home_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();

        // Minimal disposable theme: the page view prints the resolved robots, title and body.
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', '<meta name="robots" content="{{ app("cms.seo")->robots() }}"><h1>{{ $title }}</h1><main>{!! $body !!}</main>');
        File::put($dir.'/views/pages/home.blade.php', 'HOME');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'RB1', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function viOnlyHome(): Content
    {
        $page = $this->smPost('Trang chu tinh', false, [], 'page');
        $this->assertTrue($page->hasTranslation('vi'));
        $this->assertFalse($page->hasTranslation('en'), 'fixture: no English translation');
        $s = app('cms.settings');
        $s->set('reading.homepage_display', 'static_page', 'string');
        $s->set('reading.homepage_page_id', $page->id, 'integer');
        $s->clearCache();

        return $page;
    }

    private function robots(string $html): string
    {
        preg_match('/<meta name="robots" content="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function test_the_default_language_homepage_is_unchanged_and_indexable(): void
    {
        $this->viOnlyHome();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Trang chu tinh', $html);
        $this->assertStringNotContainsString('noindex', $this->robots($html));
    }

    public function test_the_untranslated_language_homepage_is_not_indexed(): void
    {
        $this->viOnlyHome();

        // Policy A: a 404 (this fixture theme has no errors view, so Core's error view renders it).
        $html = (string) $this->get('/en')->assertNotFound()->getContent();

        $this->assertStringContainsString('noindex', $this->robots($html), 'an untranslated homepage must not be indexed in that language');
        $this->assertStringContainsString('follow', $this->robots($html));
        $this->assertStringNotContainsString('Trang chu tinh', $html, 'no other-language content');
    }

    public function test_the_page_s_own_untranslated_url_stays_not_found(): void
    {
        $page = $this->viOnlyHome();
        $slug = $page->translations()->where('locale', 'vi')->value('slug');

        $this->get('/en/'.$slug)->assertNotFound();
    }

    public function test_a_translated_homepage_stays_indexable_in_both_languages(): void
    {
        $page = $this->viOnlyHome();
        app('cms.content')->update($page, ['locale' => 'en', 'title' => 'Static home EN', 'slug' => 'static-home-en', 'content' => '<p>EN body</p>'], null,
            \TheNguyen\CMS\ContentAuthority\ContentWriteContext::system('install_seed'));

        foreach (['/', '/en'] as $url) {
            $this->assertStringNotContainsString('noindex', $this->robots($this->get($url)->assertOk()->getContent()), $url);
        }
        $this->assertStringContainsString('EN body', $this->get('/en')->getContent());
    }

    public function test_no_translation_is_written_or_copied_by_rendering(): void
    {
        $page = $this->viOnlyHome();
        $before = $page->translations()->orderBy('locale')->get(['locale', 'title', 'content'])->toArray();

        $this->get('/en')->assertNotFound();
        $this->get('/')->assertOk();

        $this->assertSame($before, $page->fresh()->translations()->orderBy('locale')->get(['locale', 'title', 'content'])->toArray());
    }

    public function test_an_explicit_item_noindex_still_applies_and_is_not_loosened(): void
    {
        $page = $this->viOnlyHome();
        $page->forceFill(['seo_robots' => 'noindex,nofollow'])->saveQuietly();

        $this->assertSame('noindex,nofollow', $this->robots($this->get('/')->getContent()));
        // Policy A: `/en` is the noindex 404 page (the item's own robots apply to the page it renders).
        $this->assertStringContainsString('noindex', $this->robots((string) $this->get('/en')->assertNotFound()->getContent()));
    }

    public function test_the_sitemap_lists_the_home_only_in_languages_the_home_page_is_translated_into(): void
    {
        $this->viOnlyHome();

        $this->get('/sitemaps/core-home-vi-1.xml')->assertOk();
        $this->get('/sitemaps/core-home-en-1.xml')->assertNotFound();
        $this->assertStringNotContainsString('core-home-en-1.xml', $this->get('/sitemap.xml')->getContent());
    }
}
