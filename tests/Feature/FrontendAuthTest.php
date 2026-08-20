<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\EnforceFrontendSession;
use TheNguyen\CMS\Http\Middleware\EnsureFrontendEmailVerified;
use TheNguyen\CMS\Http\Middleware\FrontendAuthenticate;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\FrontendAuthManager;

/**
 * Frontend Identity & Authentication Foundation (v1.0.0-beta.7.1.14).
 * Registration, login/logout, session/device invalidation, middleware, hooks,
 * and health. Uses the shared users table + web guard; no customers table.
 *
 * Middleware that would normally sit on a real route are exercised by direct
 * invocation: the frontend /{slug} catch-all otherwise shadows ad-hoc test
 * routes, so invoking the middleware object is the deterministic approach.
 */
class FrontendAuthTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): FrontendAuthManager
    {
        return app('cms.frontend_auth');
    }

    /** A request bound to a live (array-driver) session, for manager-level tests. */
    private function sessionRequest(string $userAgent = 'Device/A', array $authKeys = []): Request
    {
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => $userAgent]);
        $session = $this->app['session']->driver();
        $session->start();

        if ($authKeys !== []) {
            $session->put('cms_auth', $authKeys);
        }

        $request->setLaravelSession($session);

        return $request;
    }

    private function validSessionKeys(User $user): array
    {
        return [
            'version' => (int) ($user->frontend_session_version ?? 1),
            'expires_at' => now()->addDay()->getTimestamp(),
            'last_activity_at' => now()->getTimestamp(),
        ];
    }

    private function next(): \Closure
    {
        return fn () => new Response('ok');
    }

    // 1.
    public function test_register_route_visible_when_enabled(): void
    {
        config(['cms.auth.registration_enabled' => true]);

        $this->get('/register')->assertOk()->assertSee('Create account', false);
    }

    // 2.
    public function test_register_disabled_blocks_registration(): void
    {
        config(['cms.auth.registration_enabled' => false]);

        $this->get('/register')->assertRedirect(route('cms.auth.login'));

        $this->post('/register', [
            'name' => 'Jane', 'email' => 'jane@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    // 3.
    public function test_registration_creates_user(): void
    {
        config(['cms.auth.registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'Jane', 'email' => 'jane@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com', 'name' => 'Jane']);
    }

    // 4.
    public function test_registration_assigns_configured_default_role(): void
    {
        $role = Role::create(['name' => 'Members', 'slug' => 'members']);
        config(['cms.auth.registration_enabled' => true, 'cms.auth.default_role_id' => $role->getKey()]);

        $this->post('/register', [
            'name' => 'Bob', 'email' => 'bob@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertTrue(User::where('email', 'bob@example.com')->firstOrFail()->hasRole('members'));
    }

    // 5.
    public function test_missing_default_role_falls_back_to_safe_subscriber(): void
    {
        config(['cms.auth.registration_enabled' => true, 'cms.auth.default_role_id' => 999999]);

        $this->post('/register', [
            'name' => 'Sub', 'email' => 'sub@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertTrue(User::where('email', 'sub@example.com')->firstOrFail()->hasRole('subscriber'));

        // The safe fallback role must never carry admin access.
        $this->assertSame(0, Role::where('slug', 'subscriber')->firstOrFail()->permissions()->count());
    }

    // 6.
    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    // 7.
    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // 8.
    public function test_login_regenerates_session(): void
    {
        $user = User::factory()->create();
        $request = $this->sessionRequest();
        $before = $request->session()->getId();

        $this->manager()->login($user, false, $request);

        $this->assertNotSame($before, $request->session()->getId());
    }

    // 9.
    public function test_logout_clears_session(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect();

        $this->assertGuest();
    }

    // 10.
    public function test_remember_checkbox_accepted(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    // 11.
    public function test_session_version_stored_in_session(): void
    {
        $user = User::factory()->create();
        $request = $this->sessionRequest();

        $this->manager()->login($user, false, $request);

        $this->assertSame(
            (int) $user->fresh()->frontend_session_version,
            (int) $request->session()->get('cms_auth.version'),
        );
    }

    // 12.
    public function test_password_reset_increments_session_version(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 1]);

        $this->manager()->changePassword($user, 'new-password-123');

        $this->assertSame(2, (int) $user->fresh()->frontend_session_version);
    }

    // 13.
    public function test_password_change_invalidates_old_session(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 1]);
        $this->manager()->changePassword($user, 'new-password-123'); // -> 2

        // A request still holding the old version must be rejected.
        $request = $this->sessionRequest(authKeys: [
            'version' => 1,
            'expires_at' => now()->addDay()->getTimestamp(),
            'last_activity_at' => now()->getTimestamp(),
        ]);
        $this->actingAs($user->fresh());

        $this->assertFalse($this->manager()->enforceSessionPolicy($request));
    }

    // 14.
    public function test_new_device_login_increments_session_version(): void
    {
        config(['cms.auth.rotate_session_on_device_change' => true]);
        $user = User::factory()->create(['frontend_session_version' => 1]);

        $this->manager()->login($user, false, $this->sessionRequest('Device/A')); // records A
        $this->manager()->login($user, false, $this->sessionRequest('Device/B')); // device change -> +1

        $this->assertSame(2, (int) $user->fresh()->frontend_session_version);
    }

    // 15.
    public function test_old_session_invalid_after_version_mismatch(): void
    {
        $user = User::factory()->create(['frontend_session_version' => 5]);
        $this->actingAs($user);
        $request = $this->sessionRequest(authKeys: [
            'version' => 4,
            'expires_at' => now()->addDay()->getTimestamp(),
            'last_activity_at' => now()->getTimestamp(),
        ]);

        $this->assertFalse($this->manager()->enforceSessionPolicy($request));
    }

    // 16.
    public function test_same_device_login_does_not_unnecessarily_invalidate(): void
    {
        config([
            'cms.auth.rotate_session_on_device_change' => true,
            'cms.auth.single_session_per_user' => false,
        ]);
        $user = User::factory()->create(['frontend_session_version' => 1]);

        $this->manager()->login($user, false, $this->sessionRequest('Device/A'));
        $this->manager()->login($user, false, $this->sessionRequest('Device/A')); // same device

        $this->assertSame(1, (int) $user->fresh()->frontend_session_version);
    }

    // 17.
    public function test_idle_timeout_logs_out_when_exceeded(): void
    {
        config(['cms.auth.idle_timeout_minutes' => 1]);
        $user = User::factory()->create(['frontend_session_version' => 1]);
        $this->actingAs($user);

        $request = $this->sessionRequest(authKeys: [
            'version' => 1,
            'expires_at' => now()->addDay()->getTimestamp(),
            'last_activity_at' => now()->subMinutes(5)->getTimestamp(),
        ]);

        $this->assertFalse($this->manager()->enforceSessionPolicy($request));
    }

    // 18.
    public function test_cms_auth_middleware_protects_route(): void
    {
        $middleware = app(FrontendAuthenticate::class);

        // Guest -> redirected to login.
        $guestResponse = $middleware->handle($this->sessionRequest(), $this->next());
        $this->assertSame(302, $guestResponse->getStatusCode());

        // Authenticated with a valid session -> passes through.
        $user = User::factory()->create(['frontend_session_version' => 1]);
        $this->actingAs($user);
        $authedResponse = $middleware->handle(
            $this->sessionRequest(authKeys: $this->validSessionKeys($user)),
            $this->next(),
        );
        $this->assertSame('ok', $authedResponse->getContent());
    }

    // 19.
    public function test_cms_guest_redirects_logged_in_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/login')->assertRedirect();
        $this->actingAs($user)->get('/register')->assertRedirect();
    }

    // 20-21. RequireRole/RequirePermission middleware coverage removed here:
    // those are generic Core RBAC middleware, out of scope for A2 auth/account
    // localization and excluded from this extraction. They land with the future
    // Core-authz slice that ships RequireRole/RequirePermission.

    // 22.
    public function test_email_verification_required_blocks_protected_route(): void
    {
        config(['cms.auth.email_verification_required' => true]);
        $middleware = app(EnsureFrontendEmailVerified::class);

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified);
        $blocked = $middleware->handle($this->sessionRequest(), $this->next());
        $this->assertSame(302, $blocked->getStatusCode());

        $verified = User::factory()->create(); // verified by default
        $this->actingAs($verified);
        $passed = $middleware->handle($this->sessionRequest(), $this->next());
        $this->assertSame('ok', $passed->getContent());
    }

    // 23.
    public function test_locale_preferences_untouched_by_auth(): void
    {
        $user = User::factory()->create([
            'admin_locale' => 'en', 'frontend_locale' => 'vi', 'editing_locale' => 'fr',
        ]);

        $this->manager()->login($user, false, $this->sessionRequest());

        $fresh = $user->fresh();
        $this->assertSame('en', $fresh->admin_locale);
        $this->assertSame('vi', $fresh->frontend_locale);
        $this->assertSame('fr', $fresh->editing_locale);
    }

    // 24.
    public function test_hooks_fire_on_register_login_logout(): void
    {
        $fired = [];
        foreach (['cms.auth.registered', 'cms.auth.login.success', 'cms.auth.logout'] as $hook) {
            add_action($hook, function () use (&$fired, $hook): void {
                $fired[$hook] = true;
            });
        }

        config(['cms.auth.registration_enabled' => true]);
        $this->post('/register', [
            'name' => 'Hook', 'email' => 'hook@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $user = User::where('email', 'hook@example.com')->firstOrFail();
        $request = $this->sessionRequest();
        $this->manager()->login($user, false, $request);
        $this->manager()->logout($request);

        $this->assertArrayHasKey('cms.auth.registered', $fired);
        $this->assertArrayHasKey('cms.auth.login.success', $fired);
        $this->assertArrayHasKey('cms.auth.logout', $fired);
    }

    // 25.
    public function test_health_fields_exist(): void
    {
        $this->getJson('/cms-health')->assertOk()->assertJsonStructure([
            'frontend_auth_ready',
            'frontend_registration_enabled',
            'frontend_default_role_configured',
            'frontend_session_policy_ready',
            'frontend_email_verification_available',
        ]);
    }

    public function test_frontend_login_does_not_grant_admin_access(): void
    {
        config(['cms.auth.registration_enabled' => true]);
        $this->post('/register', [
            'name' => 'Reg', 'email' => 'reg@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $this->assertFalse(User::where('email', 'reg@example.com')->firstOrFail()->hasPermission('admin.access'));
    }

    public function test_frontend_session_middleware_passes_guest(): void
    {
        $response = app(EnforceFrontendSession::class)->handle($this->sessionRequest(), $this->next());

        $this->assertSame('ok', $response->getContent());
    }
}
