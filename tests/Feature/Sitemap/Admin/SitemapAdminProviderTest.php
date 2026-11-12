<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin;

use App\Filament\Admin\Pages\SitemapPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-ADMIN-R1 — dynamic plugin provider discovery and the
 * administrative inclusion toggle (no plugin is named or modified).
 */
final class SitemapAdminProviderTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPlugin('sm-lib');
        $this->smPlugin('sm-other');
        $this->smItems('sm-lib', ['books' => [1 => 'one', 2 => 'two']]);
        $this->smItems('sm-other', ['songs' => [7 => 'seven']]);
        $this->smRoutesWithAdmin('sm-lib', 'sm-other');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->assertTrue($this->smCode('sm-other')->registerSitemap());
    }

    public function test_registered_providers_are_discovered_from_the_registry(): void
    {
        $rows = collect(app('cms.sitemap_settings')->providerRows())->keyBy('id');

        $this->assertSame(['core', 'sm-lib', 'sm-other'], $rows->keys()->all());
        $this->assertTrue($rows['sm-lib']['registered']);
        $this->assertTrue($rows['sm-lib']['active']);
        $this->assertTrue($rows['sm-lib']['included']);
        $this->assertSame('Sitemap Fixture sm-lib', $rows['sm-lib']['name']);
        $this->assertFalse($rows['core']['excludable']);
    }

    public function test_excluding_a_provider_removes_only_its_urls_and_keeps_the_plugin_running(): void
    {
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));

        Livewire::test(SitemapPage::class)
            ->set('data.providers.sm-lib', false)
            ->call('save')
            ->assertHasNoErrors();

        $names = $this->indexNames();
        $this->assertNotContains('sm-lib-books-vi-1', $names);
        $this->assertContains('sm-other-songs-vi-1', $names);
        $this->get('/sitemaps/sm-lib-books-vi-1.xml')->assertNotFound();

        $this->assertTrue(app('cms.extension')->isPluginActive('sm-lib'), 'plugin stays active');
        $this->assertArrayHasKey('sm-lib', app('cms.sitemap_providers')->active(), 'registration untouched');
        $this->get('/lib-sm-lib/books/one')->assertOk()->assertSee('OK-sm-lib');

        Livewire::test(SitemapPage::class)
            ->set('data.providers.sm-lib', true)
            ->call('save');
        $this->assertContains('sm-lib-books-vi-1', $this->indexNames(), 're-including restores');
    }

    public function test_exclusion_is_keyed_by_plugin_identity_across_the_lifecycle(): void
    {
        settings()->set('seo.sitemap_excluded_providers', ['sm-lib'], 'array');

        // Deactivate and reactivate: the operator's decision persists.
        settings()->set('extensions.active_plugins', ['sm-other'], 'array');
        settings()->set('extensions.active_plugins', ['sm-lib', 'sm-other'], 'array');
        $this->assertNotContains('sm-lib-books-vi-1', $this->indexNames());

        // Uninstalled: still shown (not registered) so it can be cleared, never applied elsewhere.
        File::deleteDirectory($this->smRoot().DIRECTORY_SEPARATOR.'sm-lib');
        app('cms.extension')->flushRegistry();
        $rows = collect(app('cms.sitemap_settings')->providerRows())->keyBy('id');
        $this->assertFalse($rows['sm-lib']['registered']);
        $this->assertFalse($rows['sm-lib']['included']);
        $this->assertTrue($rows['sm-other']['included'], 'another plugin is never affected');
    }

    public function test_forged_and_core_provider_identifiers_are_refused(): void
    {
        $settings = app('cms.sitemap_settings');
        $known = array_column($settings->providerRows(), 'id');

        $this->assertArrayHasKey('providers', $settings->validate(['providers' => ['not-installed' => false]], $known));
        $this->assertArrayHasKey('providers', $settings->validate(['providers' => ['core' => false]], $known));
        $this->assertArrayNotHasKey('providers', $settings->validate(['providers' => ['sm-lib' => false]], $known));

        $this->actingAs($this->userWith('sitemap.view', 'sitemap.manage'));
        Livewire::test(SitemapPage::class)
            ->set('data.providers.not-installed', false)
            ->call('save')
            ->assertHasErrors(['data.providers']);
        $this->assertSame([], $settings->excludedProviders());
    }

    public function test_a_broken_provider_does_not_break_the_page_or_the_other_providers(): void
    {
        $this->smItems('sm-lib', ['books' => [1 => 'one']], 'throw-keys');
        $this->assertContains('sm-other-songs-vi-1', $this->indexNames());

        $this->actingAs($this->userWith('sitemap.view'));
        Livewire::test(SitemapPage::class)->assertOk()->assertSee('sm-other');

        $status = app('cms.sitemap')->status('sm-lib');
        $this->assertNotNull($status['failed_at'] ?? null, 'the failure is recorded');
        $this->assertStringNotContainsString('SM-SECRET', json_encode($status), 'no exception message is stored');
    }

    public function test_rendering_the_page_never_builds_a_manifest(): void
    {
        Cache::flush();
        $this->actingAs($this->userWith('sitemap.view'));

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Livewire::test(SitemapPage::class)->assertOk();

        foreach (['core', 'sm-lib', 'sm-other'] as $id) {
            $this->assertNull(Cache::get('cms.sitemap.last.'.$id), "{$id} manifest not built by the admin page");
        }
        $this->assertLessThan(60, $queries, 'bounded queries');
    }
}
