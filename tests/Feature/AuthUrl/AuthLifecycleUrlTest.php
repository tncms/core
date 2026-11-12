<?php

declare(strict_types=1);

namespace Tests\Feature\AuthUrl;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CORE-P14-1 — the URL side of the login / registration / password-reset
 * lifecycle: every redirect is Core-built and localized, never the Referer;
 * the reset form is not an account-existence oracle; localized twins share
 * the same throttle buckets; sessions are regenerated on sign-in.
 */
final class AuthLifecycleUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://www.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::SITE, 'mail.default' => 'array']);
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function user(string $email = 'life@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make('old-password-123')]);
    }

    public function test_forgot_password_returns_to_its_own_localized_form_not_the_referer(): void
    {
        $this->user();

        $this->post(self::SITE.'/en/forgot-password', ['email' => 'life@example.test'], ['Referer' => 'https://evil.test/phish'])
            ->assertRedirect(self::SITE.'/en/forgot-password')
            ->assertSessionHas('status');

        $this->post(self::SITE.'/forgot-password', ['email' => 'nobody@example.test'], ['Referer' => self::SITE.'/en/account'])
            ->assertRedirect(self::SITE.'/forgot-password');
    }

    public function test_forgot_password_response_is_identical_for_known_and_unknown_addresses(): void
    {
        $this->user();

        $known = $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->post(self::SITE.'/en/forgot-password', ['email' => 'life@example.test']);
        $knownStatus = session('status');
        $unknown = $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])->post(self::SITE.'/en/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertSame($knownStatus, session('status'));
    }

    public function test_the_reset_form_does_not_reveal_whether_an_address_has_an_account(): void
    {
        $this->user();
        $payload = ['token' => str_repeat('b', 64), 'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456'];

        $known = $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.1'])->post(self::SITE.'/en/reset-password', $payload + ['email' => 'life@example.test']);
        $knownError = session('errors')?->first('email');
        $unknown = $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.2'])->post(self::SITE.'/en/reset-password', $payload + ['email' => 'nobody@example.test']);
        $unknownError = session('errors')?->first('email');

        $this->assertNotNull($knownError);
        $this->assertSame($knownError, $unknownError, 'unknown address and bad token give the same error');
        $this->assertSame($known->status(), $unknown->status());
    }

    public function test_a_successful_reset_goes_to_the_localized_login_and_revokes_other_sessions(): void
    {
        $user = $this->user();
        $version = (int) $user->frontend_session_version;
        $link = null;
        Event::listen(NotificationSent::class, function (NotificationSent $e) use (&$link): void {
            if ($e->notification instanceof ResetPassword) {
                $link = $e->notification->toMail($e->notifiable)->actionUrl;
            }
        });
        $this->post('http://forged.example/en/forgot-password', ['email' => 'life@example.test']);

        $this->assertStringStartsWith(self::SITE.'/en/reset-password/', (string) $link, 'configured origin, request locale');
        preg_match('~/reset-password/([A-Za-z0-9]+)\?~', (string) $link, $m);

        $this->post(self::SITE.'/en/reset-password', ['token' => $m[1], 'email' => 'life@example.test', 'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456'])
            ->assertRedirect(self::SITE.'/en/login');

        $this->assertGreaterThan($version, (int) $user->fresh()->frontend_session_version, 'other sessions are revoked');
        $this->assertGuest('web');
    }

    public function test_login_regenerates_the_session_and_follows_the_localized_default(): void
    {
        $this->user();
        $this->get(self::SITE.'/en/login')->assertOk();
        $before = session()->getId();

        $this->post(self::SITE.'/en/login', ['email' => 'life@example.test', 'password' => 'old-password-123'])
            ->assertRedirect(self::SITE.'/en/account');

        $this->assertNotSame($before, session()->getId(), 'session fixation: id regenerated on sign-in');
    }

    public function test_failed_login_does_not_reveal_the_account_and_keeps_the_destination(): void
    {
        $this->user();
        $this->get(self::SITE.'/en/account/profile');
        $intended = session('url.intended');

        $this->post(self::SITE.'/en/login', ['email' => 'life@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $wrongPassword = session('errors')->first('email');
        $this->post(self::SITE.'/en/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');

        $this->assertSame($wrongPassword, session('errors')->first('email'));
        $this->assertSame($intended, session('url.intended'));
    }

    public function test_localized_and_canonical_login_share_one_throttle_bucket(): void
    {
        $this->user();
        $server = ['REMOTE_ADDR' => '10.9.2.1'];

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables($server)->post(self::SITE.($i % 2 === 0 ? '/login' : '/en/login'), ['email' => 'life@example.test', 'password' => 'x'])->assertStatus(302);
        }

        $this->withServerVariables($server)->post(self::SITE.'/en/login', ['email' => 'LIFE@example.test ', 'password' => 'x'])->assertStatus(429);
    }

    public function test_registration_lands_on_a_localized_page(): void
    {
        app('cms.settings')->set('auth.registration_enabled', true);

        $response = $this->post(self::SITE.'/en/register', [
            'name' => 'New Person', 'email' => 'new@example.test',
            'password' => 'brand-new-password-456', 'password_confirmation' => 'brand-new-password-456',
        ]);

        $this->assertStringStartsWith(self::SITE.'/en/', (string) $response->headers->get('Location'));
        $this->assertNotNull(User::query()->where('email', 'new@example.test')->first(), 'registration ran');
    }

    public function test_disabled_registration_sends_to_the_localized_login(): void
    {
        app('cms.settings')->set('auth.registration_enabled', false);

        $this->get(self::SITE.'/en/register')->assertRedirect(self::SITE.'/en/login');
    }

    public function test_a_signed_in_user_on_any_guest_page_is_sent_to_the_localized_account(): void
    {
        $this->actingAs($this->user(), 'web');

        foreach (['/en/login', '/en/register', '/en/forgot-password', '/en/reset-password/abc'] as $path) {
            $this->get(self::SITE.$path)->assertRedirect(self::SITE.'/en/account');
        }
        $this->get(self::SITE.'/login')->assertRedirect(self::SITE.'/account');
    }

    public function test_auth_pages_are_noindex_and_unknown_locale_prefix_is_not_an_auth_page(): void
    {
        foreach (['/login', '/en/login', '/en/register', '/en/forgot-password', '/en/reset-password/abc'] as $path) {
            $this->assertStringContainsString('noindex', (string) $this->get(self::SITE.$path)->headers->get('X-Robots-Tag'), $path);
        }

        $this->assertNotSame(200, $this->get(self::SITE.'/fr/login')->status());
        $this->get(self::SITE.'/vi/login')->assertRedirect(self::SITE.'/login');
    }

    public function test_verification_resend_returns_to_the_localized_notice_not_the_referer(): void
    {
        $this->actingAs(User::factory()->unverified()->create(), 'web');

        $this->post(self::SITE.'/en/email/verification-notification', [], ['Referer' => 'https://evil.test/x'])
            ->assertRedirect(self::SITE.'/en/email/verify');
    }
}
