<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Models\Language;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — language changes made through the real write path
 * (LanguageManager, used by the admin Create/Edit/Delete language pages) invalidate the
 * manager's memoised default/active state within the same process, so the account and
 * auth surface and cms_localized_route() follow them immediately.
 */
final class AccountLanguageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_writes_invalidate_the_memoised_default_and_active_set(): void
    {
        $languages = app('cms.language');
        $languages->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $this->assertSame('vi', $languages->defaultCode());      // memoised now
        $this->assertFalse($languages->isActive('en'));

        $en = $languages->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->assertTrue($languages->isActive('en'));
        $this->assertStringEndsWith('/en/account/profile', cms_localized_route('cms.account.profile', locale: 'en'));

        $this->assertTrue($languages->setDefault('en'));
        $this->assertSame('en', $languages->defaultCode());
        $this->assertStringEndsWith('/account/profile', cms_localized_route('cms.account.profile', locale: 'en'));
        $this->assertStringNotContainsString('/en/', cms_localized_route('cms.account.profile', locale: 'en'));

        $vi = Language::query()->where('code', 'vi')->firstOrFail();
        $languages->update($vi, ['is_active' => false]);
        $this->assertFalse($languages->isActive('vi'));
        $this->assertStringNotContainsString('/vi/', cms_localized_route('cms.account.profile', locale: 'vi'));

        $this->assertTrue($languages->delete($vi->fresh()));
        $this->assertSame(['en'], $languages->active()->pluck('code')->all());
        $this->assertSame('en', $en->fresh()->code);
    }

    public function test_account_page_follows_a_default_language_change_made_through_the_manager(): void
    {
        $languages = app('cms.language');
        $languages->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $languages->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->actingAs(User::factory()->create());

        $this->get('/account/security')->assertSee('<html lang="vi">', false);
        $languages->setDefault('en');
        $this->get('/account/security')->assertSee('<html lang="en">', false)->assertSee('Change password');
    }
}
