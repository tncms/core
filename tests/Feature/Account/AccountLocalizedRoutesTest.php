<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — locale-prefixed Core auth/account routes.
 *
 * Every Core auth/account route has a {locale} twin named "<name>.localized"; the
 * unprefixed routes and names are unchanged. An explicit active prefix decides the
 * locale; a default-locale prefix 301s to the canonical path; an inactive prefix
 * never renders. Links, redirects and mail links follow the request locale.
 * Fixture: Vietnamese default, English active (the shipped seed).
 */
final class AccountLocalizedRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** Every Core auth/account route name with its unprefixed URI. */
    private const ROUTES = [
        'cms.auth.login' => 'login', 'cms.auth.login.attempt' => 'login',
        'cms.auth.register' => 'register', 'cms.auth.register.store' => 'register',
        'password.request' => 'forgot-password', 'password.email' => 'forgot-password',
        'password.reset' => 'reset-password/{token}', 'password.update' => 'reset-password',
        'cms.auth.logout' => 'logout', 'cms.auth.verification.notice' => 'email/verify',
        'verification.verify' => 'email/verify/{id}/{hash}', 'verification.send' => 'email/verification-notification',
        'cms.account' => 'account', 'cms.account.profile' => 'account/profile', 'cms.account.profile.update' => 'account/profile',
        'cms.account.profile.fields.update' => 'account/profile/fields',
        'cms.account.security' => 'account/security', 'cms.account.security.password' => 'account/security/password',
        'cms.account.security.email' => 'account/security/email',
        'cms.account.sessions' => 'account/sessions', 'cms.account.sessions.logout-others' => 'account/sessions/logout-others',
        'cms.account.preferences' => 'account/preferences', 'cms.account.preferences.update' => 'account/preferences',
        'cms.account.media.index' => 'account/media', 'cms.account.media.store' => 'account/media',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['email' => 'loc-owner@example.test']);
    }

    // ---- route table

    public function test_every_route_keeps_its_name_and_uri_and_gains_a_localized_twin(): void
    {
        foreach (self::ROUTES as $name => $uri) {
            $this->assertSame($uri, Route::getRoutes()->getByName($name)?->uri(), $name);
            $twin = Route::getRoutes()->getByName($name.'.localized');
            $this->assertNotNull($twin, $name.'.localized');
            $this->assertSame('{locale}/'.$uri, $twin->uri(), $name);
            $this->assertContains('cms.locale', $twin->gatherMiddleware(), $name);
            $this->assertContains('cms.canonical-locale', $twin->gatherMiddleware(), $name);
        }

        $this->assertSame(url('/login'), route('cms.auth.login'));
        $this->assertSame(url('/account/profile'), route('cms.account.profile'));
    }

    public function test_auth_routes_are_registered_before_the_frontend_catch_all(): void
    {
        $names = array_map(static fn ($r) => $r->getName(), Route::getRoutes()->getRoutes());
        $catchAll = array_search('cms.resolve.localized', $names, true);
        $this->assertIsInt($catchAll);
        foreach (['cms.auth.login.localized', 'cms.account.profile.localized', 'verification.verify.localized'] as $name) {
            $this->assertLessThan($catchAll, array_search($name, $names, true), $name);
        }
    }

    // ---- rendering in the prefix locale

    public function test_prefixed_auth_pages_render_in_the_prefix_locale(): void
    {
        foreach (['/en/login', '/en/register', '/en/forgot-password', '/en/reset-password/tok-123?email=a@example.test'] as $path) {
            $this->get($path)->assertOk()->assertSee('<html lang="en">', false);
        }
        $this->get('/en/login')->assertSee('Sign in')->assertSee('action="'.url('/en/login').'"', false)
            ->assertSee('href="'.url('/en/forgot-password').'"', false);
        $this->get('/login')->assertOk()->assertSee('<html lang="vi">', false)->assertSee('Đăng nhập');
    }

    public function test_prefixed_reset_page_receives_the_token_not_the_locale(): void
    {
        $this->get('/en/reset-password/tok-123?email=a@example.test')->assertOk()
            ->assertSee('value="tok-123"', false)
            ->assertSee('action="'.url('/en/reset-password').'"', false);
    }

    public function test_prefixed_account_pages_render_in_english_with_localized_links(): void
    {
        $this->actingAs($this->user());

        foreach (['/en/account', '/en/account/profile', '/en/account/security', '/en/account/preferences', '/en/account/sessions'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('<html lang="en">', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertSee('href="'.url('/en/account/profile').'"', false)
                ->assertSee('href="'.url('/en/account/security').'"', false)
                ->assertSee('action="'.url('/en/logout').'"', false)
                ->assertDontSee('href="'.url('/account/profile').'"', false);
        }

        $this->get('/en/account/security')->assertSee('aria-current="page"', false)
            ->assertSee('action="'.url('/en/account/security/password').'"', false);
        $this->get('/account')->assertOk()->assertSee('<html lang="vi">', false)
            ->assertSee('Bảng điều khiển')
            ->assertSee('href="'.url('/account/profile').'"', false);
    }

    public function test_default_locale_prefix_redirects_to_the_canonical_path(): void
    {
        $this->get('/vi/login')->assertStatus(301)->assertHeader('Location', '/login');
        $this->actingAs($this->user())->get('/vi/account/profile?tab=x')->assertStatus(301)->assertHeader('Location', '/account/profile?tab=x');
    }

    public function test_inactive_or_unknown_prefix_never_renders_a_page(): void
    {
        $this->get('/fr/login')->assertNotFound();

        // A language registered at boot but deactivated later is refused by Core's
        // locale runtime instead of rendering in another language.
        app('cms.language')->update(\TheNguyen\CMS\Models\Language::query()->where('code', 'en')->firstOrFail(), ['is_active' => false]);
        $this->get('/en/login')->assertNotFound();
        $this->actingAs($this->user())->get('/en/account')->assertNotFound();
    }

    // ---- redirects and links keep the locale

    public function test_guest_is_sent_to_the_localized_login_and_back_to_the_intended_page(): void
    {
        $this->get('/en/account/profile?tab=bio')->assertRedirect(url('/en/login'));
        $this->get('/account/security')->assertRedirect(url('/login'));

        $user = $this->user(['password' => bcrypt('secret-pass-1')]);
        $this->get('/en/account/profile?tab=bio');
        $this->post('/en/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertRedirect(url('/en/account/profile?tab=bio'));
    }

    public function test_login_without_intended_lands_on_the_localized_account(): void
    {
        $user = $this->user(['password' => bcrypt('secret-pass-1')]);
        $this->post('/en/login', ['email' => $user->email, 'password' => 'secret-pass-1'])->assertRedirect(url('/en/account'));
    }

    public function test_logout_keeps_the_locale(): void
    {
        $this->actingAs($this->user());
        $this->post('/en/logout')->assertRedirect(url('/en'));
        $this->assertGuest();
    }

    public function test_account_form_redirects_keep_the_locale(): void
    {
        $this->actingAs($this->user());
        $this->post('/en/account/preferences', ['frontend_locale' => '', 'timezone' => ''])
            ->assertRedirect(url('/en/account/preferences'));
    }

    public function test_reset_and_verification_mail_links_follow_the_request_locale(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post('/en/forgot-password', ['email' => $user->email])->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
            return str_starts_with($n->toMail($user)->actionUrl, url('/en/reset-password/'));
        });

        app('cms.language')->setCurrent('en');
        $link = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->assertStringStartsWith(url('/en/email/verify/'.$user->id.'/'), $link);

        app('cms.language')->setCurrent('vi');
        $this->assertStringStartsWith(url('/email/verify/'.$user->id.'/'), (new VerifyEmail)->toMail($user)->actionUrl);
    }

    public function test_localized_verification_link_is_valid_and_tamper_evident(): void
    {
        $user = User::factory()->unverified()->create();
        app('cms.language')->setCurrent('en');
        $link = (new VerifyEmail)->toMail($user)->actionUrl;

        $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
        $this->get(str_replace('/en/email/', '/vi/email/', $link))->assertStatus(301); // canonical first, then invalid
        $this->get(str_replace('/en/email/', '/email/', $link))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        $this->get($link)->assertRedirect(url('/en/login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    // ---- session strategy: explicit prefix wins, links stay unprefixed

    public function test_session_strategy_applies_the_preference_and_an_explicit_prefix_wins(): void
    {
        app('cms.settings')->set('language.routing_strategy', 'session', 'string');
        $this->actingAs($this->user(['frontend_locale' => 'en']));

        $this->get('/account')->assertOk()->assertSee('<html lang="en">', false)
            ->assertSee('href="'.url('/account/profile').'"', false)  // session mode never prefixes
            ->assertDontSee(url('/en/account'), false);

        $this->actingAs($this->user(['email' => 'pref-vi@example.test', 'frontend_locale' => 'vi']));
        $this->get('/en/account')->assertOk()->assertSee('<html lang="en">', false);
    }
}
