<?php

declare(strict_types=1);

namespace Tests\Feature\ContentExtension;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentSeoOverride;
use TheNguyen\CMS\Content\Extension\PreparedContent;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-CONTENT-EXT-R1 M2 — the single-content gate: status decided before any
 * view renders, Core-mapped headers, inactive/missing owner fallbacks,
 * preview untouched, Core content byte-for-byte unchanged.
 */
final class ContentResponseGateTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    private int $rendered = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCxLanguages();
        $this->useCxPlugins('cx-alpha');
        add_action('cms.post.rendered', function (): void {
            $this->rendered++;
        });
    }

    private function assertManagedCache($response): void
    {
        $directives = fn (string $v): array => collect(explode(',', $v))->map(fn ($d) => trim($d))->sort()->values()->all();

        $this->assertSame($directives(PreparedContent::MANAGED_CACHE_CONTROL), $directives((string) $response->headers->get('Cache-Control')));
    }

    private function path(Content $content): string
    {
        return parse_url(content_url($content, 'en'), PHP_URL_PATH);
    }

    public function test_core_article_is_unchanged(): void
    {
        $post = $this->corePost('Plain Core Article');

        $response = $this->get($this->path($post));

        $response->assertOk()->assertSee('Plain Core Article');
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        $this->assertSame(1, $this->rendered);
    }

    public function test_available_managed_article_renders_with_revalidating_cache_header(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Available');

        $response = $this->get($this->path($post));

        $response->assertOk()->assertSee('Managed Available');
        $this->assertManagedCache($response);
    }

    public function test_unavailable_is_404_before_render(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Hidden');
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Unavailable);

        $response = $this->get($this->path($post));

        $response->assertNotFound()->assertDontSee('Managed Hidden body');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, $this->rendered, 'the theme view never rendered');
    }

    public function test_gone_is_404_by_default_and_410_only_when_enabled(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Gone');
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Gone);

        $this->get($this->path($post))->assertNotFound();

        app('cms.settings')->set('content.allow_gone', true);
        $response = $this->get($this->path($post));

        $response->assertStatus(410)->assertSee('Content no longer available');
        $this->assertStringContainsString('noindex', $response->getContent());
        $this->assertSame(0, $this->rendered);
    }

    public function test_temporarily_unavailable_is_503_with_retry_after_and_no_store(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Paused');
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::TemporarilyUnavailable, 600);

        $response = $this->get($this->path($post));

        $response->assertStatus(503);
        $this->assertSame('600', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(0, $this->rendered);
    }

    public function test_unpublished_managed_article_stays_404_from_core_and_the_owner_is_never_asked(): void
    {
        $provider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(title: 'Should Not Apply'));
        $this->assertTrue($this->cxPlugin('cx-alpha')->registerSeo($provider));

        $post = $this->ownedPost('cx-alpha', 'Managed Draft', ['status' => 'draft']);

        $this->get('/'.$post->translations->first()->slug)->assertNotFound();
        $this->assertSame([], $provider->calls);
    }

    /** @return array<string, array{ContentFallbackPolicy, int}> */
    public static function fallbacks(): array
    {
        return [
            'core_render' => [ContentFallbackPolicy::CoreRender, 200],
            'hide_404' => [ContentFallbackPolicy::Hide404, 404],
            'temporary_503' => [ContentFallbackPolicy::Temporary503, 503],
        ];
    }

    #[DataProvider('fallbacks')]
    public function test_inactive_owner_applies_the_persisted_fallback(ContentFallbackPolicy $fallback, int $status): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Fallback '.$fallback->value);
        $this->cxPlugin('cx-alpha')->fallback($post->id, $fallback);
        // Availability is the ACTIVE owner's state; inactive uses the fallback only.
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Unavailable);

        app('cms.extension')->deactivatePlugin('cx-alpha');
        app('cms.extension')->flushRegistry();

        $response = $this->get($this->path($post));

        $response->assertStatus($status);

        if ($status === 503) {
            $this->assertSame('300', $response->headers->get('Retry-After'));
        }

        if ($status === 200) {
            $response->assertSee('Managed Fallback core_render');
            $this->assertManagedCache($response);
        }
    }

    public function test_default_plugin_created_content_hides_when_owner_is_missing(): void
    {
        $this->writeCxPlugin('cx-vanish');
        app('cms.extension')->flushRegistry();
        app('cms.extension')->activatePlugin('cx-vanish');
        app('cms.extension')->flushRegistry();
        $this->cxPlugin('cx-vanish')->registerGuard(); // owned content is governed by its owner's guard
        $post = $this->ownedPost('cx-vanish', 'Managed Missing Owner');
        $path = $this->path($post);

        app('cms.extension')->deactivatePlugin('cx-vanish');
        \Illuminate\Support\Facades\File::deleteDirectory($this->cxRoot().DIRECTORY_SEPARATOR.'cx-vanish');
        app('cms.extension')->flushRegistry();

        $this->get($path)->assertNotFound();
    }

    public function test_page_routes_and_the_slug_resolver_are_gated_too(): void
    {
        $page = $this->cxPlugin('cx-alpha')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Managed Page', 'slug' => 'managed-page', 'content' => 'x',
        ]);
        $this->get('/managed-page')->assertOk();

        $this->cxPlugin('cx-alpha')->availability($page->id, ContentAvailability::TemporarilyUnavailable, 120);
        $this->get('/managed-page')->assertStatus(503)->assertHeader('Retry-After', '120');
    }

    public function test_reference_cache_hit_never_reuses_a_200_after_availability_flips(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed Flip');
        $path = $this->path($post);

        $this->get($path)->assertOk();          // miss → caches the slug reference
        $this->get($path)->assertOk();          // hit

        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Unavailable);
        $this->get($path)->assertNotFound();    // hit, decision re-evaluated

        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Available);
        $this->get($path)->assertOk();
    }

    public function test_preview_of_unavailable_managed_content_still_renders_without_owner_calls(): void
    {
        $provider = $this->cxSeoProvider('cx-alpha', new ContentSeoOverride(title: 'Override In Preview'));
        $this->cxPlugin('cx-alpha')->registerSeo($provider);
        $post = $this->ownedPost('cx-alpha', 'Managed Preview');
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::TemporarilyUnavailable);

        $url = app('cms.preview')->temporaryUrl($post, locale: 'en');
        $response = $this->get($url);

        $response->assertOk()->assertSee('Managed Preview');
        $response->assertDontSee('Override In Preview');
        $this->assertSame([], $provider->calls);
    }
}
