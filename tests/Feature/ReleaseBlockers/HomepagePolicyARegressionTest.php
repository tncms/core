<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Http\Exceptions\TranslationUnavailableHttpException;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-RELEASE-BLOCKERS-R1-CLOSURE — regression guards around policy A (brief §3.4, §8, §11):
 * exact headers, cache semantics, no open redirect, no leakage of a draft or other-language title,
 * query cost of `/`, and the exception remaining an ordinary NotFoundHttpException for consumers.
 */
final class HomepagePolicyARegressionTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'rbc_home_regression';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();

        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives', 'errors'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', '<h1>{{ $title }}</h1><main>{!! $body !!}</main>');
        File::put($dir.'/views/pages/home.blade.php', 'THEME-HOME');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/views/errors/404.blade.php', 'THEME-404 <a class="home" href="{{ $homeUrl }}">{{ $homeLabel }}</a>');
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'RBC R', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function viOnlyHome(string $title = 'Trang chu VI'): Content
    {
        $page = app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'vi', 'title' => $title,
            'slug' => Str::slug($title), 'content' => '<p>VI-BODY</p>'], \Tests\Support\ContentFixtures::context());
        $s = app('cms.settings');
        $s->set('reading.homepage_display', 'static_page', 'string');
        $s->set('reading.homepage_page_id', $page->id, 'integer');
        $s->clearCache();

        return $page;
    }

    public function test_headers_are_exact_404_noindex_never_publicly_cacheable(): void
    {
        $this->viOnlyHome();

        $res = $this->get('/en')->assertNotFound();
        $this->assertSame('noindex', $res->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('public', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', (string) $res->headers->get('Cache-Control'), 'a 404 is never browser-cached');

        $ok = $this->get('/')->assertOk();
        $this->assertNull($ok->headers->get('X-Robots-Tag'), 'the translated home is not noindexed by policy A');
    }

    public function test_with_html_caching_enabled_the_404_is_still_not_cached(): void
    {
        $this->viOnlyHome();
        $s = app('cms.settings');
        $s->set('optimize.response.enabled', true, 'boolean');
        $s->set('optimize.response.public_html_ttl', 300, 'integer');
        $s->clearCache();

        // Control: the setting is live — the translated home gets the browser max-age.
        $this->assertStringContainsString('max-age=300', (string) $this->get('/')->assertOk()->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', (string) $this->get('/en')->assertNotFound()->headers->get('Cache-Control'));
    }

    public function test_query_string_and_host_input_cannot_redirect_or_inject_the_home_link(): void
    {
        $this->viOnlyHome();

        $res = $this->get('/en?redirect=https://evil.example/&url=//evil.example', ['X-Forwarded-Host' => 'evil.example'])->assertNotFound();
        $res->assertHeaderMissing('Location');
        $html = (string) $res->getContent();
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('class="home" href="'.url('/').'"', $html);
    }

    public function test_an_unpublished_page_title_never_leaks_through_the_unavailable_page(): void
    {
        $this->viOnlyHome('Trang chu cong khai');
        app('cms.content')->create(['type' => 'page', 'status' => 'draft', 'locale' => 'en', 'title' => 'SECRET-DRAFT-TITLE',
            'slug' => 'secret-draft', 'content' => '<p>secret</p>'], \Tests\Support\ContentFixtures::context());

        $html = (string) $this->get('/en')->assertNotFound()->getContent();
        $this->assertStringNotContainsString('SECRET-DRAFT-TITLE', $html);
        $this->assertStringNotContainsString('Trang chu cong khai', $html);
    }

    /** Security review finding 1: the sitemap's home entry must not advertise the 404 locale either. */
    public function test_the_sitemap_home_entry_has_no_alternate_for_the_unavailable_locale(): void
    {
        $this->viOnlyHome();

        $xml = $this->get('/sitemaps/core-home-vi-1.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('<loc>http://localhost</loc>', $xml);
        $this->assertStringNotContainsString('hreflang="en"', $xml);
        $this->assertStringNotContainsString('http://localhost/en', $xml);
    }

    public function test_the_sitemap_home_entry_keeps_every_translated_alternate(): void
    {
        $page = $this->viOnlyHome();
        app('cms.content')->update($page, ['locale' => 'en', 'title' => 'Home EN', 'slug' => 'home-en', 'content' => '<p>EN</p>'],
            null, \Tests\Support\ContentFixtures::context());

        foreach (['vi', 'en'] as $locale) {
            $xml = $this->get("/sitemaps/core-home-{$locale}-1.xml")->assertOk()->streamedContent();
            $this->assertStringContainsString('hreflang="vi"', $xml, $locale);
            $this->assertStringContainsString('hreflang="en"', $xml, $locale);
            $this->assertStringContainsString('hreflang="x-default"', $xml, $locale);
        }
    }

    public function test_the_exception_is_an_ordinary_not_found_exception_for_consumers(): void
    {
        $e = new TranslationUnavailableHttpException('en', 'http://localhost');

        $this->assertInstanceOf(NotFoundHttpException::class, $e);
        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame(['X-Robots-Tag' => 'noindex'], $e->getHeaders());
    }

    public function test_home_query_cost_is_bounded(): void
    {
        $this->viOnlyHome();
        $this->get('/');
        $this->get('/en');

        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Recorded in doc 11: the policy gate and the memoized hreflang check add no per-locale queries.
        $this->assertLessThanOrEqual(60, $count('/'));
        $this->assertLessThanOrEqual(60, $count('/en'));
    }
}
