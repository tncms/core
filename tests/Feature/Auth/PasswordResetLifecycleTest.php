<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1 — the password-reset lifecycle around the
 * trusted-origin link: the mailed link opens the reset form, a valid token resets
 * once, and invalid, expired, reused and cross-account tokens all fail.
 */
final class PasswordResetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://www.example.test';

    private const OLD = 'old-password-123';

    private const NEW = 'brand-new-password-456';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::SITE, 'mail.default' => 'array']);
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function user(string $email): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make(self::OLD)]);
    }

    /** Request a reset on a forged host and return the mailed link. */
    private function requestLink(User $user, string $path = '/forgot-password', string $ip = '10.7.0.1'): string
    {
        $link = null;
        Event::listen(NotificationSent::class, function (NotificationSent $e) use ($user, &$link): void {
            if ($e->notification instanceof ResetPassword && $e->notifiable->is($user)) {
                $link = $e->notification->toMail($user)->actionUrl;
            }
        });

        $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('http://evil.example'.$path, ['email' => $user->email])->assertStatus(302);
        $this->assertNotNull($link);

        return (string) $link;
    }

    /** @return array{0: string, 1: string} token and email from a mailed link */
    private function parts(string $link): array
    {
        preg_match('~/reset-password/([A-Za-z0-9]+)\?email=([^&]+)$~', $link, $m);

        return [$m[1], urldecode($m[2])];
    }

    private function submit(string $token, string $email, string $path = '/reset-password', string $ip = '10.7.1.1'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post(self::SITE.$path, [
            'token' => $token, 'email' => $email,
            'password' => self::NEW, 'password_confirmation' => self::NEW,
        ]);
    }

    public function test_the_mailed_link_opens_the_reset_form_on_the_configured_origin(): void
    {
        foreach (['/forgot-password' => '/reset-password/', '/en/forgot-password' => '/en/reset-password/'] as $from => $to) {
            $link = $this->requestLink($this->user('form'.strlen($from).'@example.test'), $from, '10.7.0.'.strlen($from));
            $this->assertStringStartsWith(self::SITE.$to, $link);

            $page = $this->get($link);
            $page->assertOk();
            [$token] = $this->parts($link);
            $page->assertSee($token, false);
        }
    }

    public function test_a_valid_token_resets_once_and_cannot_be_reused(): void
    {
        $user = $this->user('valid@example.test');
        [$token, $email] = $this->parts($this->requestLink($user));

        $this->submit($token, $email)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $email)->count(), 'token consumed');

        $user->forceFill(['password' => Hash::make(self::OLD)])->save();
        $this->submit($token, $email, ip: '10.7.1.2')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password), 'a reused token changes nothing');
    }

    public function test_an_invalid_token_fails(): void
    {
        $user = $this->user('invalid@example.test');
        [, $email] = $this->parts($this->requestLink($user));

        $this->submit(str_repeat('a', 64), $email)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    public function test_an_expired_token_fails(): void
    {
        $user = $this->user('expired@example.test');
        [$token, $email] = $this->parts($this->requestLink($user));

        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();
        $this->submit($token, $email)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::OLD, $user->fresh()->password));
    }

    public function test_a_token_for_another_account_fails(): void
    {
        $victim = $this->user('victim@example.test');
        $attacker = $this->user('attacker@example.test');
        [$attackerToken] = $this->parts($this->requestLink($attacker, ip: '10.7.0.9'));

        $this->submit($attackerToken, $victim->email)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::OLD, $victim->fresh()->password));
    }
}
