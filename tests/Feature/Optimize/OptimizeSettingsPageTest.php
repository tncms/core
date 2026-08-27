<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Services\CmsCachePolicy;

/**
 * CORE-OPTIMIZE-1 — Settings → Optimize admin surface (§30). Exercises the real
 * Filament page: authorization gate, default rendering, ON/OFF persistence, and
 * the Clear CMS Cache header action.
 */
class OptimizeSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    public function test_page_requires_settings_permission(): void
    {
        // Anonymous is rejected by the existing settings.manage gate.
        $this->assertFalse(SettingsPage::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertTrue(SettingsPage::canAccess());
    }

    public function test_optimize_toggle_renders_enabled_by_default(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->assertSet('data.optimize_cache_enabled', true);
    }

    public function test_toggle_off_persists_and_disables_policy(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            // Provide a valid form: site name is a localized key that mounts
            // empty until translated, and the default category select has no
            // options in a fresh test database. Neither is under test here.
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', null)
            ->set('data.optimize_cache_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse((bool) $this->settings()->get(CmsCachePolicy::SETTING_KEY, true));
        $this->assertTrue(app('cms.cache_policy')->disabled());
    }

    public function test_toggle_on_persists_and_enables_policy(): void
    {
        $this->actingAs(User::factory()->create());
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', null)
            ->set('data.optimize_cache_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $this->settings()->get(CmsCachePolicy::SETTING_KEY, false));
        $this->assertTrue(app('cms.cache_policy')->enabled());
    }

    public function test_clear_cms_cache_header_action_runs(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SettingsPage::class)
            ->callAction('clearCmsCache')
            ->assertHasNoErrors();
    }
}
