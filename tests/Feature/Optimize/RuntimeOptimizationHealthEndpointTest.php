<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\OptimizeResponseHeaders;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;

/**
 * CORE-OPTIMIZE-3 §14/§18 — the public /cms-health endpoint carries a bounded,
 * path/secret-free runtime optimization block, and the response-header middleware
 * is scoped to the frontend content group ONLY (never admin/installer/upgrade), so
 * sensitive responses can never be made publicly cacheable by mistake (§6/§12).
 */
class RuntimeOptimizationHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_exposes_runtime_optimization_fields(): void
    {
        $this->getJson('/cms-health')->assertOk()->assertJsonStructure([
            'response_optimization',
            'response_public_html_ttl',
            'response_optimization_status',
            'static_assets_fingerprinted',
            'static_assets_status',
        ]);
    }

    public function test_health_fields_reflect_settings(): void
    {
        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);
        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 120);

        $this->getJson('/cms-health')->assertOk()->assertJson([
            'response_optimization' => true,
            'response_public_html_ttl' => 120,
            'response_optimization_status' => 'healthy',
        ]);
    }

    public function test_health_optimization_block_is_secret_free(): void
    {
        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);

        $body = $this->getJson('/cms-health')->assertOk()->getContent();

        $this->assertStringNotContainsString(base_path(), (string) $body);
        $this->assertStringNotContainsString('APP_KEY', (string) $body);
        $this->assertStringNotContainsString(':memory:', (string) $body);
    }

    public function test_middleware_is_scoped_to_frontend_group(): void
    {
        $router = app('router');

        $home = Route::getRoutes()->getByName('cms.home');
        $this->assertNotNull($home, 'cms.home route must exist');
        $this->assertContains(
            OptimizeResponseHeaders::class,
            $router->gatherRouteMiddleware($home),
            'Frontend routes must carry the response optimization middleware.'
        );

        // No admin/installer/upgrade route may carry it (§6/§12).
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! preg_match('#^(admin|install|upgrade)(/|$)#', $uri)) {
                continue;
            }

            $this->assertNotContains(
                OptimizeResponseHeaders::class,
                $router->gatherRouteMiddleware($route),
                "Sensitive route [{$uri}] must never carry response optimization."
            );
        }
    }
}
