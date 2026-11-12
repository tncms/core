<?php

declare(strict_types=1);

namespace Tests\Feature\AuthUrl;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-P14-1 — Authentication URL Policy regression: intended-destination
 * capture and validation, auth-page locale under any middleware order, and the
 * guarantees the P14-1a trusted origin and P12-4 middleware already give.
 */
final class AuthUrlPolicyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);

        $define = static function (string $suffix): void {
            Route::middleware(['web', 'cms.permission:p14.secret', 'cms.locale'])
                ->get('p14-plugin/permission', static fn () => response('ok'))->name('p14reg.permission'.$suffix);
            Route::middleware(['web', 'cms.role:p14-role', 'cms.locale'])
                ->get('p14-plugin/role', static fn () => response('ok'))->name('p14reg.role'.$suffix);
            Route::middleware(['web', 'cms.verified', 'cms.locale'])
                ->get('p14-plugin/verified', static fn () => response('ok'))->name('p14reg.verified'.$suffix);
            Route::middleware(['web', 'cms.auth', 'cms.locale'])
                ->get('p14-plugin/json', static fn () => response()->json(['ok' => true]))->name('p14reg.json'.$suffix);
        };
        $define('');
        Route::prefix('{locale}')->where(['locale' => 'en'])->middleware('cms.canonical-locale')->group(static fn () => $define('.localized'));

        $router = app('router');
        $all = $router->getRoutes()->getRoutes();
        $mine = array_filter($all, static fn ($r) => str_starts_with((string) $r->getName(), 'p14reg'));
        $ordered = new \Illuminate\Routing\RouteCollection;
        foreach ([...$mine, ...array_diff_key($all, $mine)] as $route) {
            $ordered->add($route);
        }
        $router->setRoutes($ordered);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => 'p14r@example.test', 'password' => bcrypt('secret-pass-1')]);
    }

    private function login(string $path = '/en/login'): \Illuminate\Testing\TestResponse
    {
        return $this->post($path, ['email' => 'p14r@example.test', 'password' => 'secret-pass-1']);
    }

    // ---- Locale of the auth page under any middleware order ----

    /** @return array<string, array{0: string}> */
    public static function guardedPluginPaths(): array
    {
        return ['permission' => ['p14-plugin/permission'], 'role' => ['p14-plugin/role'], 'verified' => ['p14-plugin/verified']];
    }

    #[DataProvider('guardedPluginPaths')]
    public function test_guest_redirect_keeps_the_route_locale_whatever_the_middleware_order(string $path): void
    {
        $this->get('/en/'.$path)->assertRedirect('http://localhost/en/login');
        $this->get('/'.$path)->assertRedirect('http://localhost/login');
    }

    public function test_unverified_user_notice_keeps_the_route_locale(): void
    {
        app('cms.settings')->set('auth.email_verification_required', true);
        $this->actingAs(User::factory()->unverified()->create(), 'web');

        $this->get('/en/p14-plugin/verified')->assertRedirect('http://localhost/en/email/verify');
    }

    public function test_a_json_client_still_gets_401_and_nothing_is_stored(): void
    {
        $this->getJson('/en/p14-plugin/json')->assertStatus(401);
        $this->assertNull(session('url.intended'));
    }

    public function test_a_post_clears_an_older_stored_destination(): void
    {
        $this->withSession(['url.intended' => 'http://localhost/en/account/security'])
            ->post('/en/logout')
            ->assertRedirect('http://localhost/en/login');

        $this->assertNull(session('url.intended'), 'a refused POST leaves a deterministic fallback, not an older page');
    }

    // ---- Intended destinations followed after sign-in ----

    /** @return array<string, array{0: string}> */
    public static function refusedDestinations(): array
    {
        return [
            'livewire update endpoint' => ['http://localhost/livewire-0123abcd/update'],
            'livewire asset' => ['http://localhost/livewire/livewire.js'],
            'localized livewire' => ['http://localhost/en/livewire-0123abcd/update'],
            'protocol-relative' => ['//evil.test/'],
            'external' => ['https://evil.test/en/account'],
            'forged-host style other host' => ['http://evil.test/en/account'],
            'tab in path' => ["http://localhost/\t/evil.test"],
            'encoded login loop' => ['http://localhost/en/%6cogin'],
            'data scheme' => ['data:text/html,x'],
        ];
    }

    #[DataProvider('refusedDestinations')]
    public function test_refused_destinations_fall_back_to_the_localized_account(string $intended): void
    {
        $this->user();

        $this->withSession(['url.intended' => $intended])->post('/en/login', ['email' => 'p14r@example.test', 'password' => 'secret-pass-1'])
            ->assertRedirect('http://localhost/en/account');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function keptOnThisOrigin(): array
    {
        return [
            'encoded slashes stay a path' => ['http://localhost/%2F%2Fevil.test', 'http://localhost/%2F%2Fevil.test'],
            'encoded backslash stays a path' => ['http://localhost/%5Cevil.test', 'http://localhost/%5Cevil.test'],
            'safe query kept in order' => ['http://localhost/en/account/sessions?b=2&a=1', 'http://localhost/en/account/sessions?b=2&a=1'],
            'sensitive keys dropped' => ['http://localhost/en/account?Token=x&tab=2&signature=y&password[]=z', 'http://localhost/en/account?tab=2'],
            'only sensitive keys' => ['http://localhost/en/account/profile?access_token=x', 'http://localhost/en/account/profile'],
            'oauth-style keys dropped' => ['http://localhost/en/account?code=c&state=s&_token=t&sig=g&page=3', 'http://localhost/en/account?page=3'],
        ];
    }

    #[DataProvider('keptOnThisOrigin')]
    public function test_kept_destinations_stay_on_this_origin(string $intended, string $expected): void
    {
        $this->user();

        $this->withSession(['url.intended' => $intended])->login()->assertRedirect($expected);
    }

    public function test_the_guest_redirect_from_a_page_returns_there_after_sign_in(): void
    {
        $this->user();
        $this->get('/en/account/sessions?page=2')->assertRedirect('http://localhost/en/login');

        $this->login()->assertRedirect('http://localhost/en/account/sessions?page=2');
    }

    public function test_a_forged_host_cannot_turn_a_stored_destination_into_another_origin(): void
    {
        $this->user();
        $this->get('/en/account/profile')->assertRedirect('http://localhost/en/login');

        $response = $this->post('http://evil.test/en/login', ['email' => 'p14r@example.test', 'password' => 'secret-pass-1']);

        $this->assertStringNotContainsString('/en/account/profile', (string) $response->headers->get('Location'), 'a value captured on one origin is not replayed onto another');
        $this->assertAuthenticated('web');
    }

    public function test_an_authenticated_visitor_on_the_english_login_goes_to_the_english_account(): void
    {
        $this->actingAs($this->user(), 'web');

        $this->get('/en/login')->assertRedirect('http://localhost/en/account');
    }

    public function test_english_logout_returns_to_the_english_home(): void
    {
        $this->actingAs($this->user(), 'web');

        $location = (string) $this->post('/en/logout')->headers->get('Location');
        $this->assertSame('/en', rtrim((string) parse_url($location, PHP_URL_PATH), '/'));
        $this->assertGuest('web');
    }
}
