<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Filament\Admin\Pages\HomepageLayoutPage;
use App\Filament\Admin\Pages\ImportDemoPage;
use App\Filament\Admin\Pages\ThemesPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage;
use TheNguyen\CMS\Models\Widget;
use TheNguyen\CMS\Models\WidgetArea;

/**
 * CORE-H-Z1-PRE-RERUN-RESIDUAL-CLOSURE-R1: the residuals R2 left open, mitigated in Core without editing vendor/.
 * PHPUnit guards structure, scope, the declared focus destinations and the vendor seams; target geometry,
 * listbox semantics and focus movement are proven in Chrome and Firefox
 * (docs/core-h-z1-pre-rerun-residual-closure-r1/03-05, 08).
 *
 *  - L-9 (WCAG 2.5.8): Filament's icon button keeps a -0.5rem margin; in the Homepage layout card row it pulled the
 *    36 px targets 10 px into each other. Core removes that margin inside the row only.
 *  - L-10 (WCAG 1.3.1 / 4.1.2): Filament's select puts role=listbox on its dropdown panel, which also holds the
 *    search input, while the options sit in a plain <ul>. Core makes the panel a group and the options <ul> the
 *    named listbox, re-applied after each of Filament's renderOptions() calls.
 *  - L-2 (WCAG 2.4.3): a same-page Livewire call disables its own button (wire:loading) and focus fell to <body>.
 *    Core returns it to the control, its equivalent, or the destination the control declared.
 */
final class HZ1PreRerunResidualClosureTest extends TestCase
{
    use RefreshDatabase;

    /** Filament files whose behaviour the integrations depend on (sha256). */
    private const PINS = [
        // L-9 root cause: .fi-icon-btn { @apply relative -m-2 flex size-9 ... }
        'vendor/filament/support/resources/css/components/icon-button.css' => '75f39d9435732cb2a7131dea882b8715012c8d96cd60b7d51c40b651906cd476',
        // L-2 root cause: the button merges wire:loading.attr="disabled".
        'vendor/filament/support/resources/views/components/button/index.blade.php' => '71e514ecae4c4b833595e95e451b0900516ce06a8926312faf0be29664b9136c',
        'vendor/filament/support/resources/views/components/icon-button.blade.php' => 'bba441e14172ea85424d7aaabc12a586e5296e643e491b0401e9812bd9b8ac4c',
    ];

    private const FORBIDDEN = ['MutationObserver', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'Element.prototype', 'EventTarget.prototype', 'preventDefault', 'stopPropagation'];

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

    public function test_the_vendor_seams_are_pinned(): void
    {
        foreach (self::PINS as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify CORE-H-Z1-PRE-RERUN-RESIDUAL-CLOSURE-R1 before updating this pin.");
        }
        $css = (string) file_get_contents(base_path('vendor/filament/support/resources/css/components/icon-button.css'));
        $this->assertStringContainsString('@apply relative -m-2 flex size-9', $css, 'L-9 root cause: the icon button negative margin');
        $button = (string) file_get_contents(base_path('vendor/filament/support/resources/views/components/button/index.blade.php'));
        $this->assertStringContainsString("'wire:loading.attr' => \$tag === 'button' ? 'disabled' : null", $button, 'L-2 root cause: the button disables itself while loading');

        $select = (string) file_get_contents(base_path('vendor/filament/support/resources/js/utilities/select.js'));
        foreach ([
            "this.dropdown.setAttribute('role', 'listbox')",
            "this.optionsList = document.createElement('ul')",
            'renderOptions() {',
            "this.optionsList.innerHTML = ''",
            "optionGroup.className = 'fi-select-input-option-group'",
            "optionGroupLabel.className = 'fi-dropdown-header'",
            "groupOptionsList.className = 'fi-dropdown-list'",
            "option.setAttribute('role', 'option')",
        ] as $needle) {
            $this->assertStringContainsString($needle, $select, "L-10 seam: {$needle}");
        }
        $this->assertStringNotContainsString('[role="listbox"]', $select, 'Filament never finds its elements by the listbox role');
        $this->assertStringNotContainsString("getAttribute('role')", $select);
        $dist = (string) file_get_contents(base_path('vendor/filament/forms/dist/components/select.js'));
        $this->assertGreaterThan(5, substr_count($dist, 'renderOptions()'), 'the shipped bundle keeps the renderOptions method name the directive wraps');
        $this->assertStringContainsString('optionsList', $dist);
    }

    public function test_l9_the_card_row_removes_the_icon_button_margin_only_inside_the_row(): void
    {
        $html = (string) $this->get('/admin/homepage-layout')->assertOk()->getContent();
        $this->assertStringContainsString('.tnl-card .tnl-actions > .fi-icon-btn, .tnl-card .tnl-actions > .fi-icon-btn:has(.fi-icon) { margin:0; }', $html);
        $this->assertStringContainsString('.tnl-actions { display:flex; flex-flow:row wrap; align-items:center; gap:.375rem; }', $html, 'the row gap separates the targets');
    }

