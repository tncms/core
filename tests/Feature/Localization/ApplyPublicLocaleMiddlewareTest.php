<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use TheNguyen\CMS\Http\Middleware\ApplyPublicLocale;
use Tests\TestCase;

/**
 * CORE-L10N A1 — the Core locale-apply middleware ("cms.locale").
 *
 * Proves Core owns a single, reusable locale runtime any route group (core or
 * plugin) can attach: it establishes the request's public locale from the ACTIVE
 * strategy (prefix → {locale} segment; default otherwise) and 404s an explicit
 * inactive locale — so a plugin never writes its own locale bootstrap.
 *
 * The middleware is exercised directly (not through the frontend route stack)
 * so the CMS catch-all resolver cannot capture the synthetic probe paths.
 */
final class ApplyPublicLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.language')->setCurrent('en');
    }

    /**
     * Run the middleware for a route pattern + path and return the Core-current
     * locale observed downstream (inside the "next" closure).
     */
    private function applied(string $uriPattern, string $path): string
    {
        $request = Request::create($path, 'GET');
        $route = new IlluminateRoute(['GET'], $uriPattern, static fn () => null);
        $route->bind($request);
        $request->setRouteResolver(static fn () => $route);

        $captured = '';

        app(ApplyPublicLocale::class)->handle($request, function () use (&$captured): Response {
            $captured = app('cms.language')->currentCode();

            return new Response('');
        });

        return $captured;
    }

    public function test_prefixed_active_locale_becomes_the_current_locale(): void
    {
        $this->assertSame('vi', $this->applied('{locale}/a1-probe', '/vi/a1-probe'));
    }

    public function test_unprefixed_request_falls_back_to_the_default_locale(): void
    {
        $this->assertSame('en', $this->applied('a1-probe', '/a1-probe'));
    }

    public function test_explicit_active_locale_applies(): void
    {
        $this->assertSame('en', $this->applied('{locale}/a1-probe', '/en/a1-probe'));
    }

    public function test_explicit_inactive_locale_404s(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->applied('{locale}/a1-probe', '/zz/a1-probe');
    }
}
