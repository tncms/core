<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\OptimizeResponseHeaders;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * CORE-OPTIMIZE-3 §6/§18 — HTTP response header safety. The middleware only ever
 * annotates anonymous public HTML with a PRIVATE browser cache, and marks every
 * other request class no-store. It NEVER emits a shared/public cache directive, so
 * no response can become publicly cacheable by mistake — the core §6 guarantee.
 */
class OptimizeResponseHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function enable(?int $ttl = null): void
    {
        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);

        if ($ttl !== null) {
            app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, $ttl);
        }
    }

    private function html(int $status = 200): Response
    {
        return new Response('<html>ok</html>', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function pipe(Request $request, SymfonyResponse $response): SymfonyResponse
    {
        return app(OptimizeResponseHeaders::class)->handle($request, fn () => $response);
    }

    private function guestGet(string $uri = '/about'): Request
    {
        return Request::create($uri, 'GET');
    }

    private function authedGet(string $uri = '/about'): Request
    {
        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => new \stdClass);

        return $request;
    }

    // §18.3 — anonymous cacheable HTML, TTL 0 → private, revalidate.
    public function test_anonymous_html_gets_private_no_cache_by_default_ttl(): void
    {
        $this->enable(0);

        $response = $this->pipe($this->guestGet(), $this->html());

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    // §18.3 — positive TTL → private, max-age.
    public function test_anonymous_html_gets_private_max_age_when_ttl_positive(): void
    {
        $this->enable(600);

        $response = $this->pipe($this->guestGet(), $this->html());

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertSame('600', $response->headers->getCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
    }

    // §18.4 — authenticated response → no-store (never cacheable anywhere).
    public function test_authenticated_html_gets_no_store(): void
    {
        $this->enable(600);

        $response = $this->pipe($this->authedGet(), $this->html());

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    // §18.3 — non-GET → no-store.
    public function test_non_get_gets_no_store(): void
    {
        $this->enable(600);

        $response = $this->pipe(Request::create('/x', 'POST'), $this->html());

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §18.3 — preview request → no-store (editors see live output).
    public function test_preview_request_gets_no_store(): void
    {
        $this->enable(600);

        $response = $this->pipe($this->guestGet('/about?preview=1'), $this->html());

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §4 — disabled (default) is a strict no-op: our directives are never applied.
    public function test_disabled_is_a_no_op(): void
    {
        // Not enabled.
        $response = $this->pipe($this->guestGet(), $this->html());

        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §16 — non-200 responses are left untouched.
    public function test_non_200_is_left_untouched(): void
    {
        $this->enable(600);

        $response = $this->pipe($this->guestGet(), $this->html(404));

        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §16 — non-HTML responses are left untouched.
    public function test_non_html_is_left_untouched(): void
    {
        $this->enable(600);

        $json = new Response('{}', 200, ['Content-Type' => 'application/json']);
        $response = $this->pipe($this->guestGet(), $json);

        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §6 — an explicit controller/plugin caching directive is never overridden.
    public function test_explicit_cache_control_is_respected(): void
    {
        $this->enable(600);

        $response = $this->html();
        $response->headers->set('Cache-Control', 'public, max-age=120');

        $result = $this->pipe($this->guestGet(), $response);

        $this->assertTrue($result->headers->hasCacheControlDirective('public'));
        $this->assertSame('120', $result->headers->getCacheControlDirective('max-age'));
    }

    // §16 — streamed responses (downloads) are skipped.
    public function test_streamed_response_is_skipped(): void
    {
        $this->enable(600);

        $streamed = new StreamedResponse(fn () => print ('data'), 200, ['Content-Type' => 'text/html']);
        $response = $this->pipe($this->guestGet(), $streamed);

        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    // §6 core invariant — the middleware NEVER emits a shared/public cache
    // directive for ANY request class, so nothing can become publicly cacheable.
    public function test_never_emits_shared_cache_directive(): void
    {
        $this->enable(600);

        foreach ([$this->guestGet(), $this->authedGet(), Request::create('/x', 'POST')] as $request) {
            $response = $this->pipe($request, $this->html());
            $this->assertFalse($response->headers->hasCacheControlDirective('public'), 'must never set public');
            $this->assertFalse($response->headers->hasCacheControlDirective('s-maxage'), 'must never set s-maxage');
        }
    }

    // §16 — failure fallback: an internal error leaves the response as rendered.
    public function test_failure_leaves_response_unchanged(): void
    {
        $throwingCache = new class extends PublicContentCacheManager
        {
            public function isCacheableRequest(Request $request): bool
            {
                throw new \RuntimeException('boom');
            }
        };

        $policy = new CmsOptimizationPolicy;
        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);

        $middleware = new OptimizeResponseHeaders($policy, $throwingCache);
        $original = $this->html();
        $response = $middleware->handle($this->guestGet(), fn () => $original);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<html>ok</html>', $response->getContent());
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }
}
