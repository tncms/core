<?php

declare(strict_types=1);

namespace Tests\Feature\ThemeOptions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Services\ThemeOptionManager;

/**
 * CORE-THEME-OPTIONS-UX-1 — the Core schema contract: sections carry a validated
 * `group`, the schema groups cleanly into canonical tabs, legacy group-less
 * schemas stay backward-compatible (one General tab), an NgoHoangNguyen-style
 * grouped schema is compatible, and stored keys/values are untouched.
 */
final class ThemeOptionSchemaGroupingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = ['touxg_nogroup', 'touxg_mixed', 'touxg_ng'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSchema('touxg_nogroup', [
            ['key' => 'sec_a', 'label' => 'Section A', 'fields' => [
                ['key' => 'text1', 'label' => 'Text 1', 'type' => 'text', 'default' => 'one'],
            ]],
            ['key' => 'sec_b', 'label' => 'Section B', 'fields' => [
                ['key' => 'text2', 'label' => 'Text 2', 'type' => 'text', 'default' => 'two'],
            ]],
        ]);

        $this->writeSchema('touxg_mixed', [
            ['key' => 'sec_brand', 'group' => 'brand', 'label' => 'Brand', 'fields' => [
                ['key' => 'logo', 'label' => 'Logo', 'type' => 'image'],
            ]],
            ['key' => 'sec_colors', 'group' => 'Colors', 'label' => 'Colors', 'fields' => [
                ['key' => 'primary', 'label' => 'Primary', 'type' => 'color', 'default' => '#111111'],
            ]],
            ['key' => 'sec_unknown', 'group' => 'frobnicate', 'label' => 'Unknown', 'fields' => [
                ['key' => 'misc', 'label' => 'Misc', 'type' => 'text'],
            ]],
            ['key' => 'sec_missing', 'label' => 'No Group', 'fields' => [
                ['key' => 'plain', 'label' => 'Plain', 'type' => 'text'],
            ]],
        ]);

        // An NgoHoangNguyen-style grouped schema (compatibility fixture — this
        // Core-only phase never edits the real theme).
        $this->writeSchema('touxg_ng', [
            ['key' => 'identity', 'group' => 'brand', 'label' => 'Identity', 'fields' => [
                ['key' => 'ng_logo', 'label' => 'Logo', 'type' => 'image'],
            ]],
            ['key' => 'palette', 'group' => 'colors', 'label' => 'Palette', 'fields' => [
                ['key' => 'ng_accent', 'label' => 'Accent', 'type' => 'color', 'default' => '#e11d48'],
            ]],
            ['key' => 'grid', 'group' => 'layout', 'label' => 'Grid', 'fields' => [
                ['key' => 'ng_width', 'label' => 'Width', 'type' => 'select', 'default' => 'wide',
                    'options' => ['wide' => 'Wide', 'full' => 'Full']],
            ]],
            ['key' => 'profiles', 'group' => 'seo', 'label' => 'Profiles', 'fields' => [
                ['key' => 'ng_twitter', 'label' => 'Twitter', 'type' => 'text'],
            ]],
            ['key' => 'tweaks', 'group' => 'advanced', 'label' => 'Tweaks', 'fields' => [
                ['key' => 'ng_debug', 'label' => 'Debug', 'type' => 'boolean', 'default' => false],
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/' . $slug));
        }

        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function writeSchema(string $slug, array $sections): void
    {
        $dir = base_path('themes/' . $slug);
        File::ensureDirectoryExists($dir);
        File::put($dir . '/theme.options.json', json_encode(['options' => ['sections' => $sections]]));
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    private function optionManager(): ThemeOptionManager
    {
        return app('cms.theme_option');
    }

    // --- Normalization -------------------------------------------------------

    public function test_missing_group_normalizes_to_general(): void
    {
        $sections = $this->themes()->themeOptionsSchema('touxg_nogroup')['sections'];

        $this->assertCount(2, $sections);
        foreach ($sections as $section) {
            $this->assertSame('general', $section['group']);
        }
    }

    public function test_declared_groups_are_normalized_and_unknown_falls_back(): void
    {
        $byKey = [];
        foreach ($this->themes()->themeOptionsSchema('touxg_mixed')['sections'] as $section) {
            $byKey[$section['key']] = $section['group'];
        }

        $this->assertSame('brand', $byKey['sec_brand']);
        $this->assertSame('colors', $byKey['sec_colors']);  // "Colors" case-folded
        $this->assertSame('general', $byKey['sec_unknown']); // unknown → general
        $this->assertSame('general', $byKey['sec_missing']); // missing → general
    }

    // --- Grouping into tabs --------------------------------------------------

    public function test_group_less_schema_collapses_to_one_general_tab(): void
    {
        $tabs = $this->optionManager()->groupedSchema('touxg_nogroup');

        $this->assertCount(1, $tabs);
        $this->assertSame('general', $tabs[0]['group']);
        $this->assertCount(2, $tabs[0]['sections']);
    }

    public function test_mixed_schema_groups_in_canonical_order(): void
    {
        $tabs = $this->optionManager()->groupedSchema('touxg_mixed');
        $order = array_map(fn (array $t): string => $t['group'], $tabs);

        // Canonical order: general (unknown+missing) before brand before colors.
        $this->assertSame(['general', 'brand', 'colors'], $order);

        $generalKeys = array_map(fn (array $s): string => $s['key'], $tabs[0]['sections']);
        $this->assertSame(['sec_unknown', 'sec_missing'], $generalKeys);
    }

    public function test_ng_style_schema_is_compatible(): void
    {
        $tabs = $this->optionManager()->groupedSchema('touxg_ng');
        $order = array_map(fn (array $t): string => $t['group'], $tabs);

        $this->assertSame(['brand', 'colors', 'layout', 'seo', 'advanced'], $order);
        foreach ($tabs as $tab) {
            $this->assertCount(1, $tab['sections']);
            $this->assertNotSame('', $tab['label']);
            $this->assertStringStartsWith('heroicon-', $tab['icon']);
        }
    }

    // --- Backward compatibility / persistence --------------------------------

    public function test_values_and_defaults_are_unaffected_by_grouping(): void
    {
        // Schema default resolves through get() even with grouping present.
        $this->assertSame('one', $this->optionManager()->get('text1', null, 'touxg_nogroup'));

        // Stored keys/values round-trip under the same key as before.
        $this->optionManager()->set('text1', 'changed', 'touxg_nogroup');
        $this->assertSame('changed', $this->optionManager()->get('text1', null, 'touxg_nogroup'));
        $this->assertSame('changed', $this->optionManager()->all('touxg_nogroup')['text1']);

        // The stored setting key is still theme_options.{slug}.{key}.
        $this->assertTrue(settings()->has('theme_options.touxg_nogroup.text1'));
    }

    public function test_default_theme_schema_declares_canonical_groups(): void
    {
        // Read-only (no activation): the shipped Default theme now groups into
        // Brand, Colors, Layout and SEO & Social tabs.
        $order = array_map(
            fn (array $t): string => $t['group'],
            $this->optionManager()->groupedSchema('default'),
        );

        $this->assertSame(['brand', 'colors', 'layout', 'seo'], $order);
    }

    public function test_all_returns_every_declared_field_across_groups(): void
    {
        $all = $this->optionManager()->all('touxg_ng');

        $this->assertArrayHasKey('ng_logo', $all);
        $this->assertArrayHasKey('ng_accent', $all);
        $this->assertArrayHasKey('ng_width', $all);
        $this->assertArrayHasKey('ng_twitter', $all);
        $this->assertArrayHasKey('ng_debug', $all);
        $this->assertSame('#e11d48', $all['ng_accent']);
    }
}
