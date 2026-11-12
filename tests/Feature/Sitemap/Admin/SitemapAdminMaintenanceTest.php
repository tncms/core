<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin;

use App\Filament\Admin\Pages\SitemapPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-ADMIN-R1 — diagnostics and cache maintenance: explicit
 * permissions, bounded work, sitemap-only invalidation, honest reporting.
 */
final class SitemapAdminMaintenanceTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPost('Diag Post');
        $this->smPlugin('sm-lib');
        $this->smItems('sm-lib', ['books' => [1 => 'one', 2 => 'two']]);
        $this->smRoutesWithAdmin('sm-lib');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
    }

    public function test_diagnostics_require_their_own_permission(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));
        Livewire::test(SitemapPage::class)->assertActionHidden('runDiagnostics');
        Livewire::test(SitemapPage::class)->call('runDiagnosticsNow')->assertForbidden();

        $this->assertNull(app('cms.sitemap_diagnostics')->last());
    }

    public function test_diagnostics_report_bounded_internal_checks(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.diagnostics'));
        Livewire::test(SitemapPage::class)->callAction('runDiagnostics')->assertHasNoActionErrors();

        $report = app('cms.sitemap_diagnostics')->last();
        $this->assertNotNull($report);
        $checks = collect($report['checks'])->keyBy('key');

        foreach (['index', 'robots', 'cache', 'reserved_routes', 'indexing_policy'] as $key) {
            $this->assertSame('pass', $checks[$key]['status'], $key);
        }
        $providers = collect($report['providers'])->keyBy('id');
        $this->assertSame('pass', $providers['core']['status']);
        $this->assertSame('pass', $providers['sm-lib']['status']);
        $this->assertSame(2, $providers['sm-lib']['sampled_urls']);
        $this->assertLessThanOrEqual(1, $providers['sm-lib']['sampled_children']);
        $this->assertSame([], $report['duplicates']);
    }

    public function test_diagnostics_flag_a_failing_provider_without_leaking_details(): void
    {
        $this->smItems('sm-lib', ['books' => [1 => 'one']], 'throw-keys');
        $report = app('cms.sitemap_diagnostics')->run();

        $providers = collect($report['providers'])->keyBy('id');
        $this->assertSame('pass', $providers['core']['status'], 'others unaffected');
        $this->assertNotSame('pass', $providers['sm-lib']['status']);
        $this->assertStringNotContainsString('SM-SECRET', json_encode($report), 'no exception message');
    }

    public function test_diagnostic_details_render_in_the_admin_language(): void
    {
        app('cms.language')->setCurrent('vi');
        $this->assertNotSame('read/write ok', tn_trans('read/write ok'), 'vi translation present');

        $this->actingAs($this->userWith('sitemap.view', 'sitemap.diagnostics'));
        $report = app('cms.sitemap_diagnostics')->run();
        $index = collect($report['checks'])->keyBy('key')['index'];
        $this->assertSame(':n child sitemaps', $index['detail'], 'stored as a template');

        Livewire::test(SitemapPage::class)
            ->assertSee(tn_trans('read/write ok'))
            ->assertSee(tn_trans(':n child sitemaps', $index['params']))
            ->assertDontSee('read/write ok');
    }

    public function test_a_failure_older_than_the_last_good_build_is_shown_as_resolved(): void
    {
        $this->smItems('sm-lib', ['books' => [1 => 'one']], 'throw-keys');
        app('cms.sitemap')->indexXml();
        $this->assertNotNull(app('cms.sitemap')->status('sm-lib')['failed_at'] ?? null);

        $this->travel(5)->seconds();
        $this->smItems('sm-lib', ['books' => [1 => 'one']]);
        app('cms.sitemap')->invalidateProviders(['sm-lib']);
        app('cms.sitemap')->indexXml();

        $this->actingAs($this->userWith('sitemap.view'));
        Livewire::test(SitemapPage::class)
            ->assertSee(tn_trans('Resolved by a later successful generation.'))
            ->assertDontSee('SM-SECRET');
    }

    public function test_cache_refresh_requires_permission(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage', 'sitemap.diagnostics'));
        Livewire::test(SitemapPage::class)->assertActionHidden('refreshCache');
        Livewire::test(SitemapPage::class)->call('refreshCacheNow')->assertForbidden();
    }

    public function test_cache_refresh_invalidates_only_sitemap_manifests_and_keeps_last_good(): void
    {
        $this->indexNames(); // warm
        $lastCore = Cache::get('cms.sitemap.last.core');
        $this->assertIsArray($lastCore);
        $versions = ['core' => Cache::get('cms.sitemap.version.core'), 'sm-lib' => Cache::get('cms.sitemap.version.sm-lib')];
        Cache::forever('unrelated.app.key', 'keep');
        Cache::forever('cms.public_content.probe', 'keep');

        $this->actingAs($this->userWith('sitemap.view', 'sitemap.cache_refresh'));
        Livewire::test(SitemapPage::class)->callAction('refreshCache')->assertHasNoActionErrors();

        $this->assertNotSame($versions['core'], Cache::get('cms.sitemap.version.core'));
        $this->assertNotSame($versions['sm-lib'], Cache::get('cms.sitemap.version.sm-lib'));
        $this->assertSame($lastCore, Cache::get('cms.sitemap.last.core'), 'last-good fallback preserved');
        $this->assertSame('keep', Cache::get('unrelated.app.key'));
        $this->assertSame('keep', Cache::get('cms.public_content.probe'));
        $this->assertNotNull(app('cms.sitemap')->lastRefresh());

        // Regeneration is lazy: the next request rebuilds.
        $this->assertContains('sm-lib-books-vi-1', $this->indexNames());
    }

    public function test_an_unavailable_cache_never_breaks_the_page_or_fakes_a_refresh(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.cache_refresh'));
        $this->breakSitemapCacheKeys();

        $page = Livewire::test(SitemapPage::class)->assertOk()->assertDontSee('SM-SECRET');

        $this->expectsRefreshFailure($page);
    }

    public function test_a_save_whose_invalidation_fails_warns_instead_of_claiming_success(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));
        $this->breakSitemapCacheKeys();

        Livewire::test(SitemapPage::class)
            ->set('data.types.tags', false)
            ->call('save')
            ->assertNotified(tn_trans('Settings saved, but the sitemap cache could not be invalidated.'));

        $this->assertSame(['tags'], app('cms.sitemap_settings')->excludedTypes(), 'the setting itself is stored');
    }

    public function test_read_helpers_are_not_callable_over_the_wire(): void
    {
        $this->actingAs($this->userWith('sitemap.view'));

        foreach (['overview', 'providerRows', 'lastDiagnostics', 'canManage'] as $method) {
            try {
                Livewire::test(SitemapPage::class)->call($method);
                $this->fail("$method must not be a callable Livewire action");
            } catch (\Livewire\Exceptions\MethodNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function breakSitemapCacheKeys(): void
    {
        // Core settings themselves are read through the cache (an outage of the
        // whole store is Core's existing failure policy); here only the
        // sitemap-owned keys fail, which this page must survive honestly.
        Cache::extend('sm-broken', fn () => Cache::repository(new class extends \Illuminate\Cache\ArrayStore
        {
            private function guard(string $key): void
            {
                if (str_starts_with($key, 'cms.sitemap.')) {
                    throw new \RuntimeException('SM-SECRET cache down');
                }
            }

            public function get($key)
            {
                $this->guard((string) $key);

                return parent::get($key);
            }

            public function put($key, $value, $seconds)
            {
                $this->guard((string) $key);

                return parent::put($key, $value, $seconds);
            }

            public function forever($key, $value)
            {
                $this->guard((string) $key);

                return parent::forever($key, $value);
            }
        }));
        config(['cache.stores.sm-broken' => ['driver' => 'sm-broken'], 'cache.default' => 'sm-broken']);
        Cache::forgetDriver('sm-broken');
    }

    private function expectsRefreshFailure(\Livewire\Features\SupportTesting\Testable $page): void
    {
        $page->callAction('refreshCache')->assertNotified(tn_trans('The sitemap cache could not be refreshed.'));
        $this->assertNull(app('cms.sitemap')->lastRefresh());
    }
}
