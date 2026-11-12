<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\WidgetArea;

/**
 * CORE-H-Z1-REMEDIATION-R2: residual findings left by R1, mitigated in Core without editing vendor/.
 * PHPUnit guards structure, scope, server behaviour and the vendor seams; focus movement, accessible
 * names, invalid state and error descriptions are proven in Chrome and Firefox
 * (docs/core-h-z1-remediation-r2/03-06, 08).
 *
 *  - R2-1 H-N4 residual (WCAG 1.3.1 / 4.1.2): a Filament JavaScript select has no element with its
 *    label's id, so its server error was never associated; Core now marks its trigger button.
 *  - R2-2 H-N2 residual (WCAG 2.4.3): a confirmed table action that removes its own opener left focus
 *    on <body>; Core moves it to the table the action changed.
 *  - R2-3 O-N11 (WCAG 2.4.3): a Save disables its own button (wire:loading) and focus fell to <body>;
 *    Core returns it to the first invalid field, or to the save control.
 *  - R2-4 O-N10 (WCAG 4.1.2 / 2.4.6 / 3.3.2): a single JavaScript select was named by its placeholder or
 *    value; Core names it after the field label.
 *  - R2-5: the Widgets "Copy JSON" export is generated on demand instead of on every page render.
 *  - Filament's partial re-render (an action dialog after a validation error) bypasses Livewire's
 *    `morphed` hook; the Core scripts also listen to Livewire's public message interceptor.
 */
final class HZ1R2ResidualRemediationTest extends TestCase
{
    use RefreshDatabase;

    /** Filament / Livewire files whose behaviour the R2 integrations depend on (sha256). */
    private const PINS = [
        // Filament's partial renderer morphs with Alpine.morph, outside Livewire's morph hooks.
        'vendor/filament/support/resources/js/partials.js' => 'f8499c45f4e67d3ed15546f5c48d7252b35bbffeb310e96780e2f1a4c9f439c3',
        // The table markup the H-N2 residual focuses: .fi-ta, table.fi-ta-table, .fi-ta-empty-state-heading.
        'vendor/filament/tables/resources/views/index.blade.php' => '080a214b4f8a0c25510bfc428f8bb4325c8599b8906d53fc31f00d84579d330e',
    ];

    private const FORBIDDEN = ['MutationObserver', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'Element.prototype', 'EventTarget.prototype', 'preventDefault'];

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

