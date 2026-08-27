<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Services\CmsCachePolicy;

/**
 * CORE-OPTIMIZE-2 §28/§33/§34 — Optimize Health Center admin surface. Exercises
 * the real Filament page: authorization, diagnostics rendering, Refresh (no
 * mutation), and the Rebuild action visibility + warm behavior.
 */
class CmsCacheHealthCenterUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.cache.public_ttl' => 3600]);
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    private function makeSlug(string $locale, string $path): void
    {
        Slug::create(['reference_type' => 'content', 'reference_id' => 1, 'locale' => $locale, 'slug' => $path, 'prefix' => null, 'full_path' => $path, 'is_primary' => true]);
    }

    public function test_diagnostics_panel_renders_safe_values(): void
    {
        $this->actingAs(User::factory()->create());
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        // The Optimize tab panel is lazy-rendered by Filament, so assert the
        // render helper directly: it must show administrator-friendly labels and
        // the safe driver name, and never leak a path/secret.
        $page = new SettingsPage;
        $ref = new \ReflectionMethod($page, 'renderCacheDiagnostics');
        $ref->setAccessible(true);
        $html = (string) $ref->invoke($page);

        // Locale-agnostic, safe values (labels are localized to the admin locale).
        $this->assertStringContainsString('array', $html);   // safe driver name
        $this->assertStringContainsString('3600', $html);    // configured TTL
        $this->assertStringContainsString('font-weight:600', $html); // rendered rows
        // Never leak a path or secret.
        $this->assertStringNotContainsString(base_path(), $html);
        $this->assertStringNotContainsString((string) config('app.key'), $html);
    }

    public function test_refresh_diagnostics_action_runs_without_mutation(): void
    {
        $this->actingAs(User::factory()->create());
        $epochBefore = app('cms.public_cache')->version();

        Livewire::test(SettingsPage::class)
            ->callAction('refreshCmsCacheDiagnostics')
            ->assertHasNoErrors();

        $this->assertSame($epochBefore, app('cms.public_cache')->version(), 'Refresh must not mutate the epoch.');
    }

    public function test_rebuild_action_hidden_when_unsupported(): void
    {
        $this->actingAs(User::factory()->create());
        // CMS Cache OFF => rebuild not supported => action hidden.
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        Livewire::test(SettingsPage::class)
            ->assertActionHidden('rebuildCmsCache');
    }

    public function test_rebuild_action_visible_and_warms_when_supported(): void
    {
        $this->actingAs(User::factory()->create());
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);
        $this->makeSlug('en', 'about');

        Livewire::test(SettingsPage::class)
            ->assertActionVisible('rebuildCmsCache')
            ->callAction('rebuildCmsCache')
            ->assertHasNoErrors();

        // The warmed path now resolves from cache (resolver not re-run).
        $calls = 0;
        app('cms.public_cache')->rememberResolution('en', 'about', function () use (&$calls) {
            $calls++;

            return ['reference_type' => 'content', 'reference_id' => 1];
        });
        $this->assertSame(0, $calls);
        $this->assertSame('HIT', app('cms.public_cache')->lastCacheState());
    }

    public function test_clear_action_still_runs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->callAction('clearCmsCache')
            ->assertHasNoErrors();
    }
}
