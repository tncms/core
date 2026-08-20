<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\PreviewUrlService;
use Tests\TestCase;

/**
 * CORE-L10N.1B (P3.1) — the ONE locale-aware Preview URL producer.
 *
 * Proves that published+translated records preview through the public localized
 * URL (built by the Localization Platform, unsigned), that drafts/untranslated
 * records preview through a signed URL carrying the locale, that the locale is
 * explicit (never inferred from the admin app locale), that '#' is never
 * produced, and that the signed preview route stays secure.
 */
final class PreviewUrlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    private function service(): PreviewUrlService
    {
        return app('cms.preview_url');
    }

    private function bilingualPublishedPage(): Content
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'trang-chu',
        ]);
    }

    private function draftBilingualPost(): Content
    {
        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'draft', 'locale' => 'en',
            'title' => 'Draft', 'slug' => 'draft', 'content' => '<p>en draft</p>',
        ]);

        return app('cms.content')->update($post, [
            'type' => 'post', 'locale' => 'vi', 'title' => 'Bản nháp', 'slug' => 'ban-nhap', 'content' => '<p>vi draft</p>',
        ]);
    }

    // ── published → localized public URL via the platform ──────────────────────

    public function test_published_page_preview_is_the_public_localized_url_for_the_editing_locale(): void
    {
        $page = $this->bilingualPublishedPage();

        $vi = $this->service()->forContent($page, 'vi');
        $this->assertIsString($vi);
        $this->assertStringContainsString('/vi/trang-chu', $vi);
        $this->assertStringNotContainsString('cms/preview', $vi); // real public page, not signed
        $this->assertStringNotContainsString('signature=', $vi);

        $en = $this->service()->forContent($page, 'en');
        $this->assertStringContainsString('/home', (string) $en);
        $this->assertStringNotContainsString('/vi/', (string) $en);
    }

    public function test_editing_locale_is_explicit_and_independent_of_the_admin_app_locale(): void
    {
        $page = $this->bilingualPublishedPage();

        // Admin app + current locale is en; asking for vi must still return vi.
        app()->setLocale('en');
        app('cms.language')->setCurrent('en');

        $vi = $this->service()->forContent($page, 'vi');
        $this->assertStringContainsString('/vi/trang-chu', (string) $vi);
    }

    // ── draft → signed preview URL carrying the locale ─────────────────────────

    public function test_draft_preview_is_a_signed_url_carrying_the_locale(): void
    {
        $post = $this->draftBilingualPost();

        $url = $this->service()->forContent($post, 'vi');
        $this->assertIsString($url);
        $this->assertStringContainsString('cms/preview/cms.post/'.$post->id, $url);
        $this->assertStringContainsString('locale=vi', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_draft_preview_url_renders_behind_the_signature(): void
    {
        $post = $this->draftBilingualPost();

        $response = $this->get((string) $this->service()->forContent($post, 'vi'));

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ── published-without-translation falls back to signed preview (never '#') ─

    public function test_published_without_translation_falls_back_to_signed_preview_not_hash(): void
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'English only', 'slug' => 'english-only', 'content' => '<p>x</p>',
        ]);

        $url = $this->service()->forContent($page, 'vi');
        $this->assertIsString($url);
        $this->assertNotSame('#', $url);
        $this->assertStringContainsString('cms/preview/cms.page/'.$page->id, $url);
        $this->assertStringContainsString('locale=vi', $url);
    }

    // ── '#' is never a return value ────────────────────────────────────────────

    public function test_preview_url_is_never_the_hash_sentinel(): void
    {
        $page = $this->bilingualPublishedPage();
        $draft = $this->draftBilingualPost();

        foreach (['en', 'vi'] as $locale) {
            $this->assertNotSame('#', $this->service()->forContent($page, $locale));
            $this->assertNotSame('#', $this->service()->forContent($draft, $locale));
        }
    }

    public function test_unknown_locale_returns_null_not_hash(): void
    {
        $page = $this->bilingualPublishedPage();

        $this->assertNull($this->service()->forContent($page, 'zz'));
    }

    // ── one producer covers published + draft ──────────────────────────────────

    public function test_one_producer_covers_published_and_draft(): void
    {
        $published = $this->bilingualPublishedPage();
        $draft = $this->draftBilingualPost();

        $pub = (string) $this->service()->forContent($published, 'vi');
        $dr = (string) $this->service()->forContent($draft, 'vi');

        $this->assertStringNotContainsString('signature=', $pub); // published → unsigned public
        $this->assertStringContainsString('signature=', $dr);     // draft → signed preview
    }

    // ── security ───────────────────────────────────────────────────────────────

    public function test_unsigned_preview_request_is_forbidden(): void
    {
        $post = $this->draftBilingualPost();

        $this->get('/cms/preview/cms.post/'.$post->id.'?locale=vi')->assertForbidden();
    }

    public function test_tampered_signature_is_forbidden(): void
    {
        $post = $this->draftBilingualPost();
        $url = (string) $this->service()->forContent($post, 'vi');

        $tampered = preg_replace('/signature=([0-9a-f]+)/', 'signature=0${1}', $url);

        $this->get((string) $tampered)->assertForbidden();
    }
}
