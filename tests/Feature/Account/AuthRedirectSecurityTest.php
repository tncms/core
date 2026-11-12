<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — the post-sign-in intended destination
 * (FrontendIntendedUrl) only ever leads back into this site, never to an auth page,
 * and is consumed once.
 */
final class AuthRedirectSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->user = User::factory()->create(['email' => 'redir@example.test', 'password' => bcrypt('secret-pass-1')]);
    }

    private function loginWithIntended(mixed $intended, string $path = '/login'): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['url.intended' => $intended])
            ->post($path, ['email' => 'redir@example.test', 'password' => 'secret-pass-1']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejected(): array
    {
        return [
            'external absolute' => ['https://evil.test/account'],
            'external same path' => ['http://evil.test/account/profile'],
            'protocol-relative' => ['//evil.test/account'],
            'protocol-relative path on own host' => ['http://localhost//evil.test/x'],
            'backslash path' => ['http://localhost/\\evil.test'],
            'userinfo' => ['http://attacker@localhost/account'],
            'other scheme' => ['javascript:alert(1)'],
            'other port' => ['http://localhost:8081/account'],
            'header injection' => ["http://localhost/account\r\nSet-Cookie: x=1"],
            'non-string' => [['http://localhost/account']],
            'loop: login' => ['http://localhost/login'],
            'loop: localized login' => ['http://localhost/en/login'],
            'loop: register' => ['http://localhost/register'],
            'loop: logout' => ['http://localhost/en/logout'],
            'loop: reset' => ['http://localhost/reset-password/abc'],
            'loop: verification' => ['http://localhost/en/email/verify'],
            'loop: encoded login' => ['http://localhost/%6Cogin'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_unsafe_intended_targets_fall_back_to_the_account(mixed $intended): void
    {
        $this->loginWithIntended($intended)->assertRedirect(url('/account'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_unsafe_intended_target_falls_back_to_the_localized_account(): void
    {
        $this->loginWithIntended('https://evil.test/', '/en/login')->assertRedirect(url('/en/account'));
    }

    public function test_same_origin_intended_target_is_kept_with_its_query(): void
    {
        $this->loginWithIntended('http://localhost/en/account/profile?tab=bio&x=1')
            ->assertRedirect('http://localhost/en/account/profile?tab=bio&x=1');
    }

    public function test_encoded_path_stays_on_this_origin(): void
    {
        $location = $this->loginWithIntended('http://localhost/%2F%2Fevil.test/x')->headers->get('Location');
        $this->assertSame('http://localhost/%2F%2Fevil.test/x', $location);
        $this->assertSame('localhost', parse_url((string) $location, PHP_URL_HOST));
    }

    public function test_intended_target_is_consumed_once_and_never_reused(): void
    {
        $this->loginWithIntended('http://localhost/account/security')->assertRedirect('http://localhost/account/security');
        $this->assertNull(session('url.intended'));

        $this->post('/logout');
        $this->post('/login', ['email' => 'redir@example.test', 'password' => 'secret-pass-1'])->assertRedirect(url('/account'));
    }

    public function test_signed_in_visitor_on_a_guest_page_is_not_redirected_off_site(): void
    {
        $this->actingAs($this->user)->withSession(['url.intended' => 'https://evil.test/'])
            ->get('/en/login')->assertRedirect(url('/en/account'));
    }

    public function test_failed_login_does_not_consume_the_intended_target(): void
    {
        $this->withSession(['url.intended' => 'http://localhost/account/sessions'])
            ->post('/login', ['email' => 'redir@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'redir@example.test', 'password' => 'secret-pass-1'])
            ->assertRedirect('http://localhost/account/sessions');
    }
}
