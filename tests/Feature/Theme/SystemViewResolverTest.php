<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Tests\TestCase;
use TheNguyen\CMS\View\SystemViewResolver;

/**
 * CORE-FRONTEND-SYSTEM-VIEW-RESOLVER-1 — the ONE Core-owned frontend
 * system-page theme-first resolver.
 *
 * Covers: the frozen six-page registry, theme-override vs Core-fallback
 * precedence, recursion-safe fallback when a theme override is broken (login
 * stays reachable), rejection of arbitrary/unknown keys (no user-driven view
 * selection), HTTP-layer noindex enforcement for the private pages, preserved
 * guards/validation/redirects/data parity, and the search controller consuming
 * the shared resolver without any behaviour change.
 */
final class SystemViewResolverTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'tests/Fixtures/system-view-theme';

    private const BROKEN = 'tests/Fixtures/system-view-theme-broken';

    private function resolver(): SystemViewResolver
    {
        return app('cms.system_view');
    }

    /** Point the "theme::" namespace at a fixture theme for the current request. */
    private function useThemeOverride(string $path = self::THEME): void
    {
        View::replaceNamespace('theme', [base_path($path)]);
    }

    // --- Registry + selection (service level) ---------------------------------

    public function test_registry_exposes_exactly_the_ten_system_pages(): void
    {
        $this->assertSame(
            [
                'login', 'register', 'forgot-password', 'reset-password',
                'account', 'profile', 'security', 'preferences', 'sessions', 'search',
            ],
            $this->resolver()->pages(),
        );
    }

    public function test_frozen_theme_and_fallback_view_names(): void
    {
        $r = $this->resolver();

        $expected = [
            'login' => ['theme::auth.login', 'cms::auth.login'],
            'register' => ['theme::auth.register', 'cms::auth.register'],
            'forgot-password' => ['theme::auth.forgot-password', 'cms::auth.forgot-password'],
            'reset-password' => ['theme::auth.reset-password', 'cms::auth.reset-password'],
            'account' => ['theme::account.index', 'cms::account.dashboard'],
            'profile' => ['theme::account.profile', 'cms::account.profile'],
            'security' => ['theme::account.security', 'cms::account.security'],
            'preferences' => ['theme::account.preferences', 'cms::account.preferences'],
            'sessions' => ['theme::account.sessions', 'cms::account.sessions'],
            'search' => ['theme::search.index', 'frontend.search.index'],
        ];

        foreach ($expected as $page => [$theme, $fallback]) {
            $this->assertTrue($r->has($page), $page);
            $this->assertSame($theme, $r->themeView($page), $page);
            $this->assertSame($fallback, $r->fallbackView($page), $page);
        }
    }

    public function test_resolve_returns_core_fallback_when_no_theme_override(): void
    {
        $r = $this->resolver();

        foreach ($r->pages() as $page) {
            $this->assertFalse(View::exists($r->themeView($page)), "baseline: {$page} override must be absent");
            $this->assertSame($r->fallbackView($page), $r->resolve($page), $page);
        }
    }

    public function test_resolve_prefers_theme_override_when_present(): void
    {
        $this->useThemeOverride();
        $r = $this->resolver();

        foreach ($r->pages() as $page) {
            $this->assertSame($r->themeView($page), $r->resolve($page), $page);
        }
    }

    public function test_unknown_key_cannot_select_an_arbitrary_view(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->resolver()->resolve('../../../etc/passwd');
    }

    public function test_every_accessor_rejects_an_unknown_key(): void
    {
        foreach (['themeView', 'fallbackView', 'resolve', 'renderSafely'] as $method) {
            try {
                $this->resolver()->{$method}('not-a-page');
                $this->fail("{$method}() accepted an unknown key");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_render_safely_uses_valid_theme_override(): void
    {
        // Simulate the web group's ShareErrorsFromSession (always present on a
        // real request) so the view has its $errors bag.
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->useThemeOverride();

        $this->assertStringContainsString('theme-login-override', $this->resolver()->renderSafely('login'));
    }

    public function test_render_safely_falls_back_when_theme_override_is_broken(): void
    {
        View::share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->useThemeOverride(self::BROKEN);
        $this->assertTrue(View::exists('theme::auth.login'));

        $html = $this->resolver()->renderSafely('login');

        // The broken override threw; the Core fallback rendered instead.
        $this->assertStringContainsString('tn-field', $html);
        $this->assertStringNotContainsString('should-never-render', $html);
    }

    // --- Login (HTTP wiring) --------------------------------------------------

    public function test_login_fallback_renders_core_view_with_noindex_header(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-field', false)
            ->assertSee('noindex,nofollow', false); // Core auth layout meta
    }

    public function test_login_theme_override_wins_and_is_noindex_via_header(): void
    {
        $this->useThemeOverride();

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-login-override', false)
            // The fixture theme layout omits the robots meta; the header alone
            // still guarantees noindex.
            ->assertDontSee('name="robots"', false);
    }

    public function test_login_broken_theme_override_falls_back_and_stays_reachable(): void
    {
        $this->useThemeOverride(self::BROKEN);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-field', false)
            ->assertDontSee('should-never-render', false);
    }

    public function test_login_guest_guard_redirects_authenticated_user(): void
    {
        // cms.guest still redirects an authenticated user away from /login.
        $user = User::factory()->create();
        $this->actingAs($user)->get('/login')->assertRedirect();
    }

    public function test_login_invalid_credentials_bounce_with_error(): void
    {
        // Validation + auth-attempt semantics unchanged: invalid credentials
        // bounce back to /login with the email error bag; the user stays a guest.
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'secret-but-wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_old_input_and_errors_parity_through_theme_override(): void
    {
        $this->useThemeOverride();

        // Follow the failed-login redirect back onto the themed login view and
        // assert the flashed error bag + old('email') survive into the override.
        $this->from('/login')
            ->followingRedirects()
            ->post('/login', ['email' => 'kept@example.com', 'password' => 'secret-but-wrong'])
            ->assertOk()
            ->assertSee('theme-login-override', false)
            ->assertSee('kept@example.com', false)
            ->assertSee('theme-errors', false);
    }

    // --- Register (HTTP wiring) ----------------------------------------------

    public function test_register_fallback_and_theme_override_are_noindex(): void
    {
        config(['cms.auth.registration_enabled' => true]);

        $this->get('/register')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Create account', false);

        $this->useThemeOverride();
        $this->get('/register')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-register-override', false);
    }

    public function test_register_disabled_guard_is_preserved(): void
    {
        config(['cms.auth.registration_enabled' => false]);

        $this->get('/register')->assertRedirect(route('cms.auth.login'));
    }

    // --- Forgot / reset password (HTTP wiring) --------------------------------

    public function test_forgot_password_fallback_and_override_are_noindex(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-field', false);

        $this->useThemeOverride();
        $this->get('/forgot-password')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-forgot-override', false);
    }

    public function test_reset_password_fallback_receives_token_and_email(): void
    {
        $this->get('/reset-password/tok-abc?email=a%40b.com')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tok-abc', false)
            ->assertSee('a@b.com', false);
    }

    public function test_reset_password_theme_override_receives_same_data(): void
    {
        $this->useThemeOverride();

        $this->get('/reset-password/tok-123?email=user%40example.com')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-reset-override', false)
            ->assertSee('tok-123', false)
            ->assertSee('user@example.com', false);
    }

    // --- Account (HTTP wiring) ------------------------------------------------

    public function test_account_requires_auth_then_resolves_fallback_then_override(): void
    {
        // Guest guard preserved.
        $this->get('/account')->assertRedirect(route('cms.auth.login'));

        $user = User::factory()->create();

        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false);

        $this->useThemeOverride();
        $this->actingAs($user)->get('/account')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-account-override', false)
            ->assertSee($user->name, false);
    }

    // --- Account profile / security / preferences (HTTP wiring) ---------------

    public function test_account_subpages_require_authentication(): void
    {
        foreach (['/account/profile', '/account/security', '/account/preferences'] as $path) {
            $this->get($path)->assertRedirect(route('cms.auth.login'));
        }
    }

    public function test_profile_fallback_then_override_are_noindex(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/profile')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertSee('noindex,nofollow', false)                      // Core account layout meta
            ->assertSee(route('cms.account.profile.update'), false)     // verified form action
            ->assertSee('name="_token"', false);                       // @csrf

        $this->useThemeOverride();
        $this->actingAs($user)->get('/account/profile')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-profile-override', false)
            // The fixture theme layout omits the robots meta; the header alone
            // still guarantees noindex.
            ->assertDontSee('name="robots"', false);
    }

    public function test_profile_broken_override_falls_back_and_stays_reachable(): void
    {
        $user = User::factory()->create();
        $this->useThemeOverride(self::BROKEN);

        $this->actingAs($user)->get('/account/profile')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertDontSee('should-never-render', false);
    }

    public function test_profile_update_still_validates_and_persists(): void
    {
        $user = User::factory()->create();

        // Missing required name bounces back with a validation error (unchanged).
        $this->from('/account/profile')
            ->actingAs($user)
            ->post('/account/profile', ['name' => ''])
            ->assertRedirect('/account/profile')
            ->assertSessionHasErrors('name');

        // Valid update persists and flashes status (unchanged behaviour).
        $this->actingAs($user)
            ->post('/account/profile', ['name' => 'Renamed Person'])
            ->assertRedirect(route('cms.account.profile'))
            ->assertSessionHas('status');

        $this->assertSame('Renamed Person', $user->fresh()->name);
    }

    public function test_security_fallback_then_override_are_noindex_and_hide_secrets(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/security')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertSee(route('cms.account.security.password'), false)
            ->assertSee('name="_token"', false);

        // The page must never leak the stored password hash.
        $response->assertDontSee($user->password, false);

        $this->useThemeOverride();
        $this->actingAs($user)->get('/account/security')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-security-override', false)
            ->assertDontSee('name="robots"', false);
    }

    public function test_security_broken_override_falls_back_and_stays_reachable(): void
    {
        $user = User::factory()->create();
        $this->useThemeOverride(self::BROKEN);

        $this->actingAs($user)->get('/account/security')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertDontSee('should-never-render', false);
    }

    public function test_security_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create(); // factory password is 'password'
        $original = $user->password;

        // Wrong current password: validation error, password unchanged.
        $this->from('/account/security')
            ->actingAs($user)
            ->post('/account/security/password', [
                'current_password' => 'not-the-password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertRedirect('/account/security')
            ->assertSessionHasErrors('current_password');

        $this->assertSame($original, $user->fresh()->password);

        // Correct current password: change applied, status flashed.
        $this->actingAs($user)
            ->post('/account/security/password', [
                'current_password' => 'password',
                'password' => 'new-secret-123',
                'password_confirmation' => 'new-secret-123',
            ])
            ->assertRedirect(route('cms.account.security'))
            ->assertSessionHas('status');

        $this->assertNotSame($original, $user->fresh()->password);
    }

    public function test_preferences_fallback_then_override_are_noindex(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/preferences')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertSee(route('cms.account.preferences.update'), false)
            ->assertSee('name="_token"', false);

        $this->useThemeOverride();
        $this->actingAs($user)->get('/account/preferences')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-preferences-override', false)
            ->assertDontSee('name="robots"', false);
    }

    public function test_preferences_broken_override_falls_back_and_stays_reachable(): void
    {
        $user = User::factory()->create();
        $this->useThemeOverride(self::BROKEN);

        $this->actingAs($user)->get('/account/preferences')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-acc-card', false)
            ->assertDontSee('should-never-render', false);
    }

    public function test_preferences_update_validates_timezone_and_persists(): void
    {
        $user = User::factory()->create();

        // Invalid timezone bounces with an error (validation unchanged).
        $this->from('/account/preferences')
            ->actingAs($user)
            ->post('/account/preferences', ['timezone' => 'Not/AZone'])
            ->assertRedirect('/account/preferences')
            ->assertSessionHasErrors('timezone');

        // Valid timezone persists and flashes status.
        $this->actingAs($user)
            ->post('/account/preferences', ['timezone' => 'Asia/Ho_Chi_Minh'])
            ->assertRedirect(route('cms.account.preferences'))
            ->assertSessionHas('status');

        $this->assertSame('Asia/Ho_Chi_Minh', $user->fresh()->timezone);
    }

    // --- Search (shared resolver, behaviour unchanged) ------------------------

    public function test_search_consumes_resolver_without_changing_behaviour(): void
    {
        // Fallback stays the host-app view and existing search SEO is retained.
        $this->assertSame('frontend.search.index', $this->resolver()->resolve('search'));

        $this->get('/search')
            ->assertOk()
            ->assertSee('content="noindex,follow"', false);

        // Theme override wins through the same resolver.
        $this->useThemeOverride();
        $this->assertSame('theme::search.index', $this->resolver()->resolve('search'));
    }

    public function test_helper_delegates_to_resolver(): void
    {
        $this->assertSame('cms::auth.login', cms_system_view('login'));

        $this->useThemeOverride();
        $this->assertSame('theme::auth.login', cms_system_view('login'));
    }
}
