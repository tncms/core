<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-REMAINING-DEFECTS-REMEDIATION-R1, Workstream A (CORE-ADMIN-A11Y-R2). Core-side fixes for the
 * admin accessibility findings left open by the R1 closure. Nothing under vendor/ is edited.
 *
 * A7 (4.1.2): the Filament section collapse icon-button had aria-label="" (no accessible name).
 * A8 (4.1.2): aria-expanded was bound on the content <div>, where the attribute is not allowed.
 *     Core overrides the `filament::components.section.index` view (Laravel vendor-view override)
 *     to name the button after the section heading and carry aria-expanded on the button.
 * A9 (4.1.2): TinyMCE's resize handle (role=separator, focusable) lacked aria-valuenow.
 * A10 (2.5.8): the select "Clear selection" button was 16x16 CSS px.
 * A12 (2.1.1 / 2.4.3): Escape did not close the open mobile sidebar.
 * A13 / V-2 (2.4.7): sidebar items and dashboard links had no focus indicator beyond the hover style.
 * V-1 (2.4.11): the sticky 64px top bar could fully cover the focused element at 400% zoom.
 */
final class AdminAccessibilityR2Test extends TestCase
{
    use RefreshDatabase;

    /** sha256 of vendor/filament/support/resources/views/components/section/index.blade.php, Filament v5.6.6. */
    private const VENDOR_SECTION_SHA256 = 'd1a485d321ad053815538a5288e4196c63b854675a73cc226193bda54c900efa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private function postCreateHtml(): string
    {
        return (string) $this->get('/admin/posts/create')->assertOk()->getContent();
    }

    private function adminCss(): string
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();
        preg_match('/<style[^>]*data-tn-admin-a11y[^>]*>(.*?)<\/style>/s', $html, $m);

