<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Support\PluginViewException;

/**
 * CORE-PLATFORM-SEAMS-R2 Gate D (Outcome B: long-running workers are not a
 * supported runtime). A worker whose in-memory state predates a deactivation
 * made elsewhere must still fail closed for the deactivated plugin's routes and
 * contract-rendered views, from the persisted (shared-cache) activation state.
 */
final class StaleWorkerFailClosedTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useSeamPluginsRoot();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LARAVEL_OCTANE'], $_SERVER['FRANKENPHP_WORKER']);
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    /** Real boot order: plugin routes, then Core's frontend routes (catch-all last). */
    private function bootWithRoutes(string $slug): void
    {
        app('router')->setRoutes(new \Illuminate\Routing\RouteCollection);
        $this->bootSeamPlugins($slug);
        require base_path('packages/thenguyen/cms-core/routes/frontend.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /** Another process deactivates the plugin: only the persisted state changes. */
    private function deactivateElsewhere(string $slug): void
    {
        $active = array_values(array_diff(app('cms.extension')->activePluginSlugs(), [$slug]));
        settings()->set('extensions.active_plugins', $active, 'array');
    }

    public function test_stale_worker_routes_answer_404_after_a_deactivation_elsewhere(): void
    {
        $this->writeLifecyclePlugin('stale-routes', '');
        $this->bootWithRoutes('stale-routes');

        $this->get('/lifecycle-stale-routes')->assertOk()->assertSee('ROUTE-stale-routes');

        $this->deactivateElsewhere('stale-routes');

        $this->assertNotNull(app('router')->getRoutes()->getByName('lifecycle.stale-routes'), 'route still in this worker');
        $this->get('/lifecycle-stale-routes')->assertNotFound();
    }

    public function test_stale_worker_contract_view_refuses_after_a_deactivation_elsewhere(): void
    {
        $this->writeSeamPlugin('stale-views');
        $this->makeSeamTheme('stale-theme', ['plugins/stale-views/public/card.blade.php' => 'STALE-OVERRIDE']);
        $this->bootSeamPlugins('stale-views');
        $this->assertSame('STALE-OVERRIDE', cms_plugin_view('stale-views', 'public.card')->render());

        $this->deactivateElsewhere('stale-views');

        $this->assertTrue(View::exists('stale-views::public.card'), 'namespace still in this worker');

        try {
            cms_plugin_view('stale-views', 'public.card');
            $this->fail('stale worker rendered a deactivated plugin view');
        } catch (PluginViewException $e) {
            $this->assertSame('inactive_plugin', $e->reason);
        }
    }

    public function test_active_plugin_routes_are_unaffected_by_the_guard(): void
    {
        $this->writeLifecyclePlugin('stale-ok', '');
        $this->bootWithRoutes('stale-ok');

        $route = app('router')->getRoutes()->getByName('lifecycle.stale-ok');
        $this->assertContains('cms.plugin-active:stale-ok', $route->gatherMiddleware());
        $this->get('/lifecycle-stale-ok')->assertOk();
    }

    public function test_long_running_worker_is_detected_and_warned(): void
    {
        $this->assertFalse(CmsInfo::isLongRunningWorker());

        $_SERVER['LARAVEL_OCTANE'] = '1';
        $this->assertTrue(CmsInfo::isLongRunningWorker());

        Log::spy();
        $method = new \ReflectionMethod(\TheNguyen\CMS\Providers\CmsServiceProvider::class, 'warnOnUnsupportedRuntime');
        $method->invoke(app()->getProvider(\TheNguyen\CMS\Providers\CmsServiceProvider::class));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'not a supported runtime'))->once();

        unset($_SERVER['LARAVEL_OCTANE']);
        $_SERVER['FRANKENPHP_WORKER'] = '1';
        $this->assertTrue(CmsInfo::isLongRunningWorker());
    }
}
