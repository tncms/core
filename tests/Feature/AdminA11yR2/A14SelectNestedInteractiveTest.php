<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-A14-FINAL-CLOSURE-R1. A14 (WCAG 4.1.2, Level A, axe `nested-interactive`): Filament's
 * searchable / multiple select renders each selected value as a badge whose "Remove <label>"
 * <button> sits INSIDE the trigger <button class="fi-select-input-btn">. Interactive content
 * inside a button is invalid HTML and its name leaks into the trigger's accessible name.
 *
 * Core fix, without editing vendor/: every Filament Select gets the Core Alpine hook
 * `x-tn-select-a11y` through the public Select::configureUsing() + extraAlpineAttributes() API.
 * A Core-owned Alpine directive (BODY_END, signed-in pages) then moves Filament's value container
 * (`select.selectedDisplay`) out of the trigger to be its sibling - the same pattern Filament
 * already uses for the single-select "Clear selection" button - names the trigger after the field
 * label and describes it with the selected values. Filament keeps every element reference, so
 * search, add, remove, clear, Livewire state sync and destroy are unchanged.
 *
 * The directive relies on internals of Filament's select JS. The pins below fail visibly when
 * Filament changes those files, so the contract is re-verified before any upgrade.
 */
final class A14SelectNestedInteractiveTest extends TestCase
{
    use RefreshDatabase;

