<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\OptimizeResponseHeaders;
use TheNguyen\CMS\Http\Middleware\ResolveTrustedSlots;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * CORE-EXTENSION-TRUST S2 — end-to-end through the HTTP kernel, the default
 * theme and the unchanged `cms_html()` sanitizer (brief §3.2–§3.4, §4;
 * matrix T1/T2/T9/T17, acceptance S2-1/3/4/5).
 */
final class TrustedSlotResolutionTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private object $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-alpha');
        $this->activateComponentPlugins('s2-alpha');

        $this->alpha = $this->pluginCode('s2-alpha');
        // S2-R1 migration: the form target is declared and owned by s2-alpha.
        $this->assertTrue($this->alpha->register($this->provider('s2-alpha', 'form', self::FORM, ['public', 'preview'], ['@host'], null, [], '', [['s2.fixture.submit', ['POST']]])));
        $this->assertTrue($this->alpha->register($this->provider('s2-alpha', 'note', '<p class="s2-note">note</p>', ['public'], ['@host'])));
        $this->alpha->shortcode('s2_form', 's2-alpha/form');

        $this->alpha->route(['POST'], '/s2-fixture/submit', 's2.fixture.submit', fn () => response('ok'));

        $this->page('contact', 'Contact', '<p>Intro</p>[s2_form]<p>Outro</p>');
        $this->page('about', 'About', '<p>About us</p>');
    }

    private function page(string $slug, string $title, string $content): void
    {
        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => $title, 'slug' => $slug, 'content' => $content], \Tests\Support\ContentFixtures::context());
    }

    private function main(string $html): string
    {
        $this->assertSame(1, preg_match('#<main[^>]*>(.*)</main>#s', $html, $m), 'theme renders a <main>');

        return $m[1];
    }

    public function test_multipart_form_with_csrf_survives_in_the_final_response(): void
    {
        $response = $this->get('/contact')->assertOk();
        $html = (string) $response->getContent();
        $main = $this->main($html);

        $this->assertStringContainsString('<form method="post" action="/s2-fixture/submit" enctype="multipart/form-data">', $main);
        $this->assertStringContainsString('<input type="hidden" name="_token" value="'.session()->token().'">', $main);
        foreach (['<input type="file" id="s2-file" name="attachment">', '<textarea id="s2-msg" name="message" rows="3"></textarea>', '<option value="b" selected>B</option>', '<button type="submit">Send</button>', '<label for="s2-name">Name</label>'] as $needle) {
            $this->assertStringContainsString($needle, $main);
        }

        // Order kept: the component sits where the shortcode was.
        $this->assertLessThan(strpos($main, '<form'), strpos($main, 'Intro'));
        $this->assertGreaterThan(strpos($main, '</form>'), strpos($main, 'Outro'));

        $this->assertStringNotContainsString('TNCMSSLOT', $html);
        $this->assertStringNotContainsString('tncms-slot', $html);
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_body_filter_seam_renders_the_component_too(): void
    {
        $this->alpha->bodyFilter('s2-alpha/note', 'About');

        $main = $this->main((string) $this->get('/about')->assertOk()->getContent());

        $this->assertStringContainsString('<p>About us</p><p class="s2-note">note</p>', $main);
    }

    public function test_ordinary_page_is_unchanged_and_keeps_its_cache_headers(): void
    {
        $response = $this->get('/about')->assertOk();

        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('<p>About us</p>', (string) $response->getContent());
        $this->assertStringNotContainsString('<form', $this->main((string) $response->getContent()));
    }

    public function test_ordinary_malicious_html_is_still_sanitized_with_s2_enabled(): void
    {
        $this->page('evil', 'Evil', '<p onclick="alert(1)">x</p><script>alert(2)</script><form action="https://evil.test"><input name="pw"></form><a href="javascript:alert(3)">y</a>[s2_form]');

        $main = $this->main((string) $this->get('/evil')->assertOk()->getContent());

        $this->assertStringNotContainsString('onclick', $main);
        $this->assertStringNotContainsString('alert(2)', $main);
        $this->assertStringNotContainsString('evil.test', $main);
        $this->assertStringNotContainsString('name="pw"', $main);
        $this->assertStringNotContainsString('javascript:', $main);
        // Only the trusted component's form is present.
        $this->assertSame(1, substr_count($main, '<form'));
        $this->assertStringContainsString('action="/s2-fixture/submit"', $main);
    }

    public function test_forged_and_copied_placeholders_in_content_are_stripped(): void
    {
        // Capture a REAL placeholder minted during an earlier request...
        $captured = null;
        add_filter('cms.content.after_shortcode', function ($body) use (&$captured) {
            $captured ??= $this->slotTokens((string) $body)[0] ?? null;

            return $body;
        });
        $this->get('/contact')->assertOk();
        $this->assertNotNull($captured);

        // ...and plant it, plus a forged and a bare token, into stored content.
        $copied = '<div class="tncms-slot">TNCMSSLOT'.$captured.'</div>';
        $forged = '<div class="tncms-slot">TNCMSSLOT'.str_repeat('ab', 16).'</div>';
        $this->page('forged', 'Forged', '<p>start</p>'.$copied.$forged.'<p>TNCMSSLOT'.bin2hex(random_bytes(16)).'</p><p>end</p>');

        $response = $this->get('/forged')->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('<form', $this->main($html));
        $this->assertStringNotContainsString('TNCMSSLOT', $html);
        $this->assertStringNotContainsString($captured, $html);
        $this->assertStringContainsString('<p>start</p>', $html);
        // Nothing was minted on this request, so cache headers are untouched.
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    public function test_two_requests_get_distinct_tokens_and_csrf_per_session(): void
    {
        $tokens = [];
        add_filter('cms.content.after_shortcode', function ($body) use (&$tokens) {
            $tokens = array_merge($tokens, $this->slotTokens((string) $body));

            return $body;
        });

        $a = (string) $this->get('/contact')->getContent();
        $csrfA = session()->token();

        $this->app['session.store']->invalidate();
        $b = (string) $this->get('/contact')->getContent();
        $csrfB = session()->token();

        $this->assertCount(2, $tokens);
        $this->assertNotSame($tokens[0], $tokens[1]);
        $this->assertNotSame($csrfA, $csrfB);
        $this->assertStringContainsString('value="'.$csrfA.'"', $a);
        $this->assertStringContainsString('value="'.$csrfB.'"', $b);
        $this->assertStringNotContainsString($csrfA, $b);
    }

    public function test_optimization_on_slot_pages_are_no_store_other_pages_keep_private_max_age(): void
    {
        settings()->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);
        settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 300);

        $this->assertSame('no-store, private', $this->get('/contact')->headers->get('Cache-Control'));
        $this->assertSame('max-age=300, private', $this->get('/about')->headers->get('Cache-Control'));
    }

    public function test_explicit_public_caching_is_overridden_when_a_slot_was_minted(): void
    {
        $this->firstGetRoute('/s2-public-cache', function () {
            return response('<html><body>'.cms_trusted_component('s2-alpha/note').'</body></html>')
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Cache-Control', 'public, max-age=600');
        });
        $this->firstGetRoute('/s2-public-plain', fn () => response('<html><body><p>x</p></body></html>')
            ->header('Cache-Control', 'public, max-age=600'));

        $minted = $this->get('/s2-public-cache')->assertOk();
        $this->assertSame('no-store, private', $minted->headers->get('Cache-Control'));
        $this->assertStringContainsString('<p class="s2-note">note</p>', (string) $minted->getContent());

        $this->assertSame('max-age=600, public', $this->get('/s2-public-plain')->headers->get('Cache-Control'));
    }

    public function test_route_without_the_resolver_gets_the_fallback_never_a_placeholder(): void
    {
        $this->firstGetRoute('/s2-no-resolver', fn () => response('<html><body>'.cms_trusted_component('s2-alpha/note').'</body></html>'), ['web']);

        $html = (string) $this->get('/s2-no-resolver')->assertOk()->getContent();

        $this->assertStringContainsString(TrustedSlotVault::FALLBACK, $html);
        $this->assertStringNotContainsString('TNCMSSLOT', $html);
        $this->assertStringNotContainsString('s2-note', $html);
    }

    public function test_response_non_html_json_and_plain_text_are_untouched(): void
    {
        $this->firstGetRoute('/s2-json', fn () => response()->json(['slot' => cms_trusted_component('s2-alpha/note')]));
        $this->firstGetRoute('/s2-text', fn () => response(cms_trusted_component('s2-alpha/note'))->header('Content-Type', 'text/plain'));

        $json = $this->get('/s2-json')->assertOk();
        $this->assertMatchesRegularExpression('#TNCMSSLOT[0-9a-f]{32}#', (string) $json->getContent());
        $this->assertStringNotContainsString('s2-note', (string) $json->getContent());
        $this->assertSame('no-store, private', $json->headers->get('Cache-Control'));

        $text = $this->get('/s2-text')->assertOk();
        $this->assertMatchesRegularExpression('#TNCMSSLOT[0-9a-f]{32}#', (string) $text->getContent());
        $this->assertStringNotContainsString('s2-note', (string) $text->getContent());
    }

    public function test_response_redirect_and_error_bodies_are_stripped_not_resolved(): void
    {
        $this->firstGetRoute('/s2-404', fn () => response('<html><body>'.cms_trusted_component('s2-alpha/note').'</body></html>', 404));
        $this->firstGetRoute('/s2-500', function () {
            cms_trusted_component('s2-alpha/note');
            throw new \RuntimeException('boom');
        });
        $this->firstGetRoute('/s2-redirect', fn () => redirect('/about')->setContent('<html><body>'.cms_trusted_component('s2-alpha/note').'</body></html>'));

        config(['app.debug' => false]);

        foreach (['/s2-404' => 404, '/s2-500' => 500, '/s2-redirect' => 302] as $uri => $status) {
            $response = $this->get($uri);
            $this->assertSame($status, $response->getStatusCode(), $uri);
            $this->assertDoesNotMatchRegularExpression('#TNCMSSLOT[0-9a-f]{32}#i', (string) $response->getContent(), $uri);
            $this->assertStringNotContainsString('boom', (string) $response->getContent(), $uri);
            $this->assertStringNotContainsString('s2-note', (string) $response->getContent(), $uri);
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'), $uri);
        }
    }

    public function test_response_head_is_empty_with_get_headers(): void
    {
        $response = $this->call('HEAD', '/contact');

        $response->assertOk();
        $this->assertSame('', (string) $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_response_streamed_and_encoded_bodies_are_untouched_and_late_mints_fail_closed(): void
    {
        $late = null;
        $this->firstGetRoute('/s2-stream', function () use (&$late) {
            return new StreamedResponse(function () use (&$late) {
                $late = cms_trusted_component('s2-alpha/note');
                echo $late;
            }, 200, ['Content-Type' => 'text/html']);
        });
        $this->firstGetRoute('/s2-gzip', fn () => response('<html><body>'.cms_trusted_component('s2-alpha/note').'</body></html>')
            ->header('Content-Encoding', 'gzip'));

        $stream = $this->get('/s2-stream');
        $stream->assertOk();
        $this->assertSame(TrustedSlotVault::FALLBACK, $stream->streamedContent());

        $gzip = (string) $this->get('/s2-gzip')->getContent();
        $this->assertMatchesRegularExpression('#TNCMSSLOT[0-9a-f]{32}#', $gzip);
        $this->assertStringNotContainsString('s2-note', $gzip);
    }

    public function test_middleware_placement_frontend_and_preview_only(): void
    {
        $router = app('router');

        $this->assertSame(ResolveTrustedSlots::class, $router->getMiddleware()['cms.trusted_slots'] ?? null);
        $this->assertNotContains(ResolveTrustedSlots::class, $router->getMiddlewareGroups()['web'] ?? []);

        $home = $router->getRoutes()->getByName('cms.home');
        $preview = $router->getRoutes()->getByName('cms.preview.show');
        $this->assertNotNull($home);
        $this->assertNotNull($preview);

        $homeStack = $home->gatherMiddleware();
        $this->assertContains(ResolveTrustedSlots::class, $homeStack);
        // Innermost of the frontend group: listed after OptimizeResponseHeaders.
        $this->assertGreaterThan(array_search(OptimizeResponseHeaders::class, $homeStack, true), array_search(ResolveTrustedSlots::class, $homeStack, true));
        $this->assertContains(ResolveTrustedSlots::class, $preview->gatherMiddleware());

        foreach ($router->getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'admin') || str_starts_with($uri, 'livewire') || str_starts_with($uri, 'install') || str_starts_with($uri, 'cms-health')) {
                $this->assertNotContains(ResolveTrustedSlots::class, $route->gatherMiddleware(), $uri);
            }
        }
    }

    public function test_locale_does_not_change_ownership_or_authority(): void
    {
        app()->setLocale('vi');
        $this->freshSlotRequest();

        $this->assertNotSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/note'));
        $this->writeComponentPlugin('s2-beta');
        $this->activateComponentPlugins('s2-beta');
        // 'note' allows '@host' only: switching locale grants the beta plugin nothing.
        $this->assertSame(TrustedSlotVault::FALLBACK, $this->pluginCode('s2-beta')->invoke('s2-alpha/note'));
        $this->assertSame(['s2-alpha/form', 's2-alpha/note'], $this->components()->ids());
    }
}
