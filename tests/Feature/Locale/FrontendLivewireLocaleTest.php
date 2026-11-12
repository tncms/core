<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Fixtures\LivewireLocale\LocaleProbe;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-P12-4 — a plugin's frontend Livewire component on a Core-locale account
 * route keeps the request's locale and account context on its updates.
 *
 * Livewire posts every update to its own endpoint (`web` middleware only) and
 * re-applies only *persistent* middleware, resolved from the page's original
 * path. These tests drive the real update endpoint with the snapshot the page
 * rendered, and reset the per-request locale state in between, as a fresh PHP
 * process would.
 */
final class FrontendLivewireLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        Livewire::component('p12-locale-probe', LocaleProbe::class);

        $define = static function (string $suffix): void {
            Route::middleware(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'])
                ->get('account/p12-probe', static fn () => response(Blade::render('<livewire:p12-locale-probe />')))
                ->name('p12probe.page'.$suffix);
        };
        $define('');
        Route::middleware(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session', 'cms.role:p12-reviewer'])
            ->get('account/p12-probe-role', static fn () => response(Blade::render('<livewire:p12-locale-probe />')))
            ->name('p12probe.role');
        Route::prefix('{locale}')->where(['locale' => 'en'])->middleware('cms.canonical-locale')->group(static fn () => $define('.localized'));

        $router = app('router');
        $all = $router->getRoutes()->getRoutes();
        $mine = array_filter($all, static fn ($r) => str_starts_with((string) $r->getName(), 'p12probe'));
        $ordered = new \Illuminate\Routing\RouteCollection;
        foreach ([...$mine, ...array_diff_key($all, $mine)] as $route) {
            $ordered->add($route);
        }
        $router->setRoutes($ordered);
    }

    /** Per-request state a new PHP process starts without. */
    private function newProcess(): void
    {
        // Clear the request-scoped current locale on the shared instance (other
        // singletons, e.g. cms.localized_route_url, hold this same instance).
        (new \ReflectionProperty(\TheNguyen\CMS\Services\LanguageManager::class, 'currentCode'))->setValue(app('cms.language'), null);
        app()->setLocale((string) config('app.locale'));
        // Livewire's per-request memo (e.g. "persistent middleware already applied
        // for this route") — reset by its own test helpers, never shared by two real requests.
        app('livewire')->flushState();
    }

    /** @return array{snapshot: string, html: string} */
    private function page(string $path): array
    {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'component rendered');

        return ['snapshot' => html_entity_decode($m[1], ENT_QUOTES), 'html' => $html];
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: array<string, string>} */
    private function update(string $snapshot, array $calls = [['path' => '', 'method' => 'bump', 'params' => []]], array $updates = []): array
    {
        $this->newProcess();
        $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
        ]);

        $html = (string) ($response->json('components.0.effects.html') ?? '');
        preg_match_all('/data-k="([a-z-]+)">([^<]*)</', $html, $m);

        return [$response, array_combine($m[1], array_map(static fn ($v) => html_entity_decode($v, ENT_QUOTES), $m[2]))];
    }

    public function test_an_english_page_keeps_english_on_its_livewire_update(): void
    {
        $this->actingAs(User::factory()->create());

        $page = $this->page('/en/account/p12-probe');
        $this->assertStringContainsString('data-k="locale">en<', $page['html']);

        [$response, $v] = $this->update($page['snapshot']);
        $response->assertOk();

        $this->assertSame('1', $v['count']);
        $this->assertSame('en', $v['locale'], 'update rendered in the page locale');
        $this->assertSame('en', $v['app-locale']);
        $this->assertSame('Profile', $v['profile-label']);
        $this->assertSame('/en/account/profile', $v['profile-url'], 'account links keep the /en/ prefix');
    }

    public function test_a_vietnamese_page_keeps_vietnamese_after_an_english_tab_updated(): void
    {
        $this->actingAs(User::factory()->create());

        $vi = $this->page('/account/p12-probe');
        $en = $this->page('/en/account/p12-probe');

        [, $b] = $this->update($en['snapshot']);
        [, $a] = $this->update($vi['snapshot']);
        [, $b2] = $this->update($en['snapshot']);

        $this->assertSame('en', $b['locale']);
        $this->assertSame('vi', $a['locale'], 'tab A stays Vietnamese after tab B (en) updated');
        $this->assertSame('/account/profile', $a['profile-url']);
        $this->assertSame('en', $b2['locale']);
    }

    public function test_a_livewire_update_does_not_rewrite_the_readers_language_preference(): void
    {
        $user = User::factory()->create(['frontend_locale' => 'vi']);
        $this->actingAs($user);

        $en = $this->page('/en/account/p12-probe');            // an explicit visit persists en, as before
        $this->assertSame('en', $user->fresh()->frontend_locale);
        $user->forceFill(['frontend_locale' => 'vi'])->saveQuietly(); // the reader then chooses vi elsewhere

        $this->update($en['snapshot']);

        $this->assertSame('vi', $user->fresh()->frontend_locale, 'a transport update is not a language choice');
    }

    public function test_an_update_after_logout_is_not_run_as_an_account_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $page = $this->page('/en/account/p12-probe');

        auth()->logout();
        $this->newProcess();

        [$response, $v] = $this->update($page['snapshot']);

        $this->assertFalse($response->status() === 200 && ($v['count'] ?? null) === '1', 'the bump action must not run for a guest on an account route');
        $response->assertStatus(401);
    }

    public function test_validation_errors_render_in_the_page_locale(): void
    {
        $this->actingAs(User::factory()->create());
        $page = $this->page('/en/account/p12-probe');

        [$response, $v] = $this->update($page['snapshot'], [['path' => '', 'method' => 'submit', 'params' => []]]);
        $response->assertOk();

        $this->assertSame('en', $v['locale']);
        $this->assertNotSame('', $v['error']);
        $this->assertStringContainsString('name', strtolower($v['error']));
    }

    public function test_a_role_removed_while_the_tab_is_open_is_enforced_on_the_next_update(): void
    {
        $user = User::factory()->create();
        $role = \TheNguyen\CMS\Models\Role::query()->create(['name' => 'P12 reviewer', 'slug' => 'p12-reviewer']);
        $user->roles()->attach($role);
        $this->actingAs($user);
        $page = $this->page('/account/p12-probe-role');

        [$ok] = $this->update($page['snapshot']);
        $ok->assertOk();

        $user->roles()->detach($role);
        [$response, $v] = $this->update($page['snapshot']);

        $response->assertForbidden();
        $this->assertArrayNotHasKey('count', $v, 'the action must not run once the role is gone');
    }
}
