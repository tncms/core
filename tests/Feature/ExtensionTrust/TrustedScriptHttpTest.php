<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ExtensionTrust\Concerns\TrustedScriptFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;

/**
 * CORE-EXTENSION-TRUST S1 — end-to-end through the HTTP kernel and the default
 * theme layout. Matrix: T6 (no CSP header), T18 / N9 (route scoping), D-5
 * (cache headers with response optimization on/off), N11, N12.
 */
final class TrustedScriptHttpTest extends TestCase
{
    use RefreshDatabase;
    use TrustedScriptFixtures;

    private object $caller;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        foreach (['contact', 'about'] as $slug) {
            app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'content' => '<p>'.$slug.'</p>']);
        }

        $this->useFixturePlugins();
        $this->cleanFixturePlugins();
        $this->writePlugin('ts-http', ['turnstile' => $this->turnstileEntry()]);
        $this->activate('ts-http');
        $this->caller = $this->caller('ts-http');

        // The plugin's renderer: enqueue ONLY while rendering the contact page.
        $caller = $this->caller;
        add_filter('cms.content.body', function ($body, $content) use ($caller) {
            if ($content->translatedTitle('en') === 'Contact') {
                $caller->enqueue('turnstile');
            }

            return $body;
        }, 10, 2);
    }

    private function enableOptimization(int $ttl): void
    {
        settings()->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);
        settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, $ttl);
    }

    public function test_blocked_before_approval(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $this->assertStringNotContainsString(self::TURNSTILE, (string) $response->getContent());
    }

    public function test_n9_approved_script_renders_once_on_the_enqueued_page_only(): void
    {
        $this->approve('ts-http', 'turnstile');

        $contact = (string) $this->get('/contact')->assertOk()->getContent();
        $about = (string) $this->get('/about')->assertOk()->getContent();

        $this->assertSame(1, substr_count($contact, self::TURNSTILE));
        $this->assertStringContainsString('<script src="'.self::TURNSTILE.'" async defer></script>', $contact);
        // Rendered in the footer bucket, i.e. after the main content.
        $this->assertGreaterThan(strpos($contact, '</main>'), strpos($contact, self::TURNSTILE));
        $this->assertStringNotContainsString(self::TURNSTILE, $about);
        Http::assertNothingSent();
    }

    public function test_absent_after_deactivation_and_after_fingerprint_change(): void
    {
        $this->approve('ts-http', 'turnstile');
        $this->assertStringContainsString(self::TURNSTILE, (string) $this->get('/contact')->getContent());

        $this->writePlugin('ts-http', ['turnstile' => $this->turnstileEntry(['attributes' => ['async' => true]])]);
        $this->assertStringNotContainsString(self::TURNSTILE, (string) $this->get('/contact')->getContent());

        $this->approve('ts-http', 'turnstile');
        $this->assertStringContainsString(self::TURNSTILE, (string) $this->get('/contact')->getContent());

        app('cms.extension')->deactivatePlugin('ts-http');
        $this->assertStringNotContainsString(self::TURNSTILE, (string) $this->get('/contact')->getContent());
    }

    public function test_no_csp_header_is_emitted_with_or_without_trusted_scripts(): void
    {
        $this->approve('ts-http', 'turnstile');

        foreach (['/contact', '/about'] as $uri) {
            $response = $this->get($uri);
            $this->assertNull($response->headers->get('Content-Security-Policy'), $uri);
            $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'), $uri);
        }
    }

    public function test_d5_trusted_script_pages_are_no_store_with_optimization_off(): void
    {
        $this->approve('ts-http', 'turnstile');

        $contact = $this->get('/contact');
        $about = $this->get('/about');

        $this->assertSame('no-store, private', $contact->headers->get('Cache-Control'));
        // Untouched page keeps the framework default.
        $this->assertSame('no-cache, private', $about->headers->get('Cache-Control'));
    }

    public function test_d5_trusted_script_pages_are_no_store_with_optimization_on_and_positive_ttl(): void
    {
        $this->approve('ts-http', 'turnstile');
        $this->enableOptimization(300);

        $contact = $this->get('/contact');
        $about = $this->get('/about');

        $this->assertSame('no-store, private', $contact->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', (string) $contact->headers->get('Cache-Control'));
        // Without a trusted script, optimization behaves exactly as before.
        $this->assertSame('max-age=300, private', $about->headers->get('Cache-Control'));
    }

    public function test_d5_unapproved_page_keeps_existing_cache_behaviour(): void
    {
        $this->enableOptimization(300);

        $this->assertSame('max-age=300, private', $this->get('/contact')->headers->get('Cache-Control'));
    }

    public function test_d5_authenticated_page_keeps_stronger_existing_no_store(): void
    {
        $this->approve('ts-http', 'turnstile');
        $this->enableOptimization(300);
        $this->actingAs(\App\Models\User::factory()->create());

        $this->assertSame('no-store, private', $this->get('/contact')->headers->get('Cache-Control'));
    }

    public function test_n12_existing_theme_and_plugin_assets_still_render_alongside(): void
    {
        $this->approve('ts-http', 'turnstile');
        // Asset tags only: canonical/hreflang <link>s legitimately differ per page.
        $pattern = '/<script\b[^>]*>|<link\b[^>]*rel="(?:stylesheet|modulepreload|preload)"[^>]*>/i';
        $baseline = (string) $this->get('/about')->getContent();
        preg_match_all($pattern, $baseline, $before);

        $contact = (string) $this->get('/contact')->getContent();
        preg_match_all($pattern, $contact, $after);

        // Every asset tag of a normal page is still present, plus exactly one.
        foreach ($before[0] as $tag) {
            $this->assertStringContainsString($tag, $contact);
        }
        $this->assertCount(count($before[0]) + 1, $after[0]);
    }
}
