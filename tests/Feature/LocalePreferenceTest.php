<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * v1.0.0-beta.7.1.10.1 - separate admin UI / content editing / frontend locales.
 *
 * Proves the resolution order and persistence rules for the three independent
 * locales and that they never bleed into each other:
 *
 *   - Admin UI locale     - signal ?lang=,   session cms.admin_locale,   user.admin_locale
 *   - Content edit locale - signal ?locale=, session cms.editing_locale, user.editing_locale
 *   - Frontend locale     - route {locale} -> ?locale= -> user.frontend_locale -> session
 */
class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // vi (default) + en active; ja exists but is INACTIVE (must be ignored).
        $languages = app('cms.language');
        $languages->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true]);
        $languages->create(['code' => 'en', 'name' => 'English', 'is_active' => true]);
        $languages->create(['code' => 'ja', 'name' => 'Japanese', 'is_active' => false]);
    }

    private function prefs(): LocalePreferenceManager
    {
        return app('cms.locale_preference');
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function request(string $uri, array $session = [], ?User $user = null): Request
    {
        $request = Request::create($uri, 'GET');

        $store = new Store('test-session', new ArraySessionHandler(120));
        $store->start();

        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }

        $request->setLaravelSession($store);

        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }

    // ----------------------------------------------------------------
    // Admin UI resolution order: ?lang -> user -> session -> default
    // ----------------------------------------------------------------

    public function test_admin_lang_query_override_wins_over_everything(): void
    {
        $user = User::factory()->create(['admin_locale' => 'vi']);
        $request = $this->request('/admin?lang=en', [LocalePreferenceManager::ADMIN_SESSION_KEY => 'vi'], $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
    }

    public function test_admin_uses_user_preference_when_no_query(): void
    {
        $user = User::factory()->create(['admin_locale' => 'en']);
        $request = $this->request('/admin', [], $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
    }

    public function test_admin_uses_session_when_no_query_or_user_preference(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        $request = $this->request('/admin', [LocalePreferenceManager::ADMIN_SESSION_KEY => 'en'], $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
    }

    public function test_admin_falls_back_to_default(): void
    {
        $request = $this->request('/admin');

        $this->assertSame('vi', $this->prefs()->resolveAdminLocale($request, null));
    }

    public function test_admin_ignores_inactive_lang(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        // ?lang=ja is inactive -> ignored -> falls through to default.
        $request = $this->request('/admin?lang=ja', [], $user);

        $this->assertSame('vi', $this->prefs()->resolveAdminLocale($request, $user));
    }

    public function test_admin_ignores_content_editing_locale_param(): void
    {
        $user = User::factory()->create(['admin_locale' => 'en']);
        // ?locale=vi is the CONTENT editing signal; it must NOT move the UI off en.
        $request = $this->request('/admin?locale=vi', [], $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
    }

    // ----------------------------------------------------------------
    // Admin persistence from request (?lang), logged-in only
    // ----------------------------------------------------------------

    public function test_persist_admin_from_request_writes_user_column_and_session(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        $request = $this->request('/admin?lang=en', [], $user);

        $this->prefs()->persistAdminLocaleFromRequest($request, $user);

        $this->assertSame('en', $user->fresh()->admin_locale);
        $this->assertSame('en', $request->session()->get(LocalePreferenceManager::ADMIN_SESSION_KEY));
    }

    public function test_persist_admin_from_request_is_noop_without_lang(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        // Only a content-editing ?locale is present; no ?lang -> nothing persisted.
        $request = $this->request('/admin?locale=en', [], $user);

        $this->prefs()->persistAdminLocaleFromRequest($request, $user);

        $this->assertNull($user->fresh()->admin_locale);
        $this->assertNull($request->session()->get(LocalePreferenceManager::ADMIN_SESSION_KEY));
    }

    public function test_persist_admin_is_noop_for_guests(): void
    {
        $request = $this->request('/admin?lang=en');

        $this->prefs()->persistAdminLocaleFromRequest($request, null);

        $this->assertNull($request->session()->get(LocalePreferenceManager::ADMIN_SESSION_KEY));
    }

    public function test_persist_admin_ignores_inactive_locale(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        $request = $this->request('/admin?lang=ja', [], $user);

        $this->prefs()->persistAdminLocaleFromRequest($request, $user);

        $this->assertNull($user->fresh()->admin_locale);
    }

    // ----------------------------------------------------------------
    // Content editing resolution order: ?locale -> session -> default
    // ----------------------------------------------------------------

    public function test_editing_locale_query_override_wins(): void
    {
        $request = $this->request('/admin/posts/1/edit?locale=en', [LocalePreferenceManager::EDITING_SESSION_KEY => 'vi']);

        $this->assertSame('en', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_editing_locale_uses_user_column_when_no_query(): void
    {
        $user = User::factory()->create(['editing_locale' => 'en']);
        $request = $this->request('/admin/posts', [], $user);

        $this->assertSame('en', $this->prefs()->resolveEditingLocale($request, $user));
    }

    public function test_editing_locale_query_wins_over_user_column(): void
    {
        $user = User::factory()->create(['editing_locale' => 'vi']);
        $request = $this->request('/admin/posts/1/edit?locale=en', [], $user);

        $this->assertSame('en', $this->prefs()->resolveEditingLocale($request, $user));
    }

    public function test_editing_locale_uses_session_when_no_query_or_user_column(): void
    {
        $request = $this->request('/admin/posts', [LocalePreferenceManager::EDITING_SESSION_KEY => 'en']);

        $this->assertSame('en', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_editing_locale_falls_back_to_default(): void
    {
        $request = $this->request('/admin/posts');

        $this->assertSame('vi', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_editing_locale_ignores_inactive_locale(): void
    {
        $request = $this->request('/admin/posts?locale=ja');

        $this->assertSame('vi', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_editing_locale_ignores_admin_ui_lang_param(): void
    {
        // ?lang=en is the UI signal; the editing locale must follow ?locale=vi.
        $request = $this->request('/admin/posts/1/edit?lang=en&locale=vi');

        $this->assertSame('vi', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_persist_editing_locale_writes_session_and_user_column(): void
    {
        $user = User::factory()->create(['admin_locale' => null, 'frontend_locale' => null, 'editing_locale' => null]);
        $request = $this->request('/admin/posts/1/edit?locale=en', [], $user);

        $this->prefs()->persistEditingLocaleFromRequest($request, $user);

        $this->assertSame('en', $request->session()->get(LocalePreferenceManager::EDITING_SESSION_KEY));
        $this->assertSame('en', $user->fresh()->editing_locale);
        // Independent of the admin UI and frontend preferences.
        $this->assertNull($user->fresh()->admin_locale);
        $this->assertNull($user->fresh()->frontend_locale);
    }

    public function test_persist_editing_locale_is_noop_for_guests_user_column(): void
    {
        $request = $this->request('/admin/posts/1/edit?locale=en');

        $this->prefs()->persistEditingLocaleFromRequest($request, null);

        // Session still set; no user to write to.
        $this->assertSame('en', $request->session()->get(LocalePreferenceManager::EDITING_SESSION_KEY));
    }

    public function test_editing_locale_persists_per_user_across_requests(): void
    {
        // Request 1: explicit ?locale=en persists to the user column.
        $user = User::factory()->create(['editing_locale' => null]);
        $first = $this->request('/admin/posts/1/edit?locale=en', [], $user);
        $this->prefs()->persistEditingLocaleFromRequest($first, $user);

        // Request 2 (no query, fresh session) still resolves to en via the column.
        $second = $this->request('/admin/posts', [], $user->fresh());
        $this->assertSame('en', $this->prefs()->resolveEditingLocale($second, $user->fresh()));
    }

    public function test_persist_editing_locale_is_noop_without_locale(): void
    {
        $request = $this->request('/admin/posts/1/edit?lang=en');

        $this->prefs()->persistEditingLocaleFromRequest($request);

        $this->assertNull($request->session()->get(LocalePreferenceManager::EDITING_SESSION_KEY));
    }

    public function test_persist_editing_locale_ignores_inactive_locale(): void
    {
        $request = $this->request('/admin/posts/1/edit?locale=ja');

        $this->prefs()->persistEditingLocaleFromRequest($request);

        $this->assertNull($request->session()->get(LocalePreferenceManager::EDITING_SESSION_KEY));
    }

    // ----------------------------------------------------------------
    // Separation scenarios: ?lang and ?locale are fully independent
    // ----------------------------------------------------------------

    public function test_lang_en_locale_vi_yields_admin_en_editing_vi(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        $request = $this->request('/admin/posts/1/edit?lang=en&locale=vi', [], $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
        $this->assertSame('vi', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_lang_vi_locale_en_yields_admin_vi_editing_en(): void
    {
        $user = User::factory()->create(['admin_locale' => null]);
        $request = $this->request('/admin/posts/1/edit?lang=vi&locale=en', [], $user);

        $this->assertSame('vi', $this->prefs()->resolveAdminLocale($request, $user));
        $this->assertSame('en', $this->prefs()->resolveEditingLocale($request));
    }

    public function test_persisting_editing_locale_does_not_change_admin_ui_locale(): void
    {
        // Admin UI is en (user column); editing switches to vi via ?locale.
        $user = User::factory()->create(['admin_locale' => 'en', 'editing_locale' => null]);
        $request = $this->request('/admin/posts/1/edit?locale=vi', [], $user);

        $this->prefs()->persistEditingLocaleFromRequest($request, $user);

        $this->assertSame('en', $this->prefs()->resolveAdminLocale($request, $user));
        $this->assertSame('en', $user->fresh()->admin_locale, 'admin UI locale must be untouched');
        $this->assertSame('vi', $user->fresh()->editing_locale, 'editing locale persists separately');
    }

    public function test_persisting_admin_locale_does_not_touch_editing_locale(): void
    {
        $user = User::factory()->create(['admin_locale' => null, 'editing_locale' => null]);
        $request = $this->request('/admin?lang=en', [], $user);

        $this->prefs()->persistAdminLocaleFromRequest($request, $user);

        $this->assertSame('en', $user->fresh()->admin_locale);
        $this->assertNull($user->fresh()->editing_locale, 'editing locale must be untouched by a ?lang switch');
    }

    // ----------------------------------------------------------------
    // Independence: admin / editing / frontend never bleed
    // ----------------------------------------------------------------

    public function test_persisting_admin_locale_does_not_touch_frontend(): void
    {
        $user = User::factory()->create(['admin_locale' => null, 'frontend_locale' => null]);
        $request = $this->request('/admin?lang=en', [], $user);

        $this->prefs()->persistAdminLocaleFromRequest($request, $user);

        $this->assertSame('en', $user->fresh()->admin_locale);
        $this->assertNull($user->fresh()->frontend_locale);
        $this->assertNull($request->session()->get(LocalePreferenceManager::FRONTEND_SESSION_KEY));
    }

    public function test_persisting_frontend_locale_does_not_touch_admin(): void
    {
        $user = User::factory()->create(['admin_locale' => null, 'frontend_locale' => null]);
        $request = $this->request('/en', [], $user);

        $this->prefs()->persistFrontendLocale($request, $user, 'en');

        $this->assertSame('en', $user->fresh()->frontend_locale);
        $this->assertNull($user->fresh()->admin_locale);
        $this->assertNull($request->session()->get(LocalePreferenceManager::ADMIN_SESSION_KEY));
    }

    // ----------------------------------------------------------------
    // Frontend resolution: route wins (unchanged behaviour)
    // ----------------------------------------------------------------

    public function test_frontend_route_locale_wins_over_all_preferences(): void
    {
        $user = User::factory()->create(['frontend_locale' => 'vi']);
        $request = $this->request('/en?locale=vi', [LocalePreferenceManager::FRONTEND_SESSION_KEY => 'vi'], $user);

        // Route says en; every other source says vi -> route still wins.
        $this->assertSame('en', $this->prefs()->resolveFrontendLocale($request, 'en', $user));
    }

    public function test_frontend_resolution_order_without_route(): void
    {
        $user = User::factory()->create(['frontend_locale' => 'en']);
        $request = $this->request('/', [], $user);

        // No route, no query -> user.frontend_locale.
        $this->assertSame('en', $this->prefs()->resolveFrontendLocale($request, null, $user));
    }

    public function test_frontend_falls_back_to_default(): void
    {
        $request = $this->request('/');

        $this->assertSame('vi', $this->prefs()->resolveFrontendLocale($request, null, null));
    }

    public function test_persist_frontend_for_guest_sets_session_only(): void
    {
        $request = $this->request('/en');

        $this->prefs()->persistFrontendLocale($request, null, 'en');

        $this->assertSame('en', $request->session()->get(LocalePreferenceManager::FRONTEND_SESSION_KEY));
    }
}