    public function test_l10_the_select_directive_gives_the_options_list_the_listbox_semantics(): void
    {
        $js = self::script((string) $this->get('/admin/settings')->assertOk()->getContent(), 'data-tn-admin-select-a11y');

        $this->assertStringContainsString('function structureListbox(el, select, fieldId, utilities)', $js);
        $this->assertStringContainsString('structureListbox(el, select, directive.expression, utilities);', $js, 'applied to single and multiple selects');
        $this->assertStringContainsString("setAttr(dropdown, 'role', 'group');", $js);
        $this->assertStringContainsString("setAttr(list, 'role', 'listbox');", $js);
        $this->assertStringContainsString("setAttr(list, 'aria-labelledby', labelId);", $js, 'the listbox is named by the field label');
        $this->assertStringContainsString("dropdown.removeAttribute('aria-multiselectable');", $js);
        $this->assertStringContainsString("setAttr(list, 'aria-multiselectable', 'true');", $js);
        $this->assertStringContainsString("setAttr(options, 'role', 'group');", $js, 'option groups: the inner <ul> is the group');
        $this->assertStringNotContainsString("setAttr(child, 'role', 'group')", $js, 'HTML does not allow role=group on <li>');
        // One instance method is wrapped, idempotently, and restored on cleanup; nothing global is patched.
        $this->assertStringContainsString('select.renderOptions = wrapped;', $js);
        $this->assertStringContainsString('original.__tnListbox', $js);
        $this->assertStringContainsString('select.renderOptions = original;', $js);
        $this->assertStringContainsString('mismatch(el);', $js);
        foreach (['MutationObserver', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'aria-hidden', 'Element.prototype', 'EventTarget.prototype', '.prototype.renderOptions'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the select directive: {$forbidden}");
        }
    }

    public function test_l2_the_call_focus_script_is_emitted_once_for_signed_in_admins_only(): void
    {
        $html = (string) $this->get('/admin/homepage-layout')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-tn-admin-call-focus'));
        $this->assertLessThan(strpos($html, 'data-tn-admin-call-focus'), strpos($html, 'data-tn-admin-save-focus'));

        auth()->logout();
        $this->assertStringNotContainsString('data-tn-admin-call-focus', (string) $this->get('/admin/login')->assertOk()->getContent());
    }

    public function test_l2_the_call_focus_script_only_reads_and_restores_focus(): void
    {
        $js = self::script((string) $this->get('/admin/homepage-layout')->assertOk()->getContent(), 'data-tn-admin-call-focus');

        $this->assertNotSame('', $js);
        foreach ([...self::FORBIDDEN, 'setAttribute(', 'removeAttribute(', "addEventListener('keydown"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the call-focus script: {$forbidden}");
        }
        $this->assertSame(2, substr_count($js, '{ capture: true, passive: true }'), 'the click and pointerdown readers are passive');
        $this->assertStringContainsString("button.getAttribute('wire:loading.attr') !== 'disabled'", $js, 'only controls with the wire:loading root cause');
        $this->assertStringContainsString('mount[A-Za-z]*Action|callMountedAction|save[A-Za-z]*|create[A-Za-z]*', $js, 'dialogs and saves stay with action-focus / save-focus');
        $this->assertStringContainsString("if (el.classList.contains('fi-disabled') || el.getAttribute('aria-disabled') === 'true') {", $js, 'a server-disabled control (a tooltip button keeps no disabled attribute) is skipped, not waited for');
        $this->assertStringContainsString('if (control && document.activeElement === control) {', $js, 'only a control that held focus is restored: no focus is forced on pointer users whose click does not focus');
        $this->assertStringContainsString("document.addEventListener('pointerdown'", $js, 'a pointer press elsewhere cancels the restore');
        $this->assertStringContainsString('owner.contains(pending.el)', $js, "only a request of the control's own component counts");
        $this->assertStringContainsString('document.getElementById(p.fallback[j])', $js, 'declared destinations are ids, never constructed selectors');
        $this->assertSame(1, substr_count($js, 'querySelectorAll('), 'the only query is the fixed wire:click attribute list');
        $this->assertSame(1, substr_count($js, 'Livewire.interceptMessage('));
        $this->assertSame(1, substr_count($js, "Livewire.hook('morphed'"));
        $this->assertSame(0, preg_match('/document\.addEventListener\(\s*[\'"](?!click|pointerdown|livewire:init)/', $js), 'only the passive click / pointerdown readers and the framework init event');
    }

    public function test_l2_save_focus_honours_a_declared_destination_when_its_form_is_gone(): void
    {
        $js = self::script((string) $this->get('/admin/homepage-layout')->assertOk()->getContent(), 'data-tn-admin-save-focus');

        $this->assertStringContainsString("fallback: (control.getAttribute('data-tn-focus-fallback') || '').split(/\\s+/).filter(Boolean)", $js);
        $this->assertStringContainsString('var fallback = document.getElementById(state.fallback[j]);', $js);
        $this->assertStringContainsString('if (fallback && usable(fallback, state))', $js);
    }

    public function test_l2_homepage_layout_controls_declare_where_focus_goes(): void
    {
        $page = Livewire::test(HomepageLayoutPage::class);
        $type = (string) array_key_first(array_merge(...array_values($page->instance()->sectionTypeGroups())));
        $this->assertNotSame('', $type, 'the active theme offers at least one section type');
        foreach ([1, 2, 3] as $n) {
            $page->set('addType', $type)->call('addSection');
        }
        $ids = array_column($page->get('document')['sections'], 'id');
        $this->assertCount(3, $ids);
        $html = $page->html();

        [$first, $middle, $last] = $ids;
        $this->assertStringContainsString('id="tnl-edit-'.$middle.'"', $html);
        $this->assertStringContainsString('id="tnl-up-'.$middle.'" data-tn-focus-fallback="tnl-down-'.$middle.' tnl-edit-'.$middle.'"', $html, 'Move up falls back to Move down, then Edit, of the same section');
        $this->assertStringContainsString('id="tnl-down-'.$middle.'" data-tn-focus-fallback="tnl-up-'.$middle.' tnl-edit-'.$middle.'"', $html);
        // Delete: the next section's Edit, else the previous one's, else the Add section select.
        $this->assertStringContainsString("wire:click=\"removeSection('{$middle}')\"", $html);
        $this->assertMatchesRegularExpression('/data-tn-focus-fallback="tnl-edit-'.$last.' tnl-edit-'.$first.' tnl-add-type"[^>]*wire:click="removeSection\(\''.$middle.'\'\)"|wire:click="removeSection\(\''.$middle.'\'\)"[^>]*data-tn-focus-fallback="tnl-edit-'.$last.' tnl-edit-'.$first.' tnl-add-type"/s', $html);
        $this->assertMatchesRegularExpression('/data-tn-focus-fallback="tnl-edit-'.$middle.' tnl-add-type"[^>]*wire:click="removeSection\(\''.$last.'\'\)"|wire:click="removeSection\(\''.$last.'\'\)"[^>]*data-tn-focus-fallback="tnl-edit-'.$middle.' tnl-add-type"/s', $html, 'the last section has no next one: the previous section, then Add section');
        $this->assertStringContainsString('id="tnl-add-type"', $html);

        $html = $page->call('editSection', $middle)->html();
        $this->assertMatchesRegularExpression('/wire:click="cancelEdit"[^>]*data-tn-focus-fallback="tnl-edit-'.$middle.'"|data-tn-focus-fallback="tnl-edit-'.$middle.'"[^>]*wire:click="cancelEdit"/s', $html, 'Cancel returns to the Edit button of the section it closes');
        $this->assertMatchesRegularExpression('/type="submit"[^>]*data-tn-focus-fallback="tnl-edit-'.$middle.'"|data-tn-focus-fallback="tnl-edit-'.$middle.'"[^>]*type="submit"/s', $html, 'Apply changes as well (save-focus)');

        $this->assertSame(1, substr_count($page->call('cancelEdit')->html(), 'id="tnl-edit-'.$middle.'"'), 'ids are unique');
    }

    public function test_l2_theme_activate_and_delete_declare_where_focus_goes(): void
    {
        $html = Livewire::test(ThemesPage::class)->html();

        preg_match_all('/<summary id="(tn-theme-details-[^"]+)"/', $html, $m);
        $order = $m[1];
        $this->assertGreaterThanOrEqual(2, count($order), 'the Core ships at least two themes');
        $this->assertSame($order, array_values(array_unique($order)), 'one details id per card');

        preg_match_all('/<button\b[^>]*>/s', $html, $buttons);
        $activate = $delete = 0;
        foreach ($buttons[0] as $tag) {
            if (preg_match('/wire:click="activate\(\'([^\']+)\'\)"/', $tag, $s)) {
                $activate++;
                // After Activate the button becomes the disabled "Activated" one: focus goes to the same card.
                $this->assertStringContainsString('data-tn-focus-fallback="tn-theme-details-'.$s[1].'"', $tag);
            }
            if (preg_match('/wire:click="delete\(\'([^\']+)\'\)"/', $tag, $s)) {
                $delete++;
                $i = array_search('tn-theme-details-'.$s[1], $order, true);
                $this->assertNotFalse($i);
                // After Delete the card is gone: the next card, else the previous card.
                $expected = implode(' ', array_values(array_filter([$order[$i + 1] ?? null, $order[$i - 1] ?? null])));
                $this->assertStringContainsString('data-tn-focus-fallback="'.$expected.'"', $tag);
            }
        }
        $this->assertGreaterThan(0, $activate, 'an inactive theme offers Activate');
        $this->assertGreaterThan(0, $delete, 'an inactive theme offers Delete');
    }

    public function test_l2_widget_controls_declare_where_focus_goes(): void
    {
        $area = WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'l2-sidebar', 'is_active' => true, 'sort_order' => 1]);
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[] = Widget::query()->create(['area_id' => $area->id, 'widget_type' => 'text', 'title' => 'W'.$n, 'settings' => [], 'sort_order' => $n, 'is_active' => true])->id;
        }
        [$first, $middle, $last] = $ids;
        $page = Livewire::test(WidgetsPage::class);
        $html = $page->html();

