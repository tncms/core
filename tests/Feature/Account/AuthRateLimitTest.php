<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use TheNguyen\CMS\Http\RateLimiting\FrontendAuthRateLimits as Limits;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — frontend auth POST throttles: identity + IP
 * keyed, no global bucket, framework 429, identical for known and unknown
 * accounts, shared by the unprefixed and localized twins, not applied to the
 * Filament admin login.
 */
final class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        config(['cms.auth.registration_enabled' => true]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function failLogin(string $email, string $ip = '10.0.0.1', string $path = '/login'): int
    {
        return $this->fromIp($ip)->post($path, ['email' => $email, 'password' => 'wrong-password'])->getStatusCode();
    }

    public function test_routes_use_the_named_limiters(): void
    {
        $expect = [
            'cms.auth.login.attempt' => 'throttle:cms-auth-login',
            'cms.auth.register.store' => 'throttle:cms-auth-register',
            'password.email' => 'throttle:cms-auth-password-email',
            'password.update' => 'throttle:cms-auth-password-reset',
            'verification.send' => 'throttle:cms-auth-verification-send',
        ];
        foreach ($expect as $name => $middleware) {
            $this->assertContains($middleware, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
            $this->assertContains($middleware, Route::getRoutes()->getByName($name.'.localized')->gatherMiddleware(), $name);
        }
    }

    public function test_login_is_limited_per_identity_and_ip_with_a_429(): void
    {
        for ($i = 0; $i < Limits::LOGIN_PER_IDENTITY; $i++) {
            $this->assertSame(302, $this->failLogin('victim@example.test'));
        }
        $response = $this->fromIp('10.0.0.1')->post('/login', ['email' => 'victim@example.test', 'password' => 'x']);
        $response->assertStatus(429)->assertHeader('Retry-After');

        // Another identity from the same IP, and the same identity from another IP, still work.
        $this->assertSame(302, $this->failLogin('someone-else@example.test'));
        $this->assertSame(302, $this->failLogin('victim@example.test', '10.0.0.2'));
    }

    public function test_login_ip_bucket_caps_spraying_many_identities(): void
    {
        for ($i = 0; $i < Limits::LOGIN_PER_IP; $i++) {
            $this->assertSame(302, $this->failLogin("spray{$i}@example.test", '10.0.0.9'));
        }
        $this->assertSame(429, $this->failLogin('spray-last@example.test', '10.0.0.9'));
        $this->assertSame(302, $this->failLogin('spray-last@example.test', '10.0.0.10'), 'no global bucket');
    }

    public function test_known_and_unknown_accounts_are_throttled_identically(): void
    {
        User::factory()->create(['email' => 'known@example.test']);
        $known = [];
        $unknown = [];
        for ($i = 0; $i <= Limits::LOGIN_PER_IDENTITY; $i++) {
            $known[] = $this->failLogin('known@example.test', '10.0.1.1');
            $unknown[] = $this->failLogin('unknown@example.test', '10.0.1.2');
        }
        $this->assertSame($known, $unknown);
        $this->assertSame(429, end($known));
    }

    public function test_unprefixed_and_localized_login_share_the_bucket(): void
    {
        for ($i = 0; $i < Limits::LOGIN_PER_IDENTITY; $i++) {
            $this->failLogin('twin@example.test', '10.0.2.1', $i % 2 === 0 ? '/login' : '/en/login');
        }
        $this->assertSame(429, $this->failLogin('twin@example.test', '10.0.2.1', '/en/login'));
    }

    public function test_spoofed_forwarded_for_from_an_untrusted_client_does_not_reset_the_bucket(): void
    {
        for ($i = 0; $i < Limits::LOGIN_PER_IDENTITY; $i++) {
            $this->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])->from('10.0.3.1')
                ->post('/login', ['email' => 'spoof@example.test', 'password' => 'x']);
        }
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.200'])->from('10.0.3.1')
            ->post('/login', ['email' => 'spoof@example.test', 'password' => 'x'])->assertStatus(429);
    }

    public function test_register_is_limited_per_ip(): void
    {
        for ($i = 0; $i < Limits::REGISTER_PER_IP; $i++) {
            $this->fromIp('10.0.4.1')->post('/register', ['name' => '', 'email' => "r{$i}@example.test"])->assertStatus(302);
        }
        $this->fromIp('10.0.4.1')->post('/register', ['name' => ''])->assertStatus(429);
        $this->fromIp('10.0.4.2')->post('/register', ['name' => ''])->assertStatus(302);
    }

    public function test_forgot_password_is_limited_and_never_reveals_an_account(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'has-account@example.test']);

        $known = $this->fromIp('10.0.5.1')->post('/forgot-password', ['email' => 'has-account@example.test']);
        $unknown = $this->fromIp('10.0.5.2')->post('/forgot-password', ['email' => 'no-account@example.test']);
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));

        // The broker's own throttle (existing account only) must not leak either.
        $again = $this->fromIp('10.0.5.1')->post('/forgot-password', ['email' => 'has-account@example.test']);
        $this->assertSame($unknown->getSession()->get('status'), $again->getSession()->get('status'));
        $again->assertSessionHasNoErrors();

        for ($i = 2; $i < Limits::PASSWORD_EMAIL_PER_IDENTITY; $i++) {
            $this->fromIp('10.0.5.1')->post('/forgot-password', ['email' => 'has-account@example.test']);
        }
        $this->fromIp('10.0.5.1')->post('/forgot-password', ['email' => 'has-account@example.test'])->assertStatus(429);
        $this->fromIp('10.0.5.3')->post('/forgot-password', ['email' => 'has-account@example.test'])->assertStatus(302);
    }

    public function test_reset_is_limited_per_identity(): void
    {
        $payload = ['token' => 'bogus', 'email' => 'reset@example.test', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'];
        for ($i = 0; $i < Limits::PASSWORD_RESET_PER_IDENTITY; $i++) {
            $this->fromIp('10.0.6.1')->post('/reset-password', $payload)->assertStatus(302);
        }
        $this->fromIp('10.0.6.1')->post('/en/reset-password', $payload)->assertStatus(429);
    }

    public function test_verification_resend_is_limited_per_user(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->unverified()->create());
        for ($i = 0; $i < Limits::VERIFICATION_SEND_PER_USER; $i++) {
            $this->post('/email/verification-notification')->assertStatus(302);
        }
        $this->post('/email/verification-notification')->assertStatus(429);
    }

    public function test_admin_login_is_not_double_throttled(): void
    {
        $admin = collect(Route::getRoutes()->getRoutes())->first(static fn ($r) => $r->getName() === 'filament.admin.auth.login');
        $this->assertNotNull($admin);
        foreach ($admin->gatherMiddleware() as $middleware) {
            $this->assertStringNotContainsString('cms-auth-', (string) $middleware);
        }
    }
}
