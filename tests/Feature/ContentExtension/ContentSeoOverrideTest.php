<?php

declare(strict_types=1);

namespace Tests\Feature\ContentExtension;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\RobotsRestriction;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-CONTENT-EXT-R1 M3 — owner-scoped SEO override: precedence, Core-owned
 * canonical/hreflang/og:url/og:type, sanitation, restrict-only robots,
 * registration ownership, failure isolation, multilingual.
 */
final class ContentSeoOverrideTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCxLanguages(bilingual: true);
        $this->useCxPlugins('cx-alpha', 'cx-beta');
    }

    private function path(Content $content, string $locale = 'en'): string
    {
        return parse_url(content_url($content, $locale), PHP_URL_PATH);
    }

    private function bilingualOwnedPost(string $title): Content
    {
        $post = $this->ownedPost('cx-alpha', $title, ['featured_image' => '/media/core.jpg']);

        return $this->cxPlugin('cx-alpha')->update($post, [
            'type' => 'post', 'locale' => 'vi', 'title' => $title.' VI', 'slug' => \Illuminate\Support\Str::slug($title).'-vi', 'content' => '<p>vi</p>',
        ]);
    }

    public function test_owner_override_wins_and_core_keeps_canonical_hreflang_og_url_and_og_type(): void
    {
        $provider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(
            title: 'Owner Title',
            description: 'Owner description',
            ogTitle: 'Owner OG Title',
            ogDescription: 'Owner OG description',
            ogImage: 'https://cdn.example.test/og.jpg',
        ));
        $this->assertTrue($this->cxPlugin('cx-alpha')->registerSeo($provider));
        $post = $this->bilingualOwnedPost('Seo Managed');
        $path = $this->path($post);

        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('<title>Owner Title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Owner description">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Owner OG Title">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Owner OG description">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.test/og.jpg">', $html);
        $this->assertStringContainsString('<meta name="twitter:title" content="Owner OG Title">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url($path).'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.url($path).'">', $html);
        $fresh = $post->fresh('translations');
        $this->assertStringContainsString('hreflang="vi" href="'.url(content_url($fresh, 'vi')).'"', $html);
        $this->assertStringContainsString('hreflang="en" href="'.url(content_url($fresh, 'en')).'"', $html);
        $this->assertSame([[$post->id, 'post', 'en']], $provider->calls);
    }

    public function test_no_provider_keeps_core_metadata_and_partial_override_falls_back_per_field(): void
    {
        $post = $this->bilingualOwnedPost('Seo Plain');
        $html = $this->get($this->path($post))->getContent();
        $this->assertStringContainsString('<title>Seo Plain</title>', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/media/core.jpg').'">', $html);

        $this->cxPlugin('cx-alpha')->registerSeo($this->cxSeoProvider('cx-alpha', new ContentSeoOverride(description: 'Only Description')));
        $html = $this->get($this->path($post))->getContent();
        $this->assertStringContainsString('<title>Seo Plain</title>', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Only Description">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Seo Plain">', $html);
    }

    public function test_override_is_per_locale(): void
    {
        $this->cxPlugin('cx-alpha')->registerSeo($this->cxSeoProvider('cx-alpha', null, 'locale'));
        $post = $this->bilingualOwnedPost('Seo Locales');

        $this->assertStringContainsString('<title>Owner title en</title>', $this->get($this->path($post, 'en'))->getContent());
        $this->assertStringContainsString('<title>Owner title vi</title>', $this->get($this->path($post, 'vi'))->getContent());
    }

    public function test_invalid_fields_are_dropped_individually(): void
    {
        $this->cxPlugin('cx-alpha')->registerSeo($this->cxSeoProvider('cx-alpha', new ContentSeoOverride(
            title: '<script>alert(1)</script>Clean &lt;b&gt;Title',
            description: str_repeat('x', 501),
            ogTitle: "Line\x07break",
            ogImage: 'javascript:alert(1)',
        )));
        $post = $this->bilingualOwnedPost('Seo Invalid');

        $html = $this->get($this->path($post))->getContent();

        $this->assertStringContainsString('<title>alert(1)Clean Title</title>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString(str_repeat('x', 501), $html);
        $this->assertStringContainsString('<meta property="og:title" content="Line break">', $html);
        // CORE-38-TOPLIST-SEAMS-R1 (ADR-4): an invalid supplied image is suppression, not a fallback to the featured image.
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString(url('/media/core.jpg'), $html);
        $this->assertStringNotContainsString('javascript:', $html);

        foreach (['http://insecure.test/x.jpg', '//cdn.test/x.jpg', 'https://user:pw@cdn.test/x.jpg', 'https:///nohost', '/ok/"quote.jpg'] as $bad) {
            $this->assertNull((new ContentSeoOverride(ogImage: $bad))->sanitized()->ogImage, $bad);
        }
        $this->assertSame('/media/a.jpg', (new ContentSeoOverride(ogImage: '/media/a.jpg'))->sanitized()->ogImage);
    }

    public function test_robots_can_only_become_more_restrictive_and_site_noindex_wins(): void
    {
        $post = $this->bilingualOwnedPost('Seo Robots');
        $this->cxPlugin('cx-alpha')->registerSeo($this->cxSeoProvider('cx-alpha', new ContentSeoOverride(robots: RobotsRestriction::NoFollow)));

        $this->assertStringContainsString('<meta name="robots" content="index,nofollow">', $this->get($this->path($post))->getContent());

        // A noindex site default can never be widened back to index.
        app('cms.settings')->set('seo.robots_default', 'noindex,follow,max-image-preview:large');
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow,max-image-preview:large">', $this->get($this->path($post))->getContent());

        app('cms.settings')->set('seo.noindex_site', true);
        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $this->get($this->path($post))->getContent());
    }

    /** CORE-38-TOPLIST-SEAMS-R1 (ADR-3): a throw no longer falls back to (possibly drifted) Core metadata. */
    public function test_provider_exception_renders_a_neutral_noindex_head_and_logs_class_only(): void
    {
        Log::spy();
        $this->cxPlugin('cx-alpha')->registerSeo($this->cxSeoProvider('cx-alpha', null, 'throw'));
        $post = $this->bilingualOwnedPost('Seo Throws');

        $response = $this->get($this->path($post));

        $response->assertOk();
        $head = substr((string) $response->getContent(), 0, (int) strpos((string) $response->getContent(), '</head>'));
        $this->assertStringNotContainsString('Seo Throws', $head);
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex[^"]*">/', $head);
        $this->assertStringNotContainsString('og:image', $head);
        $this->assertStringNotContainsString('hunter2', $response->getContent());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'TN CMS content SEO provider failed'
            && $context['exception'] === \RuntimeException::class
            && ! str_contains(json_encode($context), 'hunter2'))->once();
    }

    public function test_registration_is_owner_bound_and_unrelated_plugins_cannot_override(): void
    {
        $alphaProvider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(title: 'Alpha Title'));
        $betaProvider = $this->cxSeoProvider('cx-beta', new ContentSeoOverride(title: 'Beta Hijack'));

        $this->assertFalse($this->cxPlugin('cx-beta')->registerSeo($alphaProvider), 'cannot register another plugin\'s provider');
        $this->assertFalse(app('cms.content_seo')->register($alphaProvider), 'Core/test code cannot register');
        $this->assertTrue($this->cxPlugin('cx-beta')->registerSeo($betaProvider));
        $this->assertFalse($this->cxPlugin('cx-beta')->registerSeo($this->cxSeoProvider('cx-beta')), 'one provider per plugin');

        $post = $this->bilingualOwnedPost('Seo Alpha Owned');
        $html = $this->get($this->path($post))->getContent();

        $this->assertStringContainsString('<title>Seo Alpha Owned</title>', $html);
        $this->assertStringNotContainsString('Beta Hijack', $html);
        $this->assertSame([], $betaProvider->calls);
    }

    public function test_inactive_owner_under_core_render_uses_core_metadata(): void
    {
        $provider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(title: 'Stale Owner Title'));
        $this->cxPlugin('cx-alpha')->registerSeo($provider);
        $post = $this->bilingualOwnedPost('Seo Inactive');
        $this->cxPlugin('cx-alpha')->fallback($post->id, \TheNguyen\CMS\Content\Extension\ContentFallbackPolicy::CoreRender);

        app('cms.extension')->deactivatePlugin('cx-alpha');
        app('cms.extension')->flushRegistry();

        $html = $this->get($this->path($post))->assertOk()->getContent();
        $this->assertStringContainsString('<title>Seo Inactive</title>', $html);
        $this->assertSame([], $provider->calls);
    }

    public function test_core_content_ignores_providers_and_override_cannot_leak_across_contexts(): void
    {
        $provider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(title: 'Leaky Title'));
        $this->cxPlugin('cx-alpha')->registerSeo($provider);
        $core = $this->corePost('Seo Core Article');
        $owned = $this->bilingualOwnedPost('Seo Owned Article');

        $this->get($this->path($owned))->assertSee('<title>Leaky Title</title>', false);
        $html = $this->get($this->path($core))->getContent();

        $this->assertStringContainsString('<title>Seo Core Article</title>', $html);
        $this->assertCount(1, $provider->calls);

        // Singleton SeoManager: the next context never carries the override.
        $seo = app('cms.seo');
        $seo->forContent($owned, 'en')->applyContentOverride(new ContentSeoOverride(title: 'X'));
        $this->assertSame('X', $seo->title());
        $seo->forHome();
        $this->assertNotSame('X', $seo->title());
        $seo->forCustom('Custom', '')->applyContentOverride(new ContentSeoOverride(title: 'Y'));
        $this->assertSame('Custom', $seo->title());
    }
}
