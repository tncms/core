<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-RELEASE-BLOCKERS-R1-CLOSURE — TZ-1 / TZ-4 (brief §7). Publishing uses only the application
 * timezone (APP_TIMEZONE). Settings → General → Timezone (`general.timezone`) never moves a publication
 * time, but it IS read by at least one installed theme (33game maintenance countdown), so the field is
 * kept and its UI is made accurate: the effective publishing timezone is shown read-only, and the field
 * says it does not change publishing. The stored preference is never discarded or rewritten.
 */
final class SettingsTimezoneUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create(['admin_locale' => 'en']);
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
        app('cms.language')->setCurrent('en');
    }

    public function test_settings_shows_the_effective_publishing_timezone_read_only(): void
    {
        config(['app.timezone' => 'America/New_York']);

        $html = Livewire::test(SettingsPage::class)->html();

        $this->assertStringContainsString('Publishing timezone', $html);
        $this->assertStringContainsString('America/New_York', $html);
        $this->assertStringContainsString('APP_TIMEZONE', $html);
    }

    public function test_the_timezone_field_states_it_does_not_change_publishing(): void
    {
        $html = Livewire::test(SettingsPage::class)->html();

        $this->assertStringContainsString('does not change when posts are published', $html);
    }

    public function test_the_wording_is_localized_in_vietnamese(): void
    {
        app('cms.language')->setCurrent('vi');

        $html = Livewire::test(SettingsPage::class)->html();

        $this->assertStringContainsString('Múi giờ xuất bản', $html);
        $this->assertStringContainsString('không thay đổi thời điểm bài viết được xuất bản', $html);
    }

    public function test_a_stored_preference_different_from_the_publishing_timezone_is_kept_and_flagged(): void
    {
        config(['app.timezone' => 'UTC']);
        app('cms.settings')->set('general.timezone', 'Asia/Ho_Chi_Minh', 'string');
        app('cms.settings')->clearCache();

        $component = Livewire::test(SettingsPage::class);
        $component->assertSet('data.general_timezone', 'Asia/Ho_Chi_Minh');
        $this->assertStringContainsString('Publishing uses UTC', $component->html());

        // Saving unrelated settings never rewrites the stored preference.
        $component->set('data.general_site_name', 'Renamed')->set('data.writing_default_category_id', null)->call('save')->assertHasNoErrors();

        app('cms.settings')->clearCache();
        $this->assertSame('Asia/Ho_Chi_Minh', settings('general.timezone'));
        $this->assertSame('UTC', config('app.timezone'), 'no automatic application-timezone change');
    }
}
