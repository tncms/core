<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR3;

use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Widget;
use TheNguyen\CMS\Models\WidgetArea;

/**
 * CORE-H-Z1-REMEDIATION-R3 — Core fixes for the H-Z1 evidence findings ER-1..ER-10. Server-side
 * behaviour and the shipped markup / script contracts are pinned here; the browser probes in the
 * phase evidence are the behavioural proof of the client scripts.
 */
final class HZ1R3RemediationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $this->admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$this->admin->email]]);
        $this->actingAs($this->admin);
    }

    private static function script(string $html, string $marker): string
    {
        preg_match('/<script[^>]*\b'.preg_quote($marker, '/').'\b[^>]*>(.*?)<\/script>/s', $html, $m);

        return $m[1] ?? '';
    }

    // ---- ER-9: session continuation endpoint -------------------------------------------------

    public function test_er9_session_status_returns_a_token_only_for_the_same_signed_in_account(): void
    {
        $response = $this->getJson(route('filament.admin.cms-session', ['for' => (string) $this->admin->getKey()]))
            ->assertOk()
            ->assertJson(['signedIn' => true, 'sameAccount' => true, 'lifetime' => (int) config('session.lifetime') * 60]);

        $this->assertSame(csrf_token(), $response->json('token'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /** @return list<array{0: ?string}> */
    public static function foreignAccounts(): array
    {
        return [['999999'], [''], [null], ['1 OR 1=1']];
    }

    #[DataProvider('foreignAccounts')]
    public function test_er9_session_status_refuses_a_page_rendered_for_another_account(?string $for): void
    {
        $query = $for === null ? [] : ['for' => $for];

        $response = $this->getJson(route('filament.admin.cms-session', $query))
            ->assertStatus(409)
            ->assertJson(['signedIn' => true, 'sameAccount' => false]);

        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_er9_session_status_refuses_an_array_account_parameter(): void
    {
        $this->getJson(route('filament.admin.cms-session').'?for[]='.$this->admin->getKey())->assertStatus(409)->assertJsonMissingPath('token');
    }

    public function test_er9_session_status_gives_a_signed_out_browser_no_token(): void
    {
        auth()->logout();

        $response = $this->getJson(route('filament.admin.cms-session', ['for' => (string) $this->admin->getKey()]))->assertUnauthorized();
        $this->assertArrayNotHasKey('token', (array) $response->json());
    }

    public function test_er9_session_status_does_not_change_the_session_lifetime_or_csrf_expiry(): void
    {
        $lifetime = config('session.lifetime');
        $this->getJson(route('filament.admin.cms-session', ['for' => (string) $this->admin->getKey()]))->assertOk();

        $this->assertSame($lifetime, config('session.lifetime'));
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('filament.admin.cms-session')->methods(), 'read-only: no state-changing verb');
    }

    // ---- ER-9 / ER-6: render-hook scripts --------------------------------------------------

    public function test_er6_and_er9_scripts_are_emitted_once_for_signed_in_admins_and_never_for_guests(): void
    {
        $html = (string) $this->get('/admin/tags/create')->assertOk()->getContent();
        foreach (['data-tn-admin-session-timeout', 'data-tn-admin-tooltip-a11y'] as $marker) {
            $this->assertSame(1, substr_count($html, $marker), $marker);
        }
        $this->assertSame(1, substr_count($html, 'id="tn-session-dialog"'));
        preg_match('/<dialog id="tn-session-dialog"[^>]*>/s', $html, $dialog);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*(background|color)/', $dialog[0], 'colours stay in the stylesheet so the dark theme applies (1.4.3)');
        $this->assertStringContainsString('.dark #tn-session-dialog { background:var(--gray-900)', $html);

        auth()->logout();
        $guest = (string) $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-tn-admin-session-timeout', $guest);
        $this->assertStringNotContainsString('data-tn-admin-tooltip-a11y', $guest);
    }

    public function test_er9_script_stores_only_a_timestamp_never_extends_by_itself_and_never_polls(): void
    {
        $js = self::script((string) $this->get('/admin/tags/create')->assertOk()->getContent(), 'data-tn-admin-session-timeout');
        $this->assertNotSame('', $js);

        $this->assertSame(1, substr_count($js, 'localStorage.setItem('), 'one storage write');
        $this->assertStringContainsString('window.localStorage.setItem(KEY, String(lastActivity))', $js, 'the only stored value is the last-activity timestamp');
        $this->assertStringNotContainsString('sessionStorage', $js);
        $this->assertStringNotContainsString('setInterval', $js, 'one timer, no polling');
        $this->assertSame(1, substr_count($js, 'window.fetch('), 'one request site');
        $this->assertSame(1, substr_count($js, "primary.addEventListener('click', check)"), 'the request is made only from the user-pressed primary button');
        $this->assertStringNotContainsString('innerHTML', $js, 'server / translated text is written as text');
        $this->assertStringContainsString('error.response.status === 419', $js, 'only the expired-session status is taken over');
        $this->assertStringContainsString('data.sameAccount === true', $js, 'a token is used only for the same account');
        // Opening the warning moves focus out of a field; the field's deferred update then succeeds. That renews the
        // session, but must not close the warning by itself: it stays open (with the new end time) until the user answers.
        preg_match('/var touch = function \(at\) \{(.*?)\n    \};/s', $js, $touch);
        $this->assertNotEmpty($touch, 'touch() found');
        $this->assertStringNotContainsString('close()', $touch[1], 'own-page activity never closes an open warning');
        $this->assertMatchesRegularExpression("/if \(mode === 'warn'\) \{(\s*\/\/[^\n]*)*\s*render\(\);\s*\}/", $touch[1]);
        // §12 / §14: the script must not cause a request by itself. Moving focus into the dialog blurs the page field, and a
        // field with an on-blur update (Filament live(onBlur)) would then send one and renew the session at every warning,
        // so the session would never end. The blur / focusout of that field is kept from the page only during that move.
        preg_match('/var open = function \(next\) \{(.*?)\n    \};/s', $js, $open);
        $this->assertNotEmpty($open, 'open() found');
        $this->assertStringContainsString("window.addEventListener('blur', keepFromPage, true)", $open[1]);
        $this->assertStringContainsString("window.addEventListener('focusout', keepFromPage, true)", $open[1]);
        $this->assertStringContainsString("window.removeEventListener('blur', keepFromPage, true)", $open[1]);
        $this->assertStringContainsString("window.removeEventListener('focusout', keepFromPage, true)", $open[1]);
        $this->assertMatchesRegularExpression('/var keepFromPage = function \(event\) \{\s*if \(event\.target === from\) \{\s*event\.stopImmediatePropagation\(\);/', $open[1]);
        $this->assertStringContainsString('rel="noopener"', (string) $this->get('/admin/tags/create')->getContent(), 'the sign-in tab cannot reach this page');
    }

    public function test_er9_filaments_failing_419_toast_is_hidden(): void
    {
        $notifications = filament()->getPanel('admin')->getErrorNotifications();
        $this->assertTrue($notifications[419]['isHidden'] ?? false);
    }

    // ---- ER-6: tooltip script --------------------------------------------------------------

    public function test_er6_tooltip_script_only_makes_existing_tooltips_interactive_and_escape_dismissible(): void
    {
        $js = self::script((string) $this->get('/admin/tags/create')->assertOk()->getContent(), 'data-tn-admin-tooltip-a11y');
        $this->assertNotSame('', $js);

        $this->assertStringContainsString('interactive: true', $js);
        $this->assertStringContainsString("'Escape'", $js);
        $this->assertStringNotContainsString('innerHTML', $js);
    }

    // ---- ER-3: widget reorder / move without dragging --------------------------------------

    /** @return array{0: WidgetArea, 1: WidgetArea, 2: list<int>} */
    private function widgetFixture(): array
    {
        $a = WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'r3-sidebar', 'is_active' => true, 'sort_order' => 1]);
        $b = WidgetArea::query()->create(['name' => 'Footer', 'slug' => 'r3-footer', 'is_active' => true, 'sort_order' => 2]);
        $ids = [];
        foreach (['one', 'two', 'three'] as $i => $title) {
            $ids[] = (int) Widget::query()->create(['area_id' => $a->id, 'widget_type' => 'text', 'title' => $title, 'settings' => [], 'sort_order' => $i, 'is_active' => true])->id;
        }

        return [$a, $b, $ids];
    }

    /** @return list<int> */
    private function order(WidgetArea $area): array
    {
        return Widget::query()->where('area_id', $area->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_er3_move_widget_by_swaps_with_the_neighbour_and_ignores_the_ends(): void
    {
        [$a, , [$one, $two, $three]] = $this->widgetFixture();
        $page = Livewire::test(WidgetsPage::class);

        $page->call('moveWidgetBy', $one, 1)->assertNotified();
        $this->assertSame([$two, $one, $three], $this->order($a));

        $page->call('moveWidgetBy', $three, -1);
        $this->assertSame([$two, $three, $one], $this->order($a));

        $page->call('moveWidgetBy', $two, -1)->call('moveWidgetBy', $one, 1)->call('moveWidgetBy', $one, 2);
        $this->assertSame([$two, $three, $one], $this->order($a), 'first up, last down and offsets other than one place are no-ops');
    }

    public function test_er3_move_widget_to_area_appends_to_an_active_area_only(): void
    {
        [$a, $b, [$one, $two, $three]] = $this->widgetFixture();
        $inactive = WidgetArea::query()->create(['name' => 'Off', 'slug' => 'r3-off', 'is_active' => false, 'sort_order' => 3]);
        $page = Livewire::test(WidgetsPage::class);

        $page->call('moveWidgetToArea', $two, $b->id)->assertNotified();
        $this->assertSame([$one, $three], $this->order($a));
        $this->assertSame([$two], $this->order($b));

        $page->call('moveWidgetToArea', $one, $b->id);
        $this->assertSame([$two, $one], $this->order($b), 'appended at the end');

        $page->call('moveWidgetToArea', $three, $inactive->id)->call('moveWidgetToArea', $three, 999999);
        $this->assertSame([$three], $this->order($a), 'inactive or missing areas are refused');
    }

    public function test_er3_move_buttons_recheck_access_on_every_request(): void
    {
        config(['cms.super_admin_emails' => []]);
        $this->seed(CmsRolePermissionSeeder::class);
        $user = User::factory()->create();
        Role::query()->where('slug', 'admin')->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();
        $this->actingAs($user);
        [$a, $b, [$one, $two, $three]] = $this->widgetFixture();
        $byPage = Livewire::test(WidgetsPage::class);
        $areaPage = Livewire::test(WidgetsPage::class);

        $user->roles()->detach();
        app('cms.permission')->flushResolutionCache();
        $byPage->call('moveWidgetBy', $one, 1)->assertForbidden();
        $areaPage->call('moveWidgetToArea', $one, $b->id)->assertForbidden();
        $this->assertSame([$one, $two, $three], $this->order($a));
    }

    public function test_er3_widget_rows_render_named_move_controls(): void
    {
        [, , [$one, , $three]] = $this->widgetFixture();
        $html = Livewire::test(WidgetsPage::class)->html();

        $this->assertMatchesRegularExpression('/id="tn-widget-up-'.$one.'"[^>]*\bdisabled\b|\bdisabled\b[^>]*id="tn-widget-up-'.$one.'"/s', $html, 'the first widget cannot move up');
        $this->assertStringContainsString('id="tn-widget-down-'.$one.'"', $html);
        $this->assertStringContainsString('id="tn-widget-area-'.$one.'"', $html);
        $this->assertStringContainsString(e(tn_trans('Move :name down', ['name' => 'one'])), $html);
        $this->assertStringContainsString(e(tn_trans('Move :name to another area', ['name' => 'three'])), $html);
        $this->assertStringNotContainsString(e(tn_trans('Drop widgets here, or add one from the left.')), $html);
        $this->assertStringContainsString('id="tn-widget-down-'.$three.'"', $html);
    }

    public function test_er3_area_move_menu_is_a_button_disclosure_not_an_aria_expanded_div(): void
    {
        [, , [$one]] = $this->widgetFixture();
        $html = Livewire::test(WidgetsPage::class)->html();

        // The state belongs on the <button> (WCAG 4.1.2). Filament's dropdown puts aria-expanded on its wrapper <div>.
        $this->assertMatchesRegularExpression('/<button[^>]*id="tn-widget-area-'.$one.'"[^>]*>/s', $html);
        preg_match('/<button[^>]*id="tn-widget-area-'.$one.'"[^>]*>/s', $html, $trigger);
        $this->assertStringContainsString('aria-expanded="false"', $trigger[0]);
        $this->assertStringContainsString('aria-controls="tn-widget-move-list-'.$one.'"', $trigger[0]);
        $this->assertMatchesRegularExpression('/<ul[^>]*id="tn-widget-move-list-'.$one.'"[^>]*tabindex="-1"/', $html, 'the scrolling list itself is not a Tab stop (Firefox)');
        // call-focus keeps focus only for a control that Livewire disables while its call runs (as Filament's buttons do).
        $this->assertMatchesRegularExpression('/<button[^>]*class="tn-widget-move-option"[^>]*wire:loading\.attr="disabled"[^>]*wire:click="moveWidgetToArea\('.$one.',/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*aria-expanded/', $html);
        $this->assertStringNotContainsString('fi-dropdown-trigger', $html);
        // Performance: the per-widget list of other areas is created only while it is open (widgets x areas buttons).
        $this->assertMatchesRegularExpression('/<template x-if="open">\s*<ul id="tn-widget-move-list-'.$one.'"/', $html);
        // Filament's icon buttons carry a -6px margin, so 32px targets overlapped by 8px in the widget rows (2.5.8).
        $this->assertStringContainsString('.tn-widget-instance-actions .fi-icon-btn { margin: 0; }', $html);
    }

    // ---- ER-4 / ER-5: language switcher and language of parts -------------------------------

    public function test_er4_switcher_changes_language_only_through_its_button(): void
    {
        $html = view('filament.admin.locale-switcher')->render();

        $this->assertStringNotContainsString('onchange', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*class="[^"]*tn-admin-locale-apply/', $html);
        $this->assertStringContainsString(e(tn_trans('Switch language')), $html);
        $this->assertStringContainsString('.tn-admin-locale-switcher select:focus-visible{outline:2px solid', $html, 'the R1 focus indicator is kept');
    }

    public function test_er5_switcher_options_carry_their_native_language(): void
    {
        $html = view('filament.admin.locale-switcher')->render();

        $this->assertMatchesRegularExpression('/<option value="vi"[^>]*lang="vi"/', $html);
        $this->assertMatchesRegularExpression('/<option value="en"[^>]*lang="en"/', $html);
    }

    public function test_er5_native_name_codes_lists_only_languages_labelled_in_their_own_language(): void
    {
        app('cms.language')->create(['code' => 'fr', 'name' => 'French', 'native_name' => '', 'is_active' => true, 'sort_order' => 9]);
        app('cms.language')->create(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'is_active' => false, 'sort_order' => 10]);

        $codes = app('cms.language')->nativeNameCodes();
        sort($codes);
        $this->assertSame(['en', 'vi'], $codes);
    }

    private function makePost(array $titles): Content
    {
        $post = Content::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $this->admin->id]);
        foreach ($titles as $locale => $title) {
            ContentTranslation::query()->create(['content_id' => $post->id, 'locale' => $locale, 'title' => $title, 'slug' => 'p-'.$post->id.'-'.$locale]);
        }

        return $post->fresh();
    }

    /** @return list<array{0: string}> */
    public static function readModes(): array
    {
        return [['legacy'], ['adapter']];
    }

    #[DataProvider('readModes')]
    public function test_er5_title_locale_is_the_locale_of_the_title_shown(string $mode): void
    {
        config(['translation.modules.posts.read_driver' => $mode]);

        $both = $this->makePost(['vi' => 'Bài viết', 'en' => 'Post']);
        $this->assertSame('en', $both->translatedTitleLocale('en'));
        $this->assertSame('vi', $both->translatedTitleLocale('vi'));

        $viOnly = $this->makePost(['vi' => 'Chỉ tiếng Việt']);
        $this->assertSame('Chỉ tiếng Việt', $viOnly->translatedTitle('en'), 'precondition: the fallback title is shown');
        $this->assertSame('vi', $viOnly->translatedTitleLocale('en'), 'the fallback title is marked with its own language');

        $untitled = $this->makePost([]);
        $this->assertNull($untitled->translatedTitleLocale('en'), 'the placeholder is in the interface language: no lang');
    }

    // ---- ER-8: input purpose ---------------------------------------------------------------

    public function test_er8_sign_in_identifier_is_marked_username(): void
    {
        auth()->logout();
        $html = (string) $this->get('/admin/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*type="email"[^>]*autocomplete="username"|<input[^>]*autocomplete="username"[^>]*type="email"/s', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
    }

    public function test_er8_only_the_own_account_form_marks_name_and_email(): void
    {
        $other = User::factory()->create();

        $own = Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])->html();
        $this->assertMatchesRegularExpression('/id="form\.name"[^>]*autocomplete="name"|autocomplete="name"[^>]*id="form\.name"/s', $own);
        $this->assertMatchesRegularExpression('/id="form\.email"[^>]*autocomplete="email"|autocomplete="email"[^>]*id="form\.email"/s', $own);

        foreach ([Livewire::test(EditUser::class, ['record' => $other->getKey()])->html(), Livewire::test(CreateUser::class)->html()] as $html) {
            $this->assertStringNotContainsString('autocomplete="name"', $html);
            $this->assertStringNotContainsString('autocomplete="email"', $html);
            $this->assertStringContainsString('autocomplete="new-password"', $html);
        }
    }

    // ---- translations ----------------------------------------------------------------------

    public function test_every_r3_string_has_english_and_vietnamese_entries(): void
    {
        $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true, flags: JSON_THROW_ON_ERROR);
        $vi = json_decode((string) file_get_contents(base_path('lang/vi.json')), true, flags: JSON_THROW_ON_ERROR);
        $keys = [
            'Switch language', 'Move :name up', 'Move :name down', 'Move :name to another area',
            'Widget moved to position :position of :count', 'Widget moved to :area',
            'No widgets in this area. To add one, choose this area in “Add new widgets to”, then press Add on a widget under Available Widgets, or drag a widget here.',
            'Your session is about to end', 'Stay signed in', 'Your session has ended', 'Sign in in a new tab', 'Continue',
            'You are not signed in yet. Sign in in the new tab, then select Continue.',
            'A different account is signed in. These changes cannot be saved with it. Sign out and sign in again with the original account, then select Continue.',
            'The session could not be checked. Try again.',
        ];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, $key);
            $this->assertArrayHasKey($key, $vi, $key);
            $this->assertNotSame($key, $vi[$key], 'translated: '.$key);
        }
        $this->assertArrayNotHasKey('Drop widgets here, or add one from the left.', $vi, 'the directional instruction is gone');
    }
}
