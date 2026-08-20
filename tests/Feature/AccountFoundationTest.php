<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Services\AccountManager;
use TheNguyen\CMS\Support\Account\AccountNavItem;

/**
 * Account Foundation (v1.0.0-beta.7.1.15). Frontend account area for logged-in
 * users: dashboard, profile, security (email/password), sessions, preferences,
 * account hooks, and health. Core owns identity/security/preferences only — no
 * customer/order/membership data. Uses the shared users table + web guard.
 */
class AccountFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function account(): AccountManager
    {
        return app('cms.account');
    }

    /** A request bound to a live (array-driver) session, for manager-level tests. */
    private function sessionRequest(array $authKeys = []): Request
    {
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => 'Device/A']);
        $session = $this->app['session']->driver();
        $session->start();

        if ($authKeys !== []) {
            $session->put('cms_auth', $authKeys);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    private function seedLocales(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => false, 'is_active' => true]);
    }

    // 1.
    public function test_account_requires_auth(): void
    {
        $this->get('/account')->assertRedirect(route('cms.auth.login'));
    }

    // 2.
    public function test_dashboard_renders_for_logged_in_user(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);

        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertSee('Ada', false)
            ->assertSee('Account overview', false);
    }

    // 3.
    public function test_profile_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/profile')->assertOk()->assertSee('Save profile', false);
    }

    // 4.
    public function test_profile_update_changes_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/profile', [
            'name' => 'New Name',
            'username' => 'new_name',
            'phone' => '+123456789',
            'bio' => 'Hello world.',
        ])->assertRedirect(route('cms.account.profile'));

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'name' => 'New Name',
            'username' => 'new_name',
            'phone' => '+123456789',
            'bio' => 'Hello world.',
        ]);
    }

    // 5.
    public function test_username_uniqueness_enforced(): void
    {
        User::factory()->create(['username' => 'taken']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account/profile')
            ->post('/account/profile', ['name' => 'X', 'username' => 'taken'])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('username');
    }

    // 6.
    public function test_email_change_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)
            ->from('/account/security')
            ->post('/account/security/email', [
                'email' => 'new@example.com',
                'current_password' => 'wrong-password',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors('current_password');

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    // 7.
    public function test_email_change_updates_email_and_rotates_session_version(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'frontend_session_version' => 1]);

        $this->actingAs($user)->post('/account/security/email', [
            'email' => 'new@example.com',
            'current_password' => 'password',
        ])->assertRedirect(route('cms.account.security'));

        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertSame(2, (int) $fresh->frontend_session_version);
    }

    // 8.
    public function test_password_change_requires_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account/security')
            ->post('/account/security/password', [
                'current_password' => 'wrong-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors('current_password');
    }

    // 9.
    public function test_password_change_rotates_session_version_and_remember_token(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 1]);
        $originalToken = $user->getRememberToken();

        $this->actingAs($user)->post('/account/security/password', [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect(route('cms.account.security'));

        $fresh = $user->fresh();
        $this->assertSame(2, (int) $fresh->frontend_session_version);
        $this->assertNotSame($originalToken, $fresh->getRememberToken());
    }

    // 10.
    public function test_old_session_invalid_after_password_change(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 1]);

        // Change the password (bumps the version to 2).
        $this->account()->updatePassword($user, 'brand-new-password');

        // A request still holding the old version must be rejected.
        $request = $this->sessionRequest([
            'version' => 1,
            'expires_at' => now()->addDay()->getTimestamp(),
            'last_activity_at' => now()->getTimestamp(),
        ]);
        $this->actingAs($user->fresh());

        $this->assertFalse(app('cms.frontend_auth')->enforceSessionPolicy($request));
    }

    // 11.
    public function test_logout_other_sessions_increments_session_version(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 3]);

        $this->actingAs($user)->post('/account/sessions/logout-others', [
            'current_password' => 'password',
        ])->assertRedirect(route('cms.account.sessions'));

        $this->assertSame(4, (int) $user->fresh()->frontend_session_version);
    }

    // 12.
    public function test_preferences_page_renders(): void
    {
        $this->seedLocales();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/preferences')->assertOk()->assertSee('Save preferences', false);
    }

    // 13.
    public function test_preferences_update_locales_independently(): void
    {
        $this->seedLocales();
        $user = User::factory()->create(['admin_locale' => 'en', 'frontend_locale' => 'en', 'editing_locale' => 'en']);

        // Submit only the site language; the other two must be untouched.
        $this->actingAs($user)->post('/account/preferences', ['frontend_locale' => 'vi'])
            ->assertRedirect(route('cms.account.preferences'));

        $fresh = $user->fresh();
        $this->assertSame('vi', $fresh->frontend_locale);
        $this->assertSame('en', $fresh->admin_locale);
        $this->assertSame('en', $fresh->editing_locale);
    }

    // 14.
    public function test_invalid_locale_rejected(): void
    {
        $this->seedLocales();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account/preferences')
            ->post('/account/preferences', ['frontend_locale' => 'zz'])
            ->assertRedirect('/account/preferences')
            ->assertSessionHasErrors('frontend_locale');
    }

    // 15.
    public function test_invalid_timezone_rejected(): void
    {
        $this->seedLocales();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/account/preferences')
            ->post('/account/preferences', ['timezone' => 'Not/AZone'])
            ->assertRedirect('/account/preferences')
            ->assertSessionHasErrors('timezone');
    }

    // 16.
    public function test_navigation_filter_can_add_plugin_item(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('orders', 'Orders', '/account/orders', 'box', 60);

            return $items;
        });

        $keys = array_map(static fn (AccountNavItem $i): string => $i->key, $this->account()->navigationItems());

        $this->assertContains('orders', $keys);
    }

    // 17.
    public function test_account_hook_renders_plugin_content(): void
    {
        add_action('cms.account.dashboard.before', static function (): void {
            echo 'PLUGIN_ACCOUNT_BLOCK';
        });

        $user = User::factory()->create();

        $this->actingAs($user)->get('/account')->assertOk()->assertSee('PLUGIN_ACCOUNT_BLOCK', false);
    }

    // 18.
    public function test_ecommerce_labels_absent_by_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account');
        $response->assertOk();
        $response->assertDontSee('Orders');
        $response->assertDontSee('Wishlist');
        $response->assertDontSee('Addresses');
        $response->assertDontSee('Invoices');
    }

    // 19.
    public function test_health_fields_exist(): void
    {
        $this->getJson('/cms-health')->assertOk()->assertJsonStructure([
            'account_foundation_ready',
            'account_routes_ready',
            'account_hooks_ready',
            'account_profile_fields_ready',
        ]);
    }

    // 20.
    public function test_frontend_auth_middleware_still_passes(): void
    {
        // The existing guest-only login page still resolves...
        $this->get('/login')->assertOk();

        // ...and authenticated users reach the account area (auth middleware passes).
        $user = User::factory()->create();
        $this->actingAs($user)->get('/account')->assertOk();
    }
}
