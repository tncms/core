<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;

/**
 * CORE-OPTIMIZE-3 §18.2/§18.21 — Settings → Optimize → Frontend Optimization admin
 * surface. Exercises the real Filament page: authorization gate, conservative
 * defaults, and durable persistence of the response optimization controls.
 */
class RuntimeOptimizationSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    public function test_defaults_render_conservative(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->assertSet('data.optimize_response_enabled', false)
            ->assertSet('data.optimize_response_public_html_ttl', 0);
    }

    public function test_enabling_response_optimization_persists(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', null)
            ->set('data.optimize_response_enabled', true)
            ->set('data.optimize_response_public_html_ttl', 300)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $this->settings()->get(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, false));
        $this->assertSame(300, (int) $this->settings()->get(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 0));
        $this->assertTrue(app('cms.optimization_policy')->responseOptimizationEnabled());
        $this->assertSame(300, app('cms.optimization_policy')->publicHtmlTtl());
    }

    public function test_page_still_requires_settings_permission(): void
    {
        $this->assertFalse(SettingsPage::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertTrue(SettingsPage::canAccess());
    }
}
