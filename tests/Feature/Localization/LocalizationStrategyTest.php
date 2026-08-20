<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\Exceptions\LocalizationStrategyException;
use TheNguyen\CMS\Localization\LocalizationStrategyRegistry;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Localization\Strategies\PrefixLocalizationStrategy;
use TheNguyen\CMS\Localization\Strategies\SessionLocalizationStrategy;
use Tests\TestCase;

/**
 * CORE-L10N.1B — P2 localization strategy engine.
 *
 * Covers the stateless prefix + session strategies, the fail-fast/fail-closed registry, and
 * that the active strategy is driven solely by Core's `language.routing_strategy`. No existing
 * URL generation is rewired yet (that is P3/P4); this proves the engine in isolation.
 */
final class LocalizationStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app()->setLocale('en');

        Route::get('products/{slug}', fn () => '')->name('test.product');
        Route::getRoutes()->refreshNameLookups();
    }

    private function prefix(): PrefixLocalizationStrategy
    {
        return app(LocalizationStrategyRegistry::class)->resolve('prefix');
    }

    private function sessionStrategy(): SessionLocalizationStrategy
    {
        return app(LocalizationStrategyRegistry::class)->resolve('session');
    }

    private function descriptor(string $slug = 'dong-ho-toi-gian'): RouteDescriptor
    {
        return new RouteDescriptor(
            resolverKey: 'ecommerce.product',
            canonicalType: 'product',
            canonicalId: 7,
            routeName: 'test.product',
            routeParameters: ['slug' => $slug],
        );
    }

    /** A request whose bound route exposes a {locale} segment. */
    private function localizedRequest(string $uri, string $routeUri): Request
    {
        $request = Request::create($uri, 'GET');
        $route = (new IlluminateRoute(['GET'], $routeUri, []))->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    // ── prefix strategy ─────────────────────────────────────────────────────────

    public function test_prefix_resolves_locale_from_the_url_segment(): void
    {
        $this->assertSame('vi', $this->prefix()->resolveLocale(
            $this->localizedRequest('/vi/products/x', '{locale}/products/{slug}')
        ));

        // No locale segment → default locale.
        $this->assertSame('en', $this->prefix()->resolveLocale(
            $this->localizedRequest('/products/x', 'products/{slug}')
        ));
    }

    public function test_prefix_generates_translated_url_with_core_prefix(): void
    {
        $request = Request::create('/products/x', 'GET');

        // Root-relative localized paths (byte-identical to legacy localizedUrl()):
        // default locale unprefixed, secondary locale prefixed.
        $this->assertSame('/products/dong-ho-toi-gian', $this->prefix()->url($this->descriptor(), 'en', $request));
        $this->assertSame('/vi/products/dong-ho-toi-gian', $this->prefix()->url($this->descriptor(), 'vi', $request));
        $this->assertSame('get', $this->prefix()->switchMethod());
    }

    public function test_prefix_honors_default_prefix_policy(): void
    {
        app('cms.settings')->set('language.prefix_default', true, 'boolean');

        $this->assertSame(
            '/en/products/dong-ho-toi-gian',
            $this->prefix()->url($this->descriptor(), 'en', Request::create('/products/x', 'GET'))
        );
    }

    // ── session strategy ────────────────────────────────────────────────────────

    public function test_session_resolves_locale_from_core_persistence(): void
    {
        $request = Request::create('/products/x', 'GET');
        $session = $this->app['session']->driver();
        $session->start();
        $request->setLaravelSession($session);
        app('cms.locale_preference')->persistFrontendLocale($request, null, 'vi');

        $this->assertSame('vi', $this->sessionStrategy()->resolveLocale($request));
    }

    public function test_session_preserves_current_route_and_uses_post(): void
    {
        $request = Request::create('/products/dong-ho-toi-gian?ref=1', 'GET');

        $this->assertSame(
            '/products/dong-ho-toi-gian?ref=1',
            $this->sessionStrategy()->url($this->descriptor(), 'vi', $request)
        );
        $this->assertSame('post', $this->sessionStrategy()->switchMethod());

        $action = $this->sessionStrategy()->switchAction('vi', 'http://localhost/products/dong-ho-toi-gian?ref=1', $request);
        $this->assertSame('vi', $action['fields']['locale']);
        $this->assertSame('/products/dong-ho-toi-gian?ref=1', $action['fields']['redirect']);
    }

    // ── registry: fail fast / fail closed ─────────────────────────────────────────

    public function test_duplicate_strategy_key_is_rejected(): void
    {
        $registry = new LocalizationStrategyRegistry;
        $registry->register($this->prefix());

        $this->expectException(LocalizationStrategyException::class);
        $registry->register($this->prefix());
    }

    public function test_unknown_active_strategy_fails_closed(): void
    {
        $this->expectException(LocalizationStrategyException::class);
        app(LocalizationStrategyRegistry::class)->active('domain-not-registered');
    }

    public function test_active_strategy_follows_the_routing_strategy_setting(): void
    {
        $this->assertSame('prefix', app(LocalizationStrategyContract::class)->key());

        app('cms.settings')->set('language.routing_strategy', 'session', 'string');
        $this->assertSame('session', app(LocalizationStrategyContract::class)->key());
    }

    public function test_registry_reports_registered_strategy_keys(): void
    {
        $this->assertEqualsCanonicalizing(['prefix', 'session'], app(LocalizationStrategyRegistry::class)->keys());
    }
}