        return $m[1] ?? '';
    }

    public function test_a7_every_section_collapse_button_has_a_non_empty_accessible_name(): void
    {
        $html = $this->postCreateHtml();
        $count = preg_match_all('/<button\b[^>]*\bfi-section-collapse-btn\b[^>]*>/', $html, $m);

        $this->assertGreaterThanOrEqual(4, $count, 'fixture: SEO, Metrics, Categories, Tags are collapsible');
        foreach ($m[0] as $tag) {
            $this->assertMatchesRegularExpression('/\baria-label="[^"]*\S[^"]*"/', $tag, $tag);
        }
        $this->assertStringNotContainsString('aria-label=""', implode("\n", $m[0]));
    }

    public function test_a7_the_collapse_button_is_named_after_its_section_heading(): void
    {
        $html = $this->postCreateHtml();
        preg_match_all('/<button\b[^>]*\bfi-section-collapse-btn\b[^>]*\baria-label="([^"]*)"/', $html, $m);

        $this->assertContains('SEO', $m[1]);
    }

    public function test_a8_aria_expanded_is_on_the_toggle_button_not_on_the_content_div(): void
    {
        $html = $this->postCreateHtml();
        preg_match_all('/<button\b[^>]*\bfi-section-collapse-btn\b[^>]*>/', $html, $buttons);
        foreach ($buttons[0] as $tag) {
            $this->assertStringContainsString('x-bind:aria-expanded="(! isCollapsed).toString()"', $tag, $tag);
        }
        $this->assertSame(0, preg_match('/<div\b[^>]*aria-expanded[^>]*\bfi-section-content-ctn\b/', $html));
        $this->assertSame(0, preg_match('/<div\b[^>]*\bfi-section-content-ctn\b[^>]*aria-expanded/', $html));
    }

    public function test_a7_a8_the_override_tracks_the_vendor_view_it_was_copied_from(): void
    {
        $vendor = base_path('vendor/filament/support/resources/views/components/section/index.blade.php');
        $this->assertSame(
            self::VENDOR_SECTION_SHA256,
            hash_file('sha256', $vendor),
            'Filament changed the section view: re-sync resources/views/vendor/filament/components/section/index.blade.php',
        );
        $this->assertFileExists(resource_path('views/vendor/filament/components/section/index.blade.php'));
    }

    public function test_a7_a8_the_override_differs_from_vendor_only_in_the_collapse_button_and_content_div(): void
    {
        $vendor = file(base_path('vendor/filament/support/resources/views/components/section/index.blade.php'), FILE_IGNORE_NEW_LINES);
        $core = file(resource_path('views/vendor/filament/components/section/index.blade.php'), FILE_IGNORE_NEW_LINES);
        $removed = array_values(array_diff($vendor, $core));
        $added = array_values(array_diff($core, $vendor));

        $this->assertSame(['x-bind:aria-expanded="(! isCollapsed).toString()"'], array_map('trim', $removed));
        foreach ($added as $line) {
            $this->assertMatchesRegularExpression(
                '/^\s*(\{\{--.*--\}\}|@php|@endphp|\$collapseLabel\b.*|:label="\$collapseLabel"|x-bind:aria-expanded="\(! isCollapsed\)\.toString\(\)")\s*$/',
                $line,
                'unexpected override line: '.$line,
            );
        }
    }

    public function test_a9_the_rich_editor_gives_the_resize_handle_a_current_value(): void
    {
        $view = file_get_contents(resource_path('views/filament/admin/components/rich-editor.blade.php'));

        $this->assertStringContainsString('.tox-statusbar__resize-handle', $view);
        $this->assertStringContainsString("'aria-valuenow'", $view);
        $this->assertStringContainsString("'aria-valuemin'", $view);
        $this->assertStringContainsString("'aria-valuemax'", $view);
        $this->assertStringContainsString("editor.on('ResizeEditor'", $view);
    }

    public function test_a10_select_clear_button_has_a_24px_hit_area_and_a_focus_indicator(): void
    {
        $css = $this->adminCss();

        $this->assertStringContainsString('.fi-select-input .fi-select-input-value-remove-btn', $css);
        $this->assertStringContainsString('min-width: 1.5rem', $css);
        $this->assertStringContainsString('min-height: 1.5rem', $css);
        $this->assertStringContainsString('.fi-select-input .fi-select-input-value-remove-btn:focus-visible', $css);
    }

    public function test_a10b_multi_select_badge_delete_button_has_a_24px_hit_area(): void
    {
        $css = $this->adminCss();

        $this->assertMatchesRegularExpression('/\.fi-select-input-value-badges-ctn \.fi-badge-delete-btn\s*\{[^}]*min-width: 1\.5rem;[^}]*min-height: 1\.5rem;/s', $css);
        $this->assertStringContainsString('.fi-select-input-value-badges-ctn .fi-badge-delete-btn:focus-visible', $css);
    }

    public function test_a5b_multi_select_badge_label_uses_the_aa_shade_in_light_mode_only(): void
    {
        $css = $this->adminCss();

        $this->assertMatchesRegularExpression('/html:not\(\.dark\) \.fi-select-input-value-badges-ctn \.fi-badge\.fi-text-color-600\s*\{\s*--text: var\(--color-700\);\s*\}/', $css);
    }

    public function test_a13_sidebar_items_have_an_outline_focus_indicator(): void
    {
        $css = $this->adminCss();

        $this->assertStringContainsString('.fi-sidebar .fi-sidebar-item-btn:focus-visible', $css);
        $this->assertStringContainsString('.fi-sidebar .fi-sidebar-group-dropdown-trigger-btn:focus-visible', $css);
        $this->assertMatchesRegularExpression('/fi-sidebar-item-btn:focus-visible[^{]*\{[^}]*outline: 2px solid/s', $css);
    }

    public function test_v2_filament_links_have_a_focus_indicator_distinct_from_hover(): void
    {
        $css = $this->adminCss();

        $this->assertMatchesRegularExpression('/\.fi-link:focus-visible\s*\{[^}]*outline: 2px solid/s', $css);
    }

    public function test_v1_admin_scroll_padding_keeps_focus_clear_of_the_sticky_top_bar(): void
    {
        $css = $this->adminCss();

        $this->assertMatchesRegularExpression('/html\s*\{[^}]*scroll-padding-top:\s*[\d.]+rem/s', $css);
    }

    public function test_a12_escape_closes_the_mobile_sidebar_through_a_scoped_core_script(): void
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<script[^>]*data-tn-admin-sidebar-escape[^>]*>(.*?)<\/script>/s', $html, $m));
        $js = $m[1][0];
        $this->assertStringContainsString("event.key !== 'Escape'", $js);
        $this->assertStringContainsString('event.defaultPrevented', $js);
        $this->assertStringContainsString('(min-width: 64rem)', $js);
        $this->assertStringContainsString("Alpine.store('sidebar')", $js);
        $this->assertStringContainsString('.fi-topbar-open-sidebar-btn', $js);
        $this->assertStringContainsString("closest('.fi-topbar-ctn')", $js, 'Escape also works from top bar controls');
        $this->assertStringContainsString('isContentEditable', $js, 'text entry keeps its own Escape');
        $this->assertStringContainsString('.fi-dropdown-panel', $js, 'an open dropdown keeps its own Escape');
        $this->assertStringContainsString('.fi-modal-open', $js, 'an open modal keeps its own Escape');
        $this->assertStringNotContainsString('innerHTML', $js);
    }

    public function test_a12_the_escape_script_is_not_on_guest_pages(): void
    {
        auth()->logout();
        $html = (string) $this->get('/admin/login')->getContent();

        $this->assertStringNotContainsString('data-tn-admin-sidebar-escape', $html);
    }
}
