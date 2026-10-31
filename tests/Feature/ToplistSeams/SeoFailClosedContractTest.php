<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentSeoOverride;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate D1/D2 (H-3a, H-3b): an owner SEO failure
 * yields a neutral, noindex head (never editable Core metadata that may have
 * drifted), and an owner can explicitly suppress the social image so Core
 * never substitutes the featured or site image.
 */
final class SeoFailClosedContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    /** @var class-string */
    private string $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
        settings()->set('site.name', 'Seams Site');
        settings()->set('seo.default_og_image', '/media/site-default.jpg');
        $this->sxPlugin('sx-owner');
        $this->provider = $this->sxProvider('sx-owner', 'SeoOnly');
    }

    private function headOf(string $path): string
    {
        $html = (string) $this->get($path)->assertOk()->getContent();

        return substr($html, 0, (int) strpos($html, '</head>'));
    }

    private function drifted(string $title): \TheNguyen\CMS\Models\Content
    {
        return $this->sxOwnedPost('sx-owner', $title, ['featured_image' => '/media/core-featured.jpg', 'excerpt' => 'DRIFTED core excerpt']);
    }

    // ── D1: provider exception ───────────────────────────────────────────────

    public function test_provider_exception_renders_a_neutral_noindex_head(): void
    {
        Log::spy();
        ($this->provider)::$seo = 'throw';
        $post = $this->drifted('DRIFTED Core Title');

        $head = $this->headOf($this->sxPath($post));

        $this->assertStringNotContainsString('DRIFTED', $head, 'no editable Core metadata in an unsafe state');
        $this->assertStringNotContainsString('hunter2', $head);
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex[^"]*">/', $head);
        $this->assertStringNotContainsString('og:image', $head);
        $this->assertStringNotContainsString('twitter:image', $head);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $head);
        $this->assertStringContainsString('<link rel="canonical"', $head, 'Core still owns canonical');
        Log::shouldHaveReceived('warning')->withArgs(static fn ($message, $context) => $message === 'TN CMS content SEO provider failed'
            && $context['exception'] === \RuntimeException::class
            && ! str_contains((string) json_encode($context), 'hunter2'))->once();
    }

    public function test_null_override_still_means_keep_core_metadata(): void
    {
        $post = $this->drifted('Owner Accepts Core');

        $head = $this->headOf($this->sxPath($post));

        $this->assertStringContainsString('<title>Owner Accepts Core</title>', $head);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/media/core-featured.jpg').'">', $head);
        $this->assertStringNotContainsString('noindex', $head);
    }

    // ── D2: explicit og:image suppression ───────────────────────────────────

    public function test_explicit_suppression_removes_every_social_image(): void
    {
        ($this->provider)::$override = new ContentSeoOverride(title: 'Approved Title', suppressOgImage: true);
        $post = $this->drifted('Suppressed Image');

        $head = $this->headOf($this->sxPath($post));

        $this->assertStringContainsString('<title>Approved Title</title>', $head);
        $this->assertStringNotContainsString('og:image', $head);
        $this->assertStringNotContainsString('twitter:image', $head);
        $this->assertStringNotContainsString('core-featured', $head);
        $this->assertStringNotContainsString('site-default', $head);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $head);
    }

    public function test_empty_or_invalid_supplied_image_is_suppression_not_permission(): void
    {
        $post = $this->drifted('Bad Image');

        foreach (['', '   ', 'javascript:alert(1)', 'http://insecure.test/x.jpg'] as $bad) {
            ($this->provider)::$override = new ContentSeoOverride(ogImage: $bad);
            $head = $this->headOf($this->sxPath($post));

            $this->assertStringNotContainsString('og:image', $head, var_export($bad, true));
            $this->assertStringNotContainsString('core-featured', $head, var_export($bad, true));
        }
    }

    public function test_approved_image_wins_and_suppression_beats_a_supplied_image(): void
    {
        $post = $this->drifted('Approved Image');

        ($this->provider)::$override = new ContentSeoOverride(ogImage: 'https://cdn.example.test/approved.jpg');
        $this->assertStringContainsString('<meta property="og:image" content="https://cdn.example.test/approved.jpg">', $this->headOf($this->sxPath($post)));

        ($this->provider)::$override = new ContentSeoOverride(ogImage: 'https://cdn.example.test/approved.jpg', suppressOgImage: true);
        $this->assertStringNotContainsString('og:image', $this->headOf($this->sxPath($post)));
    }

    public function test_override_value_object_semantics(): void
    {
        $this->assertFalse((new ContentSeoOverride)->sanitized()->suppressOgImage, 'no override supplied');
        $this->assertTrue((new ContentSeoOverride)->sanitized()->isEmpty());
        $this->assertTrue((new ContentSeoOverride(ogImage: ''))->sanitized()->suppressOgImage);
        $this->assertTrue((new ContentSeoOverride(ogImage: 'javascript:x'))->sanitized()->suppressOgImage);
        $this->assertFalse((new ContentSeoOverride(suppressOgImage: true))->sanitized()->isEmpty());
        $this->assertTrue((new ContentSeoOverride(suppressOgImage: true))->withNoIndex()->suppressOgImage);
        $this->assertNull((new ContentSeoOverride(ogImage: 'https://cdn.example.test/a.jpg', suppressOgImage: true))->sanitized()->ogImage);
    }

    public function test_core_content_keeps_its_featured_image_fallback(): void
    {
        ($this->provider)::$seo = 'throw';
        $core = $this->sxCorePost('Core Featured', ['featured_image' => '/media/core-featured.jpg']);

        $head = $this->headOf($this->sxPath($core));

        $this->assertStringContainsString('<title>Core Featured</title>', $head);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/media/core-featured.jpg').'">', $head);
        $this->assertStringNotContainsString('noindex', $head);
        $this->assertSame([], ($this->provider)::$calls);
    }
}
