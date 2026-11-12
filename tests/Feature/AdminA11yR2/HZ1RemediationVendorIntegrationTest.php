<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-H-Z1-REMEDIATION-R1: Core-owned integrations that mitigate Filament 5.6.6 defects without
 * editing vendor/. What a server-rendered response can prove is guarded here: the scripts are emitted
 * only for signed-in admins, stay passive and scoped, and the vendor behaviour they rely on is pinned.
 * Focus movement, key handling, error association and rendered contrast are proven in Chrome and
 * Firefox (docs/core-h-z1-remediation-r1/08-vendor-findings.md and 09-escape-modal-select.md).
 *
 *  - H-N2 (WCAG 2.4.3): the action opener is disabled by wire:loading while mountAction is in flight,
 *    so the dialog's focus trap records <body> as its return target; Core returns focus to the opener.
 *  - H-N4 (WCAG 1.3.1 / 4.1.2): a server validation error is not associated with its control; Core
 *    adds aria-invalid and aria-describedby for native controls after each Livewire morph.
 *  - H-N5 (WCAG 1.4.3): danger buttons lighten to the 500 shade on hover (white text 3.81:1); Core
 *    uses the 700 shade for that one hover state.
 *  - H-N9 (WCAG 2.1.2 / 2.4.3): Escape on an open select inside a dialog also closes the dialog,
 *    because Filament's stopPropagation guard reads a property that does not exist; Core stops that
 *    one Escape after the select has closed itself.
 */