        $this->assertStringContainsString('id="tn-area-toggle-'.$area->id.'"', $html);
        foreach ($ids as $id) {
            // Edit switches its own call (openEditor <-> closeEditor), so it names itself.
            $this->assertMatchesRegularExpression('/id="tn-widget-edit-'.$id.'"[^>]*data-tn-focus-fallback="tn-widget-edit-'.$id.'"/s', $html);
        }
        $delete = fn (int $id): string => '/wire:click="deleteWidget\('.$id.'\)"[^>]*data-tn-focus-fallback="([^"]*)"/s';
        preg_match($delete($first), $html, $m);
        $this->assertSame('tn-widget-edit-'.$middle.' tn-area-toggle-'.$area->id, $m[1] ?? null, 'first row: the next Edit, then the area header');
        preg_match($delete($middle), $html, $m);
        $this->assertSame('tn-widget-edit-'.$last.' tn-widget-edit-'.$first.' tn-area-toggle-'.$area->id, $m[1] ?? null);
        preg_match($delete($last), $html, $m);
        $this->assertSame('tn-widget-edit-'.$middle.' tn-area-toggle-'.$area->id, $m[1] ?? null, 'last row: the previous Edit, then the area header');

        $html = $page->call('openEditor', $middle)->html();
        $this->assertMatchesRegularExpression('/wire:click="closeEditor" data-tn-focus-fallback="tn-widget-edit-'.$middle.'"/', $html, 'Cancel returns to the widget\'s Edit');
        $this->assertMatchesRegularExpression('/type="submit"[^>]*data-tn-focus-fallback="tn-widget-edit-'.$middle.'"/s', $html);
    }

    public function test_l2_demo_reset_declares_where_focus_goes(): void
    {
        // The real view, with one imported package whose theme is active (Reset and Import rendered) and one whose
        // theme is inactive (Reset only): packages() is the page's own read model, replaced here by fixed data.
        $html = Livewire::test(HZ1PreRerunDemoPageStub::class)->html();

        foreach (HZ1PreRerunDemoPageStub::IDS as $id) {
            $key = md5($id);
            $this->assertStringContainsString('<span id="tn-demo-name-'.$key.'" tabindex="-1">', $html);
            // After Reset its button is gone: the package's Import, else (inactive theme: no Import) its name.
            $this->assertMatchesRegularExpression('/wire:click="resetPackage\(\''.preg_quote($id, '/').'\'\)"[^>]*data-tn-focus-fallback="tn-demo-import-'.$key.' tn-demo-name-'.$key.'"/s', $html);
        }
        $active = md5(HZ1PreRerunDemoPageStub::IDS[0]);
        $this->assertMatchesRegularExpression('/id="tn-demo-import-'.$active.'"[^>]*wire:click="importPackage\(/s', $html);
        $this->assertStringNotContainsString('id="tn-demo-import-'.md5(HZ1PreRerunDemoPageStub::IDS[1]).'"', $html, 'no Import for an inactive theme');
    }
}

/** Test-only: the Import demo page with a fixed package list. */
final class HZ1PreRerunDemoPageStub extends ImportDemoPage
{
    public const IDS = ['theme:active:blog', 'theme:other:shop'];

    public function packages(): array
    {
        $package = fn (string $id, bool $inactive): array => [
            'id' => $id, 'name' => 'Demo '.$id, 'description' => '', 'preview' => null, 'type' => 'theme', 'owner' => 'x',
            'preset' => null, 'version' => '1.0.0', 'imported' => true, 'imported_at' => '2026-11-09 00:00', 'batch_id' => 'b1',
            'warnings' => [], 'inactive_theme' => $inactive, 'rollback' => true,
        ];

        return [self::IDS[0] => $package(self::IDS[0], false), self::IDS[1] => $package(self::IDS[1], true)];
    }
}
