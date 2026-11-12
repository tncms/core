<?php

declare(strict_types=1);

namespace Tests\Feature\AuthUrl;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Fixtures\LivewireLocale\LocaleProbe;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-P14-1 — reproduction of the authentication URL lifecycle defects
 * against the P12-4 baseline (fea47ac8). Each test states the contract; on the
 * baseline the confirmed defects fail, after the fix they pass.
 *
 * Requests mirror the real browser: a Livewire update is a POST with
 * "Content-type: application/json" + "X-Livewire: 1", no JSON Accept header,
 * and the page URL as Referer (so a refused update gets a redirect, not a 401).
 */
final class AuthUrlPolicyReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        Livewire::component('p14-probe', LocaleProbe::class);

        $define = static function (string $suffix): void {
            Route::middleware(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'])
                ->get('account/p14-probe', static fn () => response(Blade::render('<livewire:p14-probe />')))
                ->name('p14probe.page'.$suffix);
            // A plugin that lists the auth middleware before the locale middleware.
            Route::middleware(['web', 'cms.auth', 'cms.locale'])
                ->get('p14-plugin/area', static fn () => response(Blade::render('<livewire:p14-probe />')))
                ->name('p14probe.plugin'.$suffix);
            Route::middleware(['web', 'cms.locale', 'cms.auth'])
                ->post('p14-plugin/action', static fn () => response('done'))
                ->name('p14probe.action'.$suffix);
        };
        $define('');
        Route::prefix('{locale}')->where(['locale' => 'en'])->middleware('cms.canonical-locale')->group(static fn () => $define('.localized'));

        $router = app('router');
        $all = $router->getRoutes()->getRoutes();
        $mine = array_filter($all, static fn ($r) => str_starts_with((string) $r->getName(), 'p14probe'));
        $ordered = new \Illuminate\Routing\RouteCollection;
        foreach ([...$mine, ...array_diff_key($all, $mine)] as $route) {
            $ordered->add($route);
        }
        $router->setRoutes($ordered);
    }

    private function newProcess(): void
    {
        (new \ReflectionProperty(\TheNguyen\CMS\Services\LanguageManager::class, 'currentCode'))->setValue(app('cms.language'), null);
        app()->setLocale((string) config('app.locale'));
        app('livewire')->flushState();
    }

    private function snapshot(string $path): string
    {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'component rendered');

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** A browser-shaped Livewire update (no JSON Accept header). */
    private function browserUpdate(string $snapshot, string $referer): \Illuminate\Testing\TestResponse
    {
        $this->newProcess();
        $body = json_encode(['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'bump', 'params' => []]]]]]);

        return $this->call('POST', app('livewire')->getUpdateUri(), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LIVEWIRE' => '1',
            'HTTP_ACCEPT' => '*/*',
            'HTTP_REFERER' => $referer,
        ], $body);
    }

    private function revoke(User $user): void
    {
        $user->forceFill(['frontend_session_version' => (int) $user->frontend_session_version + 1])->saveQuietly();
    }

    // ---- A. Localized session revocation / expiry on a Livewire update ----

    public function test_a_revoked_english_account_tab_is_sent_to_the_english_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $snapshot = $this->snapshot('/en/account/p14-probe');

        $this->revoke($user);
        $response = $this->browserUpdate($snapshot, 'http://localhost/en/account/p14-probe');

        $response->assertRedirect('http://localhost/en/login');
        $this->assertGuest('web');
    }

    public function test_a_revoked_vietnamese_account_tab_is_sent_to_the_canonical_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $snapshot = $this->snapshot('/account/p14-probe');

        $this->revoke($user);
        $this->browserUpdate($snapshot, 'http://localhost/account/p14-probe')->assertRedirect('http://localhost/login');
    }

    public function test_a_guest_update_on_a_plugin_route_listing_auth_before_locale_keeps_english(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $snapshot = $this->snapshot('/en/p14-plugin/area');

        $this->revoke($user);
        $this->browserUpdate($snapshot, 'http://localhost/en/p14-plugin/area')->assertRedirect('http://localhost/en/login');
    }

    public function test_a_guest_page_get_on_a_plugin_route_listing_auth_before_locale_keeps_english(): void
    {
        $this->get('/en/p14-plugin/area')->assertRedirect('http://localhost/en/login');
    }

    // ---- B. Intended destination captured from non-GET requests ----

    public function test_a_post_never_stores_its_referer_as_the_intended_destination(): void
    {
        $this->post('/en/p14-plugin/action', [], ['Referer' => 'http://localhost/en/account/security?x=1'])
            ->assertRedirect('http://localhost/en/login');

        $this->assertNull(session('url.intended'), 'a POST is not a navigable destination; the Referer is untrusted');
    }

    public function test_a_revoked_livewire_update_does_not_store_its_referer_as_the_intended_destination(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $snapshot = $this->snapshot('/en/account/p14-probe');

        $this->revoke($user);
        $this->browserUpdate($snapshot, 'http://localhost/en/account/security');

        $this->assertSame(
            'http://localhost/en/account/p14-probe',
            session('url.intended'),
            'the tab\'s own page (from the checksummed snapshot) is remembered, never the untrusted Referer',
        );
    }

    // ---- C. Localized login and return ----

    public function test_an_english_protected_page_logs_in_on_english_and_returns(): void
    {
        $user = User::factory()->create(['email' => 'p14c@example.test', 'password' => bcrypt('secret-pass-1')]);

        $this->get('/en/account/p14-probe?tab=2')->assertRedirect('http://localhost/en/login');
        $this->assertSame('http://localhost/en/account/p14-probe?tab=2', session('url.intended'));

        $this->post('/en/login', ['email' => 'p14c@example.test', 'password' => 'secret-pass-1'])
            ->assertRedirect('http://localhost/en/account/p14-probe?tab=2');
        $this->assertAuthenticatedAs($user, 'web');
    }

    // ---- E. Destinations that must never be stored or followed ----

    public function test_a_livewire_endpoint_is_never_a_post_login_destination(): void
    {
        User::factory()->create(['email' => 'p14e@example.test', 'password' => bcrypt('secret-pass-1')]);

        $this->withSession(['url.intended' => 'http://localhost'.app('livewire')->getUpdateUri()])
            ->post('/en/login', ['email' => 'p14e@example.test', 'password' => 'secret-pass-1'])
            ->assertRedirect('http://localhost/en/account');
    }

    public function test_a_sensitive_query_string_is_not_carried_into_the_intended_destination(): void
    {
        $this->get('/en/account/p14-probe?token=abc123&tab=2')->assertRedirect('http://localhost/en/login');

        $this->assertStringNotContainsString('abc123', (string) session('url.intended'));
    }
}
