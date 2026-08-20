<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\CurrentResourcePublisher;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\LocalizedContentUrlService;
use Tests\TestCase;

/**
 * CORE-FREEZE-4 (WORK D/E/F/J) — the localized SEO runtime.
 *
 * SEO is a pure CONSUMER of the frozen Localization Platform. These tests prove:
 *   - canonical is locale-correct and query-free (no ?locale=) — WORK E;
 *   - og:url mirrors canonical — WORK D/E;
 *   - hreflang alternates originate byte-for-byte from the platform's
 *     {@see LocalizedContentUrlService}, never a private/manual URL builder — WORK F;
 *   - SEO renders the ACTIVE locale's metadata (never another locale's) — WORK C/D;
 *   - SEO never writes the current-resource authority and routes its hreflang
 *     through the platform seams — WORK J, RECONCILED.
 *
 * WORK J reconciliation (Conflict 1): the literal "SeoManager must not import
 * ContentTranslation" cannot hold — SeoManager is a context object the render
 * boundary feeds (Content + locale) and it legitimately reads translations for
 * meta values. The FROZEN invariant this guards instead is that SEO is not a
 * writer of the current resource and that its locale-derived URLs (hreflang)
 * flow through the platform, not a second URL authority. SeoManager itself is
 * NOT rewritten (HARD RULE: no architecture redesign).
 */
final class LocalizedSeoRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $core;

    protected function setUp(): void
    {
        parent::setUp();

        $this->core = base_path('packages/thenguyen/cms-core/src');

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);

        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
    }

    // ── WORK E: canonical is locale-correct and query-free ──────────────────────

    public function test_home_canonical_is_locale_correct_and_query_free(): void
    {
        app('cms.language')->setCurrent('en');
        $en = app('cms.seo')->forHome()->canonical();

        $this->assertSame(url('/'), $en);
        $this->assertStringNotContainsString('?', $en);

        app('cms.language')->setCurrent('vi');
        $vi = app('cms.seo')->forHome()->canonical();

        $this->assertStringEndsWith('/vi', $vi);
        $this->assertStringNotContainsString('?', $vi);
    }

    public function test_content_canonical_is_the_prefixed_request_url_without_a_locale_query(): void
    {
        $this->bilingualPage();

        $en = $this->get('/home');
        $en->assertOk();
        $en->assertSee('rel="canonical" href="'.url('/home').'"', false);
        $en->assertDontSee('?locale', false);

        $vi = $this->get('/vi/trang-chu');
        $vi->assertOk();
        $vi->assertSee('rel="canonical" href="'.url('/vi/trang-chu').'"', false);
        $vi->assertDontSee('?locale', false);
    }

    // ── WORK D/E: og:url mirrors canonical ──────────────────────────────────────

    public function test_open_graph_url_equals_canonical(): void
    {
        $page = $this->bilingualPage();

        app('cms.language')->setCurrent('vi');
        app('cms.seo')->forContent($page, 'vi');

        $seo = app('cms.seo')->current();
        $this->assertSame($seo['canonical'], $seo['og_url']);
    }

    // ── WORK F: hreflang originates from the localization platform ───────────────

    public function test_hreflang_alternates_originate_from_the_localization_platform(): void
    {
        $page = $this->bilingualPage();

        app('cms.language')->setCurrent('vi');
        app('cms.seo')->forContent($page, 'vi');
        app(CurrentResourcePublisher::class)->publishContent($page);

        $alternates = collect(app('cms.seo')->alternates())->keyBy('hreflang');
        $urls = app(LocalizedContentUrlService::class);

        // Byte-for-byte identical to the ONE canonical resource-to-URL service —
        // proving SEO builds no alternate URL of its own.
        $this->assertSame($urls->absoluteForResource($page, 'en'), $alternates['en']['href']);
        $this->assertSame($urls->absoluteForResource($page, 'vi'), $alternates['vi']['href']);
        $this->assertArrayHasKey('x-default', $alternates->all());
    }

    // ── WORK C/D: SEO renders the active locale's metadata, never another's ──────

    public function test_seo_renders_the_active_locale_metadata(): void
    {
        $page = $this->bilingualPage()->fresh(['translations']);

        app('cms.seo')->forContent($page, 'en');
        $this->assertSame('Home', app('cms.seo')->title());

        app('cms.seo')->forContent($page, 'vi');
        $this->assertSame('Trang chủ', app('cms.seo')->title());
    }

    // ── WORK J (reconciled): SEO is a consumer, not a writer ─────────────────────

    public function test_seo_never_writes_the_resource_and_sources_hreflang_from_the_platform(): void
    {
        $seo = php_strip_whitespace($this->core.'/Services/SeoManager.php');

        // SEO never touches the current-resource WRITER API (it only reads via the
        // context factory in alternates()).
        foreach (['CurrentResourcePublisher', 'CurrentResourceContext', 'publishHome', 'publishContent', 'publishTerm'] as $writer) {
            $this->assertStringNotContainsString($writer, $seo, "SeoManager must not reference the current-resource writer ({$writer}).");
        }

        // SEO's locale-derived URLs (hreflang) flow through the frozen platform
        // seams — not a private page/post/term URL builder.
        foreach (['cms.localization.context_factory', 'cms.localization.switch_targets', 'cms.localization.url_generator'] as $seam) {
            $this->assertStringContainsString($seam, $seo, "SeoManager hreflang must flow through the platform seam ({$seam}).");
        }
    }

    private function bilingualPage(): Content
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi',
            'title' => 'Trang chủ', 'slug' => 'trang-chu', 'content' => '<p>vi</p>',
        ]);
    }
}
