<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yR2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\WidgetArea;

/**
 * CORE-H-Z1-REMEDIATION-R1: regression guards for the Core-owned findings of
 * CORE-H-Z1-WCAG22-AA-EVIDENCE-CLOSURE-R1 that a server-rendered response can prove.
 *
 *  - H-C2 (WCAG 1.4.3): the text colours used on the Themes, Widgets and Install pages are
 *    computed here against the backgrounds they are painted on (light and dark).
 *  - H-C3 (WCAG 1.3.1 / 3.3.2 / 4.1.2): the Route Dictionary filters have associated labels.
 *  - H-C4: the Widgets page no longer ships the two expressions that threw on every load.
 *
 * Rendered ratios, pseudo-states and the accessibility tree are proven in real browsers
 * (docs/core-h-z1-remediation-r1/05-07).
 */
final class HZ1RemediationCoreFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static fn (string $c): float => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
        $linear = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private static function ratio(string $fg, string $bg): float
    {
        $a = self::luminance($fg);
        $b = self::luminance($bg);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private function assertNormalTextContrast(string $fg, string $bg, string $what): void
    {
        $this->assertGreaterThanOrEqual(4.5, round(self::ratio($fg, $bg), 2), sprintf('%s: %s on %s is %.2f:1', $what, $fg, $bg, self::ratio($fg, $bg)));
    }

    /** @return array{light: string, dark: string} the --tnw-faint value declared for each scheme */
    private function widgetFaintTokens(string $html): array
    {
        $this->assertSame(1, preg_match('/\.tn-widget-page\s*\{[^}]*--tnw-faint:\s*(#[0-9a-f]{6})/i', $html, $light));
        $this->assertSame(1, preg_match('/\.dark \.tn-widget-page\s*\{[^}]*--tnw-faint:\s*(#[0-9a-f]{6})/i', $html, $dark));

        return ['light' => strtolower($light[1]), 'dark' => strtolower($dark[1])];
    }

    public function test_h_c2_widgets_faint_text_token_meets_4_5_to_1_on_every_panel_background(): void
    {
        WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'hz1-sidebar', 'is_active' => true, 'sort_order' => 1]);
        $tokens = $this->widgetFaintTokens((string) $this->get('/admin/widgets')->assertOk()->getContent());

        // Light: panel #ffffff, area header #f9fafb. Dark: panel rgb(17 24 39), header = 4% white over it (#1b2130).
        $this->assertNormalTextContrast($tokens['light'], '#ffffff', 'widgets light panel');
        $this->assertNormalTextContrast($tokens['light'], '#f9fafb', 'widgets light area header');
        $this->assertNormalTextContrast($tokens['dark'], '#111827', 'widgets dark panel');
        $this->assertNormalTextContrast($tokens['dark'], '#1b2130', 'widgets dark area header');
    }

    public function test_h_c2_theme_placeholder_text_meets_4_5_to_1_in_both_schemes(): void
    {
        $html = (string) $this->get('/admin/themes')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/\.tn-theme-placeholder\s*\{[^}]*\bcolor:\s*(#[0-9a-f]{6})/i', $html, $light));
        $this->assertSame(1, preg_match('/\.dark \.tn-theme-placeholder\s*\{\s*color:\s*(#[0-9a-f]{6})/i', $html, $dark));
        $this->assertNormalTextContrast($light[1], '#f3f4f6', 'theme placeholder light');
        // Dark: 5% white over the dark card on the #09090b page (measured rgb(26 26 28)).
        $this->assertNormalTextContrast($dark[1], '#1a1a1c', 'theme placeholder dark');
    }

    public function test_h_c2_install_page_back_links_meet_4_5_to_1_in_both_schemes(): void
    {
        foreach (['/admin/plugins/install', '/admin/themes/install'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('color:#6b7280', $html, "{$url}: the 4.11:1 inline colour is gone");
            $this->assertMatchesRegularExpression('/<a\s[^>]*class="tn-install-back"/', $html, "{$url}: back link uses the Core class");
            $this->assertSame(1, preg_match('/\.tn-install-back\s*\{[^}]*\bcolor:\s*(#[0-9a-f]{6})/i', $html, $light));
            $this->assertSame(1, preg_match('/\.dark \.tn-install-back\s*\{\s*color:\s*(#[0-9a-f]{6})/i', $html, $dark));
            $this->assertNormalTextContrast($light[1], '#fafafa', "{$url} light");
            $this->assertNormalTextContrast($dark[1], '#09090b', "{$url} dark");
        }
    }

    public function test_h_c3_route_dictionary_filters_have_associated_visible_labels(): void
    {
        $html = (string) $this->get('/admin/localization/route-dictionary')->assertOk()->getContent();

        foreach (['search' => 'input', 'locale' => 'select', 'owner' => 'select'] as $key => $tag) {
            $id = "tn-route-dictionary-{$key}";
            $this->assertMatchesRegularExpression('/<'.$tag.'\s[^>]*\bid="'.$id.'"/', $html, "{$key} control has id {$id}");
            $this->assertSame(1, preg_match('/<label for="'.$id.'"[^>]*>(.*?)<\/label>/s', $html, $m), "{$key} control has exactly one <label for>");
            $this->assertNotSame('', trim(strip_tags($m[1])), "{$key} label is not empty");
            $this->assertStringNotContainsString('fi-sr-only', $m[0], "{$key} label is visible");
        }
    }

    public function test_h_c4_widgets_page_ships_no_uncompiled_directive_or_out_of_scope_loading_target(): void
    {
        WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'hz1-sidebar', 'is_active' => true, 'sort_order' => 1]);
        $html = (string) $this->get('/admin/widgets')->assertOk()->getContent();

        $this->assertSame(0, preg_match('/="[^"]*@js\(/', $html), 'no attribute contains an uncompiled @js( directive');
        // CORE-H-Z1-REMEDIATION-R2 (R2-5, owner decision 4): the export is no longer embedded; Copy JSON
        // calls a compiled Alpine handler that fetches it on activation (HZ1R2ResidualRemediationTest).
        $this->assertMatchesRegularExpression('/x-on:click="copy\(\)"/', $html, 'Copy JSON is wired to a compiled handler');
        $this->assertStringContainsString('$wire.clipboardExport()', $html, 'which writes the export JSON fetched from the server');
        $this->assertSame(0, preg_match('/wire:target="addWidget\(targetAreaId/', $html), 'no loading target evaluates targetAreaId outside $wire scope');
        $this->assertGreaterThan(0, preg_match_all('/wire:target="addWidget"/', $html), 'Add buttons keep a loading target');
        $this->assertGreaterThan(0, preg_match_all('/wire:click="addWidget\(targetAreaId, \'/', $html), 'the Add action itself is unchanged');
    }
}