final class HZ1RemediationVendorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Filament v5.6.6 files whose behaviour the H-N2 script depends on (sha256). */
    private const MODAL_PINS = [
        'vendor/filament/support/resources/js/components/modal.js' => '94e7d13e7741f6927244fd000644a741219a50b9fd975d50a7b41e5bcab9da30',
        'vendor/filament/support/dist/index.js' => '282369e6856a39f5dae4ed4d383244ef7360f1fee67641338f064ae89443dbfa',
        'public/js/filament/support/support.js' => '282369e6856a39f5dae4ed4d383244ef7360f1fee67641338f064ae89443dbfa',
        'vendor/filament/support/resources/views/components/modal/index.blade.php' => '913c98d3e38bba1990734e794239666accf9b269df62df0b6cd0733631d4c339',
        'vendor/filament/support/resources/views/components/button/index.blade.php' => '71e514ecae4c4b833595e95e451b0900516ce06a8926312faf0be29664b9136c',
    ];

    private const FORBIDDEN = ['MutationObserver', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'Element.prototype', 'EventTarget.prototype', 'preventDefault'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private static function script(string $html, string $marker): string
    {
        preg_match('/<script[^>]*\b'.preg_quote($marker, '/').'\b[^>]*>(.*?)<\/script>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_h_n2_the_vendor_modal_and_button_contract_is_pinned(): void
    {
        foreach (self::MODAL_PINS as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the H-N2 action-focus contract before updating this pin.");
        }

        $bundle = (string) file_get_contents(base_path('public/js/filament/support/support.js'));
        $this->assertStringContainsString('"modal-closed",{id:', $bundle, 'close() announces modal-closed');
        $this->assertStringContainsString('new CustomEvent("x-modal-opened"', $bundle, 'open() announces x-modal-opened');

        $button = (string) file_get_contents(base_path('vendor/filament/support/resources/views/components/button/index.blade.php'));
        $this->assertStringContainsString("'wire:loading.attr' => \$tag === 'button' ? 'disabled' : null", $button, 'the root cause: the opener is disabled while its request runs');
    }

    public function test_h_n2_the_action_focus_script_is_emitted_once_for_signed_in_admins_only(): void
    {
        $html = (string) $this->get('/admin/posts')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-tn-admin-action-focus'));
        $this->assertNotSame('', self::script($html, 'data-tn-admin-action-focus'));

        auth()->logout();
        $this->assertStringNotContainsString('data-tn-admin-action-focus', (string) $this->get('/admin/login')->assertOk()->getContent());
    }

    public function test_h_n2_the_action_focus_script_is_passive_scoped_and_bounded(): void
    {
        $js = self::script((string) $this->get('/admin/posts')->assertOk()->getContent(), 'data-tn-admin-action-focus');

        foreach (self::FORBIDDEN as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the H-N2 script: {$forbidden}");
        }
        $this->assertStringNotContainsString('stopPropagation', $js, 'no event is stopped');
        $this->assertStringContainsString('window.__tnAdminActionFocus', $js, 'idempotent across re-emission');
        $this->assertStringContainsString('{ capture: true, passive: true }', $js, 'the click listener only reads');
        $this->assertStringContainsString('/^\s*mount[A-Za-z]*Action\(/', $js, 'only Filament mount…Action openers are remembered');
        $this->assertStringContainsString("closest('.fi-modal-window')", $js, 'controls inside a dialog are not openers');
        $this->assertStringContainsString('el.getAttribute(state.attribute) === state.call', $js, 'replacement openers are matched by exact string equality, never a built selector');
        $this->assertSame(0, preg_match('/querySelector(All)?\([^)]*\+/', $js), 'no selector is concatenated from page data');
        $this->assertStringContainsString("document.querySelector('.fi-modal-open')", $js, 'nothing moves while a dialog is still open');
        $this->assertStringContainsString('RETRY_MS = 2000', $js, 'retries are bounded');
        $this->assertStringContainsString('OPEN_WINDOW_MS = 10000', $js, 'a click that opened no dialog cannot adopt a later, unrelated one');
        $this->assertSame(1, substr_count($js, "Livewire.hook('morphed'"), 'one permanent morph hook, no per-action accumulation');
        $this->assertSame(1, substr_count($js, "addEventListener('modal-closed'"));
        $this->assertSame(1, substr_count($js, "addEventListener('x-modal-opened'"));
    }

    public function test_h_n4_the_vendor_error_markup_the_script_relies_on_is_pinned(): void
    {
        $path = 'vendor/filament/forms/resources/views/components/field-wrapper.blade.php';
        $this->assertSame('b7a2aa2ba0f6d3a80874d97e7fb1d5af07abf5675909f1754a255d9dba8437cb', hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the H-N4 field-errors contract before updating this pin.");

        $wrapper = (string) file_get_contents(base_path($path));
        $this->assertSame(3, substr_count($wrapper, 'data-validation-error'), 'the error element is marked, in all three error variants');
        $this->assertStringNotContainsString('aria-invalid', $wrapper, 'the root cause: Filament does not mark the control invalid');
        $this->assertStringNotContainsString('aria-describedby', $wrapper, 'the root cause: Filament does not reference the error');
        $this->assertStringContainsString("'fi-fo-field',", $wrapper, 'the wrapper class the script scopes to');
    }

    public function test_h_n4_the_field_errors_script_is_emitted_once_for_signed_in_admins_only(): void
    {
        $html = (string) $this->get('/admin/users/create')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-tn-admin-field-errors'));
        $this->assertNotSame('', self::script($html, 'data-tn-admin-field-errors'));

        auth()->logout();
        $this->assertStringNotContainsString('data-tn-admin-field-errors', (string) $this->get('/admin/login')->assertOk()->getContent());
    }

    public function test_h_n4_the_field_errors_script_is_scoped_to_native_controls_and_reverts_only_its_own_tokens(): void
    {
        $js = self::script((string) $this->get('/admin/users/create')->assertOk()->getContent(), 'data-tn-admin-field-errors');

        foreach ([...self::FORBIDDEN, 'stopPropagation', 'addEventListener(\'keydown', 'addEventListener(\'click'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the H-N4 script: {$forbidden}");
        }
        $this->assertStringContainsString('window.__tnAdminFieldErrors', $js, 'idempotent across re-emission');
        $this->assertStringContainsString("document.querySelectorAll('.fi-fo-field [data-validation-error]')", $js, 'only Filament field errors');
        $this->assertStringContainsString("labels[i].closest('.fi-fo-field') !== wrapper", $js, 'a nested field\'s label is never borrowed');
        $this->assertStringContainsString("control.matches('input:not([type=hidden]), textarea, select')", $js, 'native controls only');
        $this->assertStringContainsString('wrapper.contains(control)', $js, 'the control must sit in the same wrapper as the error');
        $this->assertStringContainsString("var SUFFIX = '-tn-error'", $js);
        $this->assertStringContainsString('token !== errorId', $js, 'on removal only the Core token is dropped; other describedby ids are kept');
        $this->assertStringContainsString("control.removeAttribute('aria-invalid')", $js);
        $this->assertSame(1, substr_count($js, "Livewire.hook('morphed'"), 'one permanent morph hook');
        $this->assertSame(0, preg_match('/querySelector(All)?\([^)]*\+/', $js), 'no selector is concatenated from page data');
    }

    public function test_h_n5_danger_button_hover_uses_the_700_shade_and_nothing_else_changes(): void
    {
        preg_match('/<style[^>]*\bdata-tn-admin-a11y\b[^>]*>(.*?)<\/style>/s', (string) $this->get('/admin/posts')->assertOk()->getContent(), $m);
        $css = $m[1] ?? '';

        $this->assertStringContainsString('.fi-btn.fi-color-danger.hover\:fi-bg-color-500.hover\:fi-text-color-0 { --hover-bg: var(--color-700); }', $css);
        $this->assertStringContainsString('.fi-btn.fi-color-danger.dark\:hover\:fi-bg-color-500.dark\:hover\:fi-text-color-0 { --dark-hover-bg: var(--color-700); }', $css);
        $this->assertSame(2, substr_count($css, 'fi-color-danger'), 'only the danger hover background is overridden');
        $this->assertSame(0, preg_match('/fi-color-danger[^{]*\{[^}]*--(bg|text|dark-bg|dark-text)\s*:/', $css), 'default, text and focus colours are Filament\'s');

        // The vendor variables the override relies on (served bundle is tracked in public/).
        $app = (string) file_get_contents(base_path('public/css/filament/filament/app.css'));
        $this->assertStringContainsString('.hover\:fi-bg-color-500{--hover-bg:var(--color-500)}', $app);
        $this->assertStringContainsString('.dark\:hover\:fi-bg-color-500{--dark-hover-bg:var(--color-500)}', $app);
        $this->assertStringContainsString('--color-700:var(--danger-700)', $app);
    }

    public function test_h_n6_the_vendor_key_value_markup_is_pinned(): void
    {
        $path = 'vendor/filament/forms/resources/views/components/key-value.blade.php';
        $this->assertSame('7c82fed34ee1e899fe56b022839e7c55d97796c22c23c06bac49ff3b244782a6', hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the H-N6 key-value contract before updating this pin.");

        $blade = (string) file_get_contents(base_path($path));
        $this->assertStringContainsString('->merge($getExtraAlpineAttributes(), escape: false)', $blade, 'the Core hook reaches the Alpine root');
        $this->assertStringContainsString('wire:ignore', $blade, 'rows are Alpine-rendered and never morphed by Livewire');
        $this->assertSame(2, substr_count($blade, 'class="fi-input"'), 'two inputs per row');
        $this->assertSame(0, preg_match('/aria-label|<label/', $blade), 'the root cause: the inputs have no label');
    }

    public function test_h_n6_every_key_value_field_carries_the_core_hook_and_the_directive_is_signed_in_only(): void
    {
        $html = (string) $this->get('/admin/profile-fields/create')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/x-data="keyValueFormComponent\([^"]*"[^>]*\bx-tn-key-value-a11y=""/s', $html, 'the profile-field Choices KeyValue carries the Core hook');
        $this->assertSame(1, substr_count($html, 'data-tn-admin-key-value-a11y'));

        auth()->logout();
        $this->assertStringNotContainsString('data-tn-admin-key-value-a11y', (string) $this->get('/admin/login')->assertOk()->getContent());
    }

    public function test_h_n6_the_key_value_directive_only_names_inputs_after_their_headers(): void
    {
        $js = self::script((string) $this->get('/admin/profile-fields/create')->assertOk()->getContent(), 'data-tn-admin-key-value-a11y');

        foreach ([...self::FORBIDDEN, 'stopPropagation', 'addEventListener(\'keydown', 'addEventListener(\'click', 'aria-label\''] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the H-N6 directive: {$forbidden}");
        }
        $this->assertStringContainsString('window.__tnAdminKeyValueA11y', $js);
        $this->assertStringContainsString("Alpine.directive('tn-key-value-a11y'", $js);
        $this->assertStringContainsString("input.setAttribute('aria-labelledby', heads[index].id)", $js, 'the visible column header names the input');
        $this->assertStringContainsString('heads.length !== 2', $js, 'a changed vendor table is reported, not guessed');
        $this->assertStringContainsString('utilities.effect(', $js, 'reacts to row changes through Alpine, without an observer');
        $this->assertSame(1, preg_match_all('/addEventListener\(/', $js), 'one listener only');
        $this->assertStringContainsString("document.addEventListener('alpine:init'", $js, '... and it is the Alpine start-up event');
    }

    public function test_h_n7_the_vendor_notification_markup_and_container_seam_are_pinned(): void
    {
        $path = 'vendor/filament/notifications/src/Notification.php';
        $this->assertSame('9e793d004c27a1eb4d424e6f2ce61e8c0876dd0f618cd3e43684960750a6ac22', hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the H-N7 close-button contract before updating this pin.");

        $source = (string) file_get_contents(base_path($path));
        $this->assertStringContainsString('$static = app(static::class, [\'id\' => $id ?? Str::orderedUuid()]);', $source, 'make() resolves through the container');
        $this->assertStringContainsString('// If the container constructs an instance of child class', $source, 'fromArray() re-dispatches to a bound child class');
        $this->assertSame(1, substr_count($source, 'class="fi-icon-btn fi-no-notification-close-btn"'), 'the root cause: one icon-only close button ...');
        $this->assertSame(0, preg_match('/fi-no-notification-close-btn"[^>]*aria-label/s', $source), '... without a name');
    }

    public function test_h_n7_every_notification_close_button_is_named_in_the_admin_language(): void
    {
        // The container binding is what reaches every toast; fail loudly if another provider replaced it.
        $this->assertInstanceOf(\App\Filament\Admin\Support\AccessibleNotification::class, \Filament\Notifications\Notification::make());

        $this->assertSame('Close', tn_trans('Close', [], 'en'));
        $this->assertSame('Đóng', tn_trans('Close', [], 'vi'));
        $label = 'aria-label="'.e(tn_trans('Close')).'"'; // the request's admin language (EN / VI proven in the browser)

        $made = \Filament\Notifications\Notification::make()->title('Saved')->success()->toHtml();
        $this->assertSame(1, preg_match_all('/<button\b[^>]*fi-no-notification-close-btn[^>]*>/', $made, $buttons));
        $this->assertStringContainsString($label, $buttons[0][0]);
        $this->assertSame(1, substr_count($made, 'aria-label='), 'only the close button is changed');

        // The Livewire notifications component rebuilds toasts from arrays.
        $restored = \Filament\Notifications\Notification::fromArray(\Filament\Notifications\Notification::make()->title('Saved')->toArray())->toHtml();
        $this->assertMatchesRegularExpression('/<button\b[^>]*fi-no-notification-close-btn[^>]*'.preg_quote($label, '/').'/', $restored);
    }

    public function test_h_n8_the_tinymce_focus_frame_contract_is_pinned(): void
    {
        $pins = [
            'public/vendor/tinymce/tinymce.min.js' => 'ab352ce0c75efbc2f56cd3ceadacf2ec2514392d57f745b52c02c6c7015a7ed1',
            'public/vendor/tinymce/skins/ui/oxide/skin.min.css' => 'a58ba18c36b360831733006e47c45d7cd1323cf957cfa2b82ccb4429767e8539',
            'public/vendor/tinymce/skins/ui/oxide-dark/skin.min.css' => '5c9fe0a92d4e0ef7ade03710844a4817102c42469aa401e2d7b93bb2e52531d1',
        ];
        foreach ($pins as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), "TinyMCE changed {$path}: re-verify the H-N8 focus frame before updating this pin.");
        }
        foreach (['oxide', 'oxide-dark'] as $skin) {
            $css = (string) file_get_contents(base_path("public/vendor/tinymce/skins/ui/{$skin}/skin.min.css"));
            $this->assertMatchesRegularExpression('/\.tox-edit-area::before\{[^}]*opacity:0/', $css, "{$skin}: the frame exists and is hidden by default");
            $this->assertStringContainsString('.tox-edit-focus .tox-edit-area::before{opacity:1}', $css, "{$skin}: TinyMCE shows it only behind its own class");
        }
    }

    public function test_h_n8_the_core_editor_shows_the_frame_while_its_editing_window_has_focus(): void
    {
        $html = (string) $this->get('/admin/posts/create')->assertOk()->getContent();

        $this->assertStringContainsString('[data-cms-editor-label] .tox.cms-re-edit-focus .tox-edit-area::before{opacity:1;}', $html, 'scoped to the Core editor');
        $this->assertStringContainsString("editWindow.addEventListener('focus', markFocus)", $html);
        $this->assertStringContainsString("editWindow.addEventListener('blur', clearFocus)", $html);
        $this->assertStringContainsString("editor.on('keyup', markFocus)", $html, 'the keyboard entry Firefox does not announce with a focus event');
        $this->assertStringContainsString('if (editor.hasFocus())', $html, 'only marked while TinyMCE reports focus');
        foreach (['focusin', 'mousedown'] as $type) {
            $this->assertStringContainsString("document.addEventListener('{$type}', clearFocus, true)", $html);
            $this->assertStringContainsString("document.removeEventListener('{$type}', clearFocus, true)", $html, "{$type} listener is removed with the editor");
        }
        $this->assertSame(0, preg_match('/clearFocus[^\n]*(preventDefault|stopPropagation)/', $html), 'the listeners only read');
    }

    public function test_h_n9_the_vendor_escape_guard_defect_is_pinned(): void
    {
        $blade = (string) file_get_contents(base_path('vendor/filament/forms/resources/views/components/select.blade.php'));
        $this->assertStringContainsString('x-on:keydown.esc="select.dropdown.isActive && $event.stopPropagation()"', $blade, 'Filament still guards on a property its dropdown element does not have');

        $select = (string) file_get_contents(base_path('vendor/filament/support/resources/js/utilities/select.js'));
        $this->assertStringContainsString('this.isOpen', $select, 'the open state the Core guard reads');
    }

    public function test_h_n9_the_select_escape_guard_is_scoped_to_one_select_and_cleaned_up(): void
    {
        $js = self::script((string) $this->get('/admin/users/create')->assertOk()->getContent(), 'data-tn-admin-select-a11y');

        $this->assertStringContainsString('function guardEscape(el, utilities)', $js);
        $this->assertStringContainsString("el.addEventListener('keydown', onCapture, true)", $js, 'reads the open state before the select closes itself');
        $this->assertStringContainsString("el.addEventListener('keydown', onBubble, false)", $js, 'stops only after the select handled the key');
        $this->assertStringContainsString("el.removeEventListener('keydown', onCapture, true)", $js);
        $this->assertStringContainsString("el.removeEventListener('keydown', onBubble, false)", $js);
        $this->assertSame(1, substr_count($js, 'event.stopPropagation()'), 'one stop, guarded by the captured open state');
        $this->assertMatchesRegularExpression("/event\.key === 'Escape' && wasOpen\)\s*\{\s*wasOpen = false;\s*event\.stopPropagation\(\);/", $js);
        $this->assertStringNotContainsString('preventDefault', $js, 'the select keeps its own default handling');
        $this->assertSame(0, preg_match('/(window|document)\.addEventListener\(\s*[\'"]keydown/', $js), 'no global key interception');
    }
}