    /** Filament v5.6.6 files whose behaviour the A14 directive depends on (sha256). */
    private const VENDOR_PINS = [
        'vendor/filament/support/resources/js/utilities/select.js' => '5d4456d125d9f868440363168bd788ab6f9fb59416b889a7282426eb49dc3121',
        'vendor/filament/forms/resources/js/components/select.js' => '290d012eff2b8724f033b6f336d10bb6e45b8c7189cc225c178926d94523aed4',
        'vendor/filament/forms/dist/components/select.js' => '570e42e0f8aad4da8877f1d63f7afb87d88998e3b85f7aa7afeabc8a839e8060',
        'vendor/filament/forms/resources/views/components/select.blade.php' => '5ae461285aea96af9f2ed0c7eabd0068e002a5b4c2a279f3f02947efb6d5aed1',
        'vendor/filament/forms/resources/views/components/field-wrapper.blade.php' => 'b7a2aa2ba0f6d3a80874d97e7fb1d5af07abf5675909f1754a255d9dba8437cb',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    /** @return list<string> markup of each non-native select Alpine root, up to its x-ref child */
    private function selectRoots(string $html): array
    {
        preg_match_all('/x-data="selectFormComponent\(.*?<div x-ref="select">/s', $html, $m);

        return $m[0];
    }

    private function directiveScript(string $html): string
    {
        preg_match('/<script[^>]*data-tn-admin-select-a11y[^>]*>(.*?)<\/script>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_a14_the_vendor_select_contract_the_directive_relies_on_is_pinned(): void
    {
        foreach (self::VENDOR_PINS as $path => $sha) {
            $this->assertSame($sha, hash_file('sha256', base_path($path)), "Filament changed {$path}: re-verify the A14 directive contract before updating this pin.");
        }

        $select = (string) file_get_contents(base_path('vendor/filament/support/resources/js/utilities/select.js'));
        foreach ([
            "this.selectButton = document.createElement('button')",
            "this.selectedDisplay = document.createElement('div')",
            'this.selectButton.appendChild(this.selectedDisplay)',
            'this.container.appendChild(this.selectButton)',
            "removeButton.className = 'fi-badge-delete-btn'",
            'this.selectedDisplay.replaceChildren(fragment)',
            'this.dropdownId = ',
        ] as $contract) {
            $this->assertStringContainsString($contract, $select);
        }
        $this->assertStringContainsString('select: null,', (string) file_get_contents(base_path('vendor/filament/forms/resources/js/components/select.js')));
        $this->assertStringContainsString('$getExtraAlpineAttributes()', (string) file_get_contents(base_path('vendor/filament/forms/resources/views/components/select.blade.php')));
    }

    public function test_a14_post_categories_multi_select_carries_the_core_hook_with_its_field_id(): void
    {
        $html = (string) $this->get('/admin/posts/create')->assertOk()->getContent();
        $roots = array_values(array_filter($this->selectRoots($html), fn (string $t): bool => str_contains($t, "statePath: 'data.category_ids'")));

        $this->assertNotEmpty($roots, 'fixture: the Categories TermSelect renders a non-native select');
        $this->assertStringContainsString('x-tn-select-a11y="form.category_ids"', $roots[0]);
    }

    public function test_a14_the_hidden_categories_label_that_names_the_trigger_is_descriptive(): void
    {
        $html = (string) $this->get('/admin/posts/create')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<label\s+for="form\.category_ids"[^>]*>(.*?)<\/label>/s', $html, $m), 'the Categories field keeps a (visually hidden) label');
        $this->assertSame(tn_trans('Categories'), trim(strip_tags($m[1])));
        $this->assertStringNotContainsString('Category ids', $m[1]);
    }

    public function test_a14_every_non_native_select_on_the_user_form_carries_the_core_hook(): void
    {
        $html = (string) $this->get('/admin/users/create')->assertOk()->getContent();
        $roots = $this->selectRoots($html);

        $this->assertNotEmpty($roots, 'fixture: the Roles multi-select renders a non-native select');
        foreach ($roots as $tag) {
            $this->assertMatchesRegularExpression('/\bx-tn-select-a11y="[A-Za-z0-9_.:-]+"/', $tag, $tag);
        }
        $this->assertStringContainsString('x-tn-select-a11y="form.roles"', implode("\n", $roots));
    }

    public function test_a14_only_a_safe_field_id_is_passed_to_the_unescaped_alpine_attribute(): void
    {
        $this->assertSame(['x-tn-select-a11y' => 'form.category_ids'], AdminPanelProvider::selectAccessibilityAttributes('form.category_ids'));
        $this->assertSame(['x-tn-select-a11y' => ''], AdminPanelProvider::selectAccessibilityAttributes('x" onmouseover="alert(1)'));
        $this->assertSame(['x-tn-select-a11y' => ''], AdminPanelProvider::selectAccessibilityAttributes('<b>'));
        $this->assertSame(['x-tn-select-a11y' => ''], AdminPanelProvider::selectAccessibilityAttributes("form.roles\n"));
        $this->assertSame(['x-tn-select-a11y' => ''], AdminPanelProvider::selectAccessibilityAttributes(''));
        $this->assertSame(['x-tn-select-a11y' => ''], AdminPanelProvider::selectAccessibilityAttributes(null));
    }

    public function test_a14_the_directive_is_scoped_and_lifecycle_safe(): void
    {
        $js = $this->directiveScript((string) $this->get('/admin/posts/create')->assertOk()->getContent());

        $this->assertNotSame('', $js, 'the A14 directive script is emitted on signed-in admin pages');
        $this->assertStringContainsString("directive('tn-select-a11y'", $js);
        $this->assertStringContainsString('cleanup(', $js);
        $this->assertStringContainsString('isMultiple', $js);
        foreach (['MutationObserver', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'aria-hidden', 'Element.prototype', 'EventTarget.prototype'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $js, "forbidden in the A14 directive: {$forbidden}");
        }
        $this->assertSame(0, preg_match('/document\.addEventListener\(\s*[\'"](?!alpine:init|livewire:init)/', $js), 'no document-level event interception (only the framework init events)');
    }

    /**
     * CORE-H-Z1-REMEDIATION-R1, H-C1 (WCAG 4.1.2): Filament renders the field <label> without an id
     * and outside the select's wire:ignore region, so a Livewire re-render (e.g. a server-side
     * validation error) strips the id the directive gave it and the trigger's aria-labelledby
     * dangled. The Livewire seam the fix uses is pinned here; the full lifecycle (initial render,
     * validation round trips, re-interaction, listener counts) is proven in a real browser
     * (docs/core-h-z1-remediation-r1/04-h-c1-a14-lifecycle.md).
     */
    public function test_h_c1_the_livewire_morph_seam_the_relink_relies_on_is_pinned(): void
    {
        $path = base_path('vendor/livewire/livewire/dist/livewire.min.js');
        $this->assertSame('c534744ff7545741f37e57d0f8b5a20c70760c7ff1df4a18bd1a1f094adfce1e', hash_file('sha256', $path), 'Livewire changed its bundle: re-verify the morphed / island.morphed hooks before updating this pin.');
        $bundle = (string) file_get_contents($path);
        $this->assertStringContainsString('"morphed",{el:', $bundle);
        $this->assertStringContainsString('"island.morphed"', $bundle);
        $this->assertStringContainsString('hook:', $bundle);

        $wrapper = (string) file_get_contents(base_path('vendor/filament/forms/resources/views/components/field-wrapper.blade.php'));
        $this->assertMatchesRegularExpression('/@if \(\$labelTag === \'label\'\)\s*for="\{\{ \$id \}\}"\s*@else\s*id="\{\{ \$id \}\}-label"/', $wrapper, 'Filament still gives a <label> no server-side id (the reason the directive must re-link)');
    }

    public function test_h_c1_the_directive_relinks_after_livewire_morphs_without_accumulating_state(): void
    {
        $js = $this->directiveScript((string) $this->get('/admin/users/create')->assertOk()->getContent());

        $this->assertStringContainsString("Livewire.hook('morphed', onMorphed)", $js);
        $this->assertStringContainsString("Livewire.hook('island.morphed', onMorphed)", $js);
        $this->assertSame(1, substr_count($js, "Livewire.hook('morphed'"), 'one morphed hook per page, guarded by the page-level flag');
        $this->assertStringContainsString('window.__tnAdminSelectA11y = true', $js);
        $this->assertStringContainsString('links.add(link)', $js);
        $this->assertStringContainsString('links.delete(link)', $js, 'Alpine cleanup removes the registry entry');
        $this->assertStringContainsString('root.contains(link.el)', $js, 'only selects inside the morphed component are re-linked');
        $this->assertStringContainsString("getAttribute('aria-labelledby') !== labelledBy", $js, 'idempotent: attributes are only written when they changed');
    }

    public function test_a14_the_detached_value_layout_is_styled_only_behind_the_core_marker(): void
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();
        preg_match('/<style[^>]*data-tn-admin-a11y[^>]*>(.*?)<\/style>/s', $html, $m);
        $css = $m[1] ?? '';

        $this->assertStringContainsString('.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-btn', $css);
        $this->assertStringContainsString('.fi-select-input-ctn.tn-select-a11y-detached > .fi-select-input-value-ctn', $css);
        $this->assertMatchesRegularExpression('/tn-select-a11y-detached > \.fi-select-input-value-ctn \.fi-badge-delete-btn\s*\{[^}]*pointer-events:\s*auto/', $css);
    }

    public function test_a14_the_directive_script_is_not_on_guest_pages(): void
    {
        auth()->logout();

        $this->assertSame('', $this->directiveScript((string) $this->get('/admin/login')->assertOk()->getContent()));
    }
}