    public function test_the_vendor_seams_the_r2_integrations_rely_on_are_pinned(): void
    {
        foreach (self::PINS as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the CORE-H-Z1-REMEDIATION-R2 integrations before updating this pin.");
        }

        $partials = (string) file_get_contents(base_path('vendor/filament/support/resources/js/partials.js'));
        $this->assertStringContainsString('Livewire.interceptMessage(', $partials, 'partials run inside a message interceptor');
        $this->assertStringContainsString('queueMicrotask(', $partials, 'the partial morph runs in a microtask, so the Core handler (next task) runs after it');
        $this->assertStringContainsString('window.Alpine.morph(el, to', $partials, 'the root cause: no Livewire morph hook fires for partials');

        $livewire = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));
        $this->assertStringContainsString('interceptMessage: (callback) => interceptMessage(callback)', $livewire, 'Livewire exposes the public message interceptor');

        $select = (string) file_get_contents(base_path('vendor/filament/support/resources/js/utilities/select.js'));
        $this->assertStringContainsString("this.selectButton.className = 'fi-select-input-btn'", $select, 'the JS select trigger the integrations address');
        $this->assertStringContainsString('this.selectButton.appendChild(this.selectedDisplay)', $select, 'the single-select value container stays inside the trigger');
        $this->assertStringContainsString('placeholderSpan.textContent = this.placeholder', $select, 'the root cause of O-N10: the placeholder is the trigger content');

        $table = (string) file_get_contents(base_path('vendor/filament/tables/resources/views/index.blade.php'));
        $this->assertStringContainsString('<table class="fi-ta-table">', $table);
        $this->assertStringContainsString('class="fi-ta-empty-state-heading"', $table);
    }

    public function test_every_r2_script_is_emitted_once_for_signed_in_admins_and_never_for_guests(): void
    {
        $html = (string) $this->get('/admin/tags/create')->assertOk()->getContent();
        foreach (['data-tn-admin-save-focus', 'data-tn-admin-field-errors', 'data-tn-admin-action-focus', 'data-tn-admin-select-a11y'] as $marker) {
            $this->assertSame(1, substr_count($html, $marker), $marker);
        }
        $this->assertLessThan(strpos($html, 'data-tn-admin-save-focus'), strpos($html, 'data-tn-admin-field-errors'), 'save-focus runs after field-errors, so its morph hook sees the error association');

        auth()->logout();
        $this->assertStringNotContainsString('data-tn-admin-save-focus', (string) $this->get('/admin/login')->assertOk()->getContent());
    }

    public function test_every_core_render_hook_script_also_listens_to_filament_partial_renders(): void
    {
        $html = (string) $this->get('/admin/tags/create')->assertOk()->getContent();
        foreach (['data-tn-admin-save-focus', 'data-tn-admin-field-errors', 'data-tn-admin-action-focus', 'data-tn-admin-select-a11y'] as $marker) {
            $js = self::script($html, $marker);
            $this->assertSame(1, substr_count($js, 'Livewire.interceptMessage('), "{$marker}: one interceptor per page");
            $this->assertSame(1, substr_count($js, "Livewire.hook('morphed'"), "{$marker}: one morph hook per page");
            $this->assertMatchesRegularExpression('/message\.onSuccess\(function \(\) \{\s*window\.setTimeout\(/', $js, "{$marker}: runs on the next task, after Filament's microtask morph");
        }
    }

    public function test_r2_1_field_errors_associates_javascript_select_triggers_by_the_label_for_value(): void
    {
        $js = self::script((string) $this->get('/admin/tags/create')->assertOk()->getContent(), 'data-tn-admin-field-errors');

        foreach ([...self::FORBIDDEN, 'stopPropagation', "addEventListener('keydown", "addEventListener('click"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the field-errors script: {$forbidden}");
        }
        $this->assertStringContainsString("control.matches('input:not([type=hidden]), textarea, select')", $js, 'native controls are still resolved first');
        $this->assertStringContainsString("wrapper.querySelectorAll('.fi-select-input-btn')", $js, 'the JS select trigger, inside the same wrapper');
        $this->assertStringContainsString("triggers[j].closest('.fi-fo-field') === wrapper && fieldId", $js, 'only a trigger of this field, and only when the field has a label');
        $this->assertStringContainsString('var errorId = error.id || key + SUFFIX;', $js, 'the error id is derived from the label for value');
        $this->assertSame(0, preg_match('/querySelector(All)?\([^)]*\+/', $js), 'no selector is concatenated from page data');
    }

    public function test_r2_2_action_focus_falls_back_to_the_changed_table_when_the_opener_is_gone(): void
    {
        $js = self::script((string) $this->get('/admin/posts')->assertOk()->getContent(), 'data-tn-admin-action-focus');

        foreach ([...self::FORBIDDEN, 'stopPropagation'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the action-focus script: {$forbidden}");
        }
        $this->assertStringContainsString("region: target.closest('.fi-ta')", $js, 'the table is remembered from the activated control, not guessed later');
        $this->assertStringContainsString("var choices = ['.fi-ta-empty-state-heading', 'table.fi-ta-table'];", $js, 'the destination is the empty-state heading when no row is left, else the changed table');
        $this->assertStringContainsString("if (! target.hasAttribute('tabindex')) {\n            target.setAttribute('tabindex', '-1');", $js, 'focusable without joining the Tab order; an existing tabindex is kept');
        $this->assertStringContainsString('(expired || openerGone(pending)) && focusRegion(pending)', $js, 'a disabled opener that is still rendered is waited for first');
        $this->assertStringContainsString("tables.length === 1 ? tables[0] : null", $js, 'a replaced table is only adopted when it is unambiguous');
    }

    public function test_r2_3_save_focus_is_passive_scoped_and_bounded(): void
    {
        $js = self::script((string) $this->get('/admin/tags/create')->assertOk()->getContent(), 'data-tn-admin-save-focus');

        $this->assertNotSame('', $js);
        foreach ([...self::FORBIDDEN, 'stopPropagation', 'setAttribute(', 'removeAttribute('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the save-focus script: {$forbidden}");
        }
        $this->assertStringContainsString('window.__tnAdminSaveFocus', $js, 'idempotent across re-emission');
        $this->assertStringContainsString('{ capture: true, passive: true }', $js, 'the click listener only reads');
        $this->assertStringContainsString("attribute.name === 'wire:submit' || attribute.name.indexOf('wire:submit.') === 0", $js, 'only Livewire form submits');
        $this->assertStringContainsString('/^\s*(save|create)[A-Za-z]*\s*(\(|$)/', $js, 'or an explicit save…() / create…() wire:click');
        $this->assertStringContainsString("querySelectorAll('[aria-invalid=\"true\"]')", $js, 'after a validation failure, the first field marked invalid by field-errors');
        $this->assertStringContainsString('active === null || active === document.body', $js, 'acts only when focus was lost');
        $this->assertStringContainsString('RETRY_MS = 2000', $js);
        $this->assertStringContainsString('PENDING_MS = 15000', $js, 'a stale save cannot move focus later');
        $this->assertStringContainsString('pending.el.isConnected && pending.el.disabled', $js, 'waits while wire:loading keeps the save disabled');
        $this->assertStringContainsString('! pending.sent ||', $js, 'a click that started no Livewire request is never acted on');
        $this->assertStringContainsString('Date.now() - pending.at <= SEND_MS', $js, 'only a request sent right after the click counts');
        $this->assertSame(1, substr_count($js, "document.addEventListener('click'"));
        $this->assertSame(0, preg_match('/querySelector(All)?\([^)]*\+/', $js), 'no selector is concatenated from page data');
    }

    public function test_r2_3_every_core_save_control_carries_the_root_cause_the_script_addresses(): void
    {
        $button = (string) file_get_contents(base_path('vendor/filament/support/resources/views/components/button/index.blade.php'));
        $this->assertStringContainsString("'wire:loading.attr' => \$tag === 'button' ? 'disabled' : null", $button);

        $html = (string) $this->get('/admin/tags/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<form[^>]*wire:submit="create"/', $html, 'resource create pages submit through wire:submit');
        $this->assertMatchesRegularExpression('/type="submit"[^>]*wire:loading\.attr="disabled"|wire:loading\.attr="disabled"[^>]*type="submit"/s', $html);
    }

    public function test_r2_4_single_selects_are_named_by_their_label_and_described_by_their_value(): void
    {
        $js = self::script((string) $this->get('/admin/settings')->assertOk()->getContent(), 'data-tn-admin-select-a11y');

        $this->assertStringContainsString('nameSingle(el, select, directive.expression, utilities);', $js, 'single selects are named too');
        $this->assertStringContainsString('if (values.parentNode !== trigger) {', $js, 'a single select is never restructured; a changed contract is reported');
        $this->assertStringContainsString("multiple: false };", $js);
        $this->assertStringContainsString('if (! labelId && ! link.multiple) {', $js, 'without a label a single select keeps its own name');
        $this->assertStringContainsString('list.indexOf(id) === -1', $js, 'aria-describedby is shared with field-errors: only the Core token is added');
        $this->assertStringNotContainsString("trigger.setAttribute('aria-describedby', link.values.id)", $js, 'the whole describedby value is never replaced');
        $this->assertSame(1, substr_count($js, 'event.stopPropagation()'), 'the H-N9 guard is unchanged');

        // the hook carries the field id for single selects as well (AdminPanelProvider::selectA11yAttributes)
        $html = (string) $this->get('/admin/settings')->assertOk()->getContent();
        $this->assertStringContainsString('x-tn-select-a11y="form.settings_locale"', $html);
    }

    public function test_r2_5_the_widgets_page_no_longer_embeds_the_export(): void
    {
        WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'r2-sidebar', 'is_active' => true, 'sort_order' => 1]);
        $html = (string) $this->get('/admin/widgets')->assertOk()->getContent();

        $this->assertStringContainsString('$wire.clipboardExport()', $html, 'Copy JSON fetches the export on activation');
        $this->assertStringNotContainsString('navigator.clipboard.writeText(\'{', $html, 'no export JSON is embedded in the page');
        $this->assertStringNotContainsString('1.0.0-beta.7.1', $html, 'the export payload (its version marker) is not in the page');
        $this->assertStringContainsString("if (this.state === 'busy') {", $html, 'one request per activation');
        $this->assertStringContainsString('role="status"', $html, 'the outcome is announced');
        $this->assertSame(0, preg_match('/x-bind:disabled="[^"]*state/', $html), 'the button is never disabled (focus would drop)');
        $this->assertStringContainsString('wire:target="clipboardExport"', $html, 'loading state scoped to the export call');
        $this->assertStringContainsString('wire:loading.attr="aria-busy"', $html, "overrides Filament's wire:loading.attr=\"disabled\" default: busy, not disabled");
        $this->assertStringContainsString("new ClipboardItem({ 'text/plain': request.then(", $html, 'the clipboard write starts inside the click where supported (WebKit user activation)');
        $this->assertStringContainsString('request.then((json) => clipboard.writeText(json))', $html, 'otherwise the text is written when it arrives');
    }

    public function test_r2_5_render_runs_no_export_query_and_the_on_demand_export_is_the_full_configuration(): void
    {
        WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'r2-sidebar', 'is_active' => true, 'sort_order' => 1]);

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        $component = Livewire::test(WidgetsPage::class);
        $this->assertSame([], array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'cms_widget_translations'))), 'rendering the page loads no widget translations (the export eager-loads them)');

        $sql = [];
        $json = $component->call('clipboardExport')->effects['returns'][0] ?? null;
        $this->assertIsString($json);
        $this->assertSame(app('cms.widget')->export(), json_decode($json, true), 'the export is the complete current configuration');
        $this->assertNotSame([], array_filter($sql, fn (string $q): bool => str_contains($q, 'cms_widget_areas')), 'the export runs only on demand');
        $this->assertArrayNotHasKey('html', $component->effects, 'renderless: the call does not re-render the page');
    }

    private function userWithRole(string $slug): User
    {
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder::class);
        $user = User::factory()->create();
        Role::query()->where('slug', $slug)->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();

        return $user;
    }

    public function test_r2_5_the_on_demand_export_rechecks_access_on_every_request(): void
    {
        config(['cms.super_admin_emails' => []]);
        $admin = $this->userWithRole('admin');
        $this->actingAs($admin);
        $this->assertTrue(cms_can('widgets.manage'), 'precondition: the admin role manages widgets');
        $component = Livewire::test(WidgetsPage::class);

        // access revoked after the page was opened: the next Livewire request is refused server-side
        $admin->roles()->detach();
        app('cms.permission')->flushResolutionCache();
        $component->call('clipboardExport')->assertForbidden();
    }

    public function test_r2_5_users_without_widget_access_cannot_reach_the_page_or_the_export(): void
    {
        config(['cms.super_admin_emails' => []]);
        $this->actingAs($this->userWithRole('editor'));
        $this->assertFalse(cms_can('widgets.manage'), 'precondition: editors do not manage widgets');

        $this->get('/admin/widgets')->assertForbidden();
        Livewire::test(WidgetsPage::class)->assertForbidden();
    }
}
