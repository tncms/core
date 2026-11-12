<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-SITEMAP-SEO-ADOPTION (owner contract B): the public slug URL of the Page
 * that currently renders at `/` answers 301 to the locale's canonical home URL
 * (LanguageManager::localizedUrl($locale, '/')). Only that page, only its public
 * slug routes; reassignment restores normal behaviour.
 */
final class HomepageSlugRedirectTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'smhr_fixture';

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

    private function theme(bool $home = false): void
    {
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE {{ $content->id ?? "" }}');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        if ($home) {
            File::put($dir.'/views/pages/home.blade.php', 'HOME');
        }
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'SM HR', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    private function conventionalHome(): Content
    {
        return $this->smPost('Trang chu', true, ['slug' => 'trang-chu'], 'page');
    }

    private function useStatic(Content $page): void
    {
        app('cms.settings')->set('reading.homepage_display', 'static_page');
        app('cms.settings')->set('reading.homepage_page_id', $page->id);
    }

    public function test_conventional_home_page_slug_redirects_permanently_in_every_locale(): void
    {
        $this->theme();
        $this->conventionalHome();
        $about = $this->smPost('Gioi thieu', true, [], 'page');

        $this->get('/trang-chu')->assertStatus(301)->assertHeader('Location', '/');
        $this->get('/en/trang-chu-en')->assertStatus(301)->assertHeader('Location', '/en');

        // The homepage itself renders the page; no loop.
        $this->get('/')->assertOk();
        $this->get('/en')->assertOk();

        // Unrelated pages are unaffected.
        $this->get('/gioi-thieu')->assertOk();
        $this->get('/en/gioi-thieu-en')->assertOk();
        $this->assertNotNull($about);
    }

    public function test_static_front_page_redirects_and_reassignment_restores_the_old_slug(): void
    {
        $this->theme();
        $conventional = $this->conventionalHome();
        $static = $this->smPost('Trang tinh', true, [], 'page');
        $this->useStatic($static);

        $this->get('/trang-tinh')->assertStatus(301)->assertHeader('Location', '/');
        $this->get('/en/trang-tinh-en')->assertStatus(301)->assertHeader('Location', '/en');
        $this->get('/trang-chu')->assertOk(); // not the front page in static mode

        // Reassign back to the conventional page: the static slug serves again.
        app('cms.settings')->set('reading.homepage_display', 'latest_posts');
        $this->get('/trang-tinh')->assertOk();
        $this->get('/trang-chu')->assertOk(); // latest_posts: / renders posts, not trang-chu

        $this->useStatic($static);
        $this->get('/trang-tinh')->assertStatus(301);
        $this->assertNotNull($conventional);
    }

    public function test_no_redirect_when_the_front_page_is_not_provable(): void
    {
        $this->theme(home: true);
        $this->conventionalHome();
        app('cms.settings')->set('theme.'.self::THEME.'.homepage_layout', (string) json_encode(['sections' => [['type' => 'hero', 'data' => []]]]));
        $this->assertTrue(app('cms.homepage')->hasLayout());

        // Section layout: / renders sections, so trang-chu is an ordinary page.
        $this->get('/trang-chu')->assertOk();
    }

    public function test_an_unpublished_static_choice_does_not_redirect_it(): void
    {
        $this->theme();
        $draft = app('cms.content')->create([
            'type' => 'page', 'status' => 'draft', 'locale' => 'vi', 'title' => 'Nhap', 'slug' => 'nhap', 'content' => '<p>x</p>',
        ], \Tests\Support\ContentFixtures::context());
        $this->useStatic($draft);
        $this->conventionalHome();

        $this->get('/nhap')->assertNotFound();
        // home() falls back to the conventional page, which is then the one redirected.
        $this->get('/trang-chu')->assertStatus(301)->assertHeader('Location', '/');
    }

    public function test_safe_query_is_kept_and_credentials_are_dropped(): void
    {
        $this->theme();
        $this->conventionalHome();

        $this->get('/trang-chu?utm_source=news&page=2')->assertStatus(301)->assertHeader('Location', '/?page=2&utm_source=news'); // Symfony-normalized order
        $this->get('/trang-chu?token=abc&utm_source=x')->assertStatus(301)->assertHeader('Location', '/?utm_source=x');
        $this->get('/trang-chu?expires=1&signature=abc')->assertStatus(301)->assertHeader('Location', '/');
        $this->get('/en/trang-chu-en?Access_Token=z')->assertStatus(301)->assertHeader('Location', '/en');
    }

    public function test_prefixed_default_locale_targets_the_prefixed_home(): void
    {
        $this->theme();
        $this->conventionalHome();
        app('cms.settings')->set('language.prefix_default', true);

        $this->get('/vi/trang-chu')->assertStatus(301)->assertHeader('Location', '/vi');
        $this->get('/en/trang-chu-en')->assertStatus(301)->assertHeader('Location', '/en');
    }

    public function test_disabled_or_unknown_locales_and_preview_are_not_redirected(): void
    {
        $this->theme();
        $home = $this->conventionalHome();

        $this->get('/fr/trang-chu')->assertNotFound();

        // The preview renderer is a separate path and still renders the page.
        $response = app(\TheNguyen\CMS\Http\Controllers\FrontendController::class)->renderPreviewable($home, 'vi');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_canonical_and_sitemap_agree_on_the_home_url(): void
    {
        $this->theme();
        $this->conventionalHome();

        $html = (string) $this->get('/')->assertOk()->getContent();
        $this->assertSame(['http://localhost'], $this->chunkLocs('core-home-vi-1'));
        $this->assertSame('http://localhost', app('cms.seo')->canonical());
        $this->assertNotNull($html);
    }
}
