<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-RELEASE-BLOCKERS-R1-CLOSURE — owner policy A (brief §3):
 *
 *   "When the configured homepage has no translation in the requested enabled locale, render a
 *    localized unavailable-page message with HTTP 404 and noindex. Do not fall back to another
 *    language or redirect to another homepage."
 *
 * Scope: the Settings-configured static homepage (reading.homepage_display = static_page). The
 * fixture theme is theme-first: its errors/404 view prints a marker, so the 404 is proven to be the
 * theme's own error contract (FrontendErrorResponder), not a parallel framework.
 */
final class HomepagePolicyATest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'rbc_home_fixture';

    private const UNAVAILABLE_EN = 'Page not available in this language';

    private const UNAVAILABLE_VI = 'Trang không có bằng ngôn ngữ này';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();

        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives', 'errors'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        $head = '<meta name="robots" content="{{ app("cms.seo")->robots() }}">'
            .'<link rel="canonical" href="{{ app("cms.seo")->canonical() }}">'
            .'@foreach(app("cms.seo")->alternates() as $a)<link rel="alternate" hreflang="{{ $a["hreflang"] }}" href="{{ $a["href"] }}">@endforeach'."\n";
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', '<html lang="{{ current_locale() }}">'.$head.'<h1>{{ $title }}</h1><main>{!! $body !!}</main></html>');
        File::put($dir.'/views/pages/home.blade.php', '<html lang="{{ current_locale() }}">'.$head.'THEME-HOME</html>');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', '<html lang="{{ current_locale() }}">'.$head.'LATEST-POSTS</html>');
        File::put($dir.'/views/errors/404.blade.php', '<html lang="{{ current_locale() }}">'.$head.'THEME-404<h1>{{ $title }}</h1><p class="msg">{{ $message }}</p><a class="home" href="{{ $homeUrl }}">{{ $homeLabel }}</a></html>');
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'RBC', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    /**
     * A page with exactly the given translations.
     *
     * @param  array<string, array{0: string, 1: string}>  $translations  locale => [title, body]
     */
    private function page(array $translations, string $status = 'published'): Content
    {
        $page = null;
        foreach ($translations as $locale => [$title, $body]) {
            $data = ['locale' => $locale, 'title' => $title, 'slug' => Str::slug($title), 'content' => $body];
            $page = $page === null
                ? app('cms.content')->create(['type' => 'page', 'status' => $status] + $data, \Tests\Support\ContentFixtures::context())
                : app('cms.content')->update($page, $data, null, \Tests\Support\ContentFixtures::context());
        }

        return $page->fresh();
    }

    private function useAsHome(Content $page): Content
    {
        $s = app('cms.settings');
        $s->set('reading.homepage_display', 'static_page', 'string');
        $s->set('reading.homepage_page_id', $page->id, 'integer');
        $s->clearCache();

        return $page;
    }

    private function robots(string $html): string
    {
        preg_match('/<meta name="robots" content="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }

    private function canonical(string $html): string
    {
        preg_match('/<link rel="canonical" href="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }

    /** @return array<string, string> hreflang => href */
    private function hreflang(string $html): array
    {
        preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/', $html, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [$x[1] => $x[2]])->all();
    }

    private function assertUnavailable(string $url, string $foreignText): string
    {
        $res = $this->get($url);
        $res->assertNotFound();
        $res->assertHeaderMissing('Location');
        $html = (string) $res->getContent();
        $this->assertStringContainsString('THEME-404', $html, 'theme-first 404 view');
        $this->assertStringContainsString('noindex', $this->robots($html).' '.(string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString($foreignText, $html, 'no other-language content');

        return $html;
    }

    // ── brief §3.5 cases ──────────────────────────────────────────────────────

    /** Case 1. */
    public function test_vi_only_home_is_200_in_vi_and_localized_404_in_en(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('VI-BODY', $html);
        $this->assertStringNotContainsString('noindex', $this->robots($html));

        $en = $this->assertUnavailable('/en', 'Trang chu VI');
        $this->assertStringContainsString('lang="en"', $en);
        $this->assertStringContainsString('<h1>'.self::UNAVAILABLE_EN.'</h1>', $en);
        $this->assertStringContainsString('class="home" href="'.url('/').'"', $en, 'safe link to the homepage that exists');
    }

    /** Case 2 — symmetric: the rule is not hardcoded to one language. */
    public function test_en_only_home_is_200_in_en_and_localized_404_in_vi(): void
    {
        $this->useAsHome($this->page(['en' => ['Home EN', '<p>EN-BODY</p>']]));

        $this->assertStringContainsString('EN-BODY', $this->get('/en')->assertOk()->getContent());

        $vi = $this->assertUnavailable('/', 'Home EN');
        $this->assertStringContainsString('lang="vi"', $vi);
        $this->assertStringContainsString('<h1>'.self::UNAVAILABLE_VI.'</h1>', $vi);
        $this->assertStringContainsString('class="home" href="'.url('/en').'"', $vi);
    }

    /** Case 3. */
    public function test_home_translated_in_both_is_200_and_indexable_with_reciprocal_hreflang(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>'], 'en' => ['Home EN', '<p>EN-BODY</p>']]));

        foreach (['/' => 'VI-BODY', '/en' => 'EN-BODY'] as $url => $body) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($body, $html);
            $this->assertStringNotContainsString('noindex', $this->robots($html), $url);
            $this->assertSame(url($url), $this->canonical($html), $url);
            $alternates = $this->hreflang($html);
            $this->assertArrayHasKey('vi', $alternates, $url);
            $this->assertArrayHasKey('en', $alternates, $url);
        }
    }

    /** Case 4. */
    public function test_home_page_with_neither_language_is_404_in_both(): void
    {
        $page = $this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]);
        $page->translations()->delete();
        $this->useAsHome($page->fresh());

        $this->assertUnavailable('/', 'Trang chu VI');
        $this->assertUnavailable('/en', 'Trang chu VI');
    }

    /** Case 5 — the latest-posts homepage never becomes a 404. */
    public function test_latest_posts_home_is_never_a_404(): void
    {
        $this->smPost('Bai viet chi VI', false);
        $s = app('cms.settings');
        $s->set('reading.homepage_display', 'latest_posts', 'string');
        $s->clearCache();

        $this->get('/')->assertOk();
        $this->get('/en')->assertOk();
    }

    /** Case 6 — a disabled locale is not an enabled locale: no policy-A page for it. */
    public function test_disabled_locale_is_not_given_the_unavailable_page(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));
        app('cms.language')->create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => false, 'sort_order' => 3]);

        $res = $this->get('/fr');
        $this->assertNotSame(500, $res->getStatusCode());
        $this->assertStringNotContainsString(self::UNAVAILABLE_EN, (string) $res->getContent());
    }

    /** Case 7. */
    public function test_unknown_locale_is_not_given_the_unavailable_page(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));

        $res = $this->get('/zz');
        $this->assertNotSame(500, $res->getStatusCode());
        $this->assertStringNotContainsString(self::UNAVAILABLE_EN, (string) $res->getContent());
    }

    /** Case 8 — publication is content-level; an unpublished configured page is served in no language. */
    public function test_unpublished_configured_home_is_not_served_in_any_language(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>'], 'en' => ['Home EN', '<p>EN-BODY</p>']], 'draft'));

        foreach (['/', '/en'] as $url) {
            $res = $this->get($url);
            $this->assertNotSame(500, $res->getStatusCode(), $url);
            $html = (string) $res->getContent();
            $this->assertStringNotContainsString('VI-BODY', $html, $url);
            $this->assertStringNotContainsString('EN-BODY', $html, $url);
        }
    }

    /** Case 9 — an existing but empty translation is a valid translation. */
    public function test_empty_but_valid_translation_is_200(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>'], 'en' => ['Home EN', '']]));

        $html = $this->get('/en')->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Home EN</h1>', $html);
        $this->assertStringNotContainsString('VI-BODY', $html);
    }

    /** Case 12. */
    public function test_404_becomes_200_once_the_translation_is_added(): void
    {
        $page = $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));
        $this->get('/en')->assertNotFound();

        app('cms.content')->update($page, ['locale' => 'en', 'title' => 'Home EN', 'slug' => 'home-en', 'content' => '<p>EN-BODY</p>'],
            null, \Tests\Support\ContentFixtures::context());

        $this->assertStringContainsString('EN-BODY', $this->get('/en')->assertOk()->getContent());
        $this->assertArrayHasKey('en', $this->hreflang($this->get('/')->getContent()));
        $this->get('/sitemaps/core-home-en-1.xml')->assertOk();
    }

    /** Case 13. */
    public function test_200_becomes_404_once_the_translation_is_removed(): void
    {
        $page = $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>'], 'en' => ['Home EN', '<p>EN-BODY</p>']]));
        $this->get('/en')->assertOk();

        $page->translations()->where('locale', 'en')->delete();

        $this->assertUnavailable('/en', 'Trang chu VI');
        $this->assertArrayNotHasKey('en', $this->hreflang($this->get('/')->getContent()));
        $this->get('/sitemaps/core-home-en-1.xml')->assertNotFound();
    }

    /** Case 14. */
    public function test_untranslated_locale_is_excluded_from_hreflang_and_sitemap(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));

        $this->assertArrayNotHasKey('en', $this->hreflang($this->get('/')->getContent()));
        $this->get('/sitemaps/core-home-vi-1.xml')->assertOk();
        $this->get('/sitemaps/core-home-en-1.xml')->assertNotFound();
        $this->assertStringNotContainsString('core-home-en-1.xml', (string) $this->get('/sitemap.xml')->getContent());

        $en = (string) $this->get('/en')->getContent();
        $this->assertArrayNotHasKey('en', $this->hreflang($en), 'the 404 never advertises itself');
        $this->assertNotSame(url('/en'), $this->canonical($en), 'no canonical to nonexistent translated content');
    }

    /** Case 15 — theme-first: with no theme error view, Core's own error view is the fallback. */
    public function test_without_a_theme_404_view_core_error_view_renders_the_same_contract(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));
        File::delete(base_path('themes/'.self::THEME.'/views/errors/404.blade.php'));

        $res = $this->get('/en')->assertNotFound();
        $html = (string) $res->getContent();
        $this->assertStringNotContainsString('THEME-404', $html);
        $this->assertStringContainsString(self::UNAVAILABLE_EN, $html);
        $this->assertStringContainsString('noindex', $html.' '.(string) $res->headers->get('X-Robots-Tag'));
        $this->assertStringNotContainsString('VI-BODY', $html);
    }

    /** Rendering never writes or copies a translation, nor changes settings. */
    public function test_rendering_writes_nothing(): void
    {
        $page = $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));
        $before = $page->translations()->orderBy('locale')->get(['locale', 'title', 'content'])->toArray();
        $settings = [settings('reading.homepage_display'), settings('reading.homepage_page_id')];

        $this->get('/en');
        $this->get('/');

        $this->assertSame($before, $page->fresh()->translations()->orderBy('locale')->get(['locale', 'title', 'content'])->toArray());
        $this->assertSame($settings, [settings('reading.homepage_display'), settings('reading.homepage_page_id')]);
    }

    /** Ordinary and missing pages keep their plain 404 (no policy-A message). */
    public function test_ordinary_page_and_missing_page_are_unchanged(): void
    {
        $this->useAsHome($this->page(['vi' => ['Trang chu VI', '<p>VI-BODY</p>']]));
        $this->page(['vi' => ['Gioi thieu', '<p>ABOUT</p>']]);

        $this->get('/gioi-thieu')->assertOk();
        $this->get('/khong-ton-tai')->assertNotFound();
        $res = $this->get('/en/gioi-thieu')->assertNotFound();
        $this->assertStringNotContainsString(self::UNAVAILABLE_EN, (string) $res->getContent());
    }
}
