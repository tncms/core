<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Widget;
use TheNguyen\CMS\Models\WidgetArea;

/**
 * CORE-40-H-Z1-M4 — an inactive widget row on the populated Widgets page was drawn at 70 % opacity, which
 * pulled its description and its "Disabled" badge below 4.5:1 (WCAG 1.4.3). The state is carried by the badge
 * text and the dashed border, so the text must keep full opacity; only the decorative (aria-hidden) type icon
 * may be dimmed.
 */
final class InactiveWidgetContrastTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create(['admin_locale' => 'en']);
        config(['cms.super_admin_emails' => [$admin->email]]);
        $area = WidgetArea::query()->create(['name' => 'Sidebar', 'slug' => 'm4-sidebar', 'is_active' => true, 'sort_order' => 1]);
        Widget::query()->create(['area_id' => $area->id, 'widget_type' => 'text', 'sort_order' => 1, 'is_active' => true, 'settings' => []]);
        Widget::query()->create(['area_id' => $area->id, 'widget_type' => 'text', 'sort_order' => 2, 'is_active' => false, 'settings' => []]);

        return (string) $this->actingAs($admin)->get('/admin/widgets')->assertOk()->getContent();
    }

    public function test_inactive_widget_row_text_is_not_dimmed(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('is-inactive', $html, 'fixture: an inactive widget row is rendered');
        $this->assertSame(1, preg_match('/\.tn-widget-instance\.is-inactive\s*\{([^}]*)\}/', $html, $rule), 'the inactive row rule exists');
        $this->assertStringNotContainsString('opacity', $rule[1], 'the whole row (title, description, badge text) must keep full opacity');
    }

    public function test_only_the_decorative_icon_of_an_inactive_row_may_be_dimmed(): void
    {
        $html = $this->page();

        preg_match_all('/([^{}]*\.is-inactive[^{}]*)\{([^}]*opacity[^}]*)\}/', $html, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selector]) {
            $this->assertMatchesRegularExpression('/\.tn-widget-instance-icon\s*$/', trim($selector), "only the aria-hidden type icon may be dimmed: {$selector}");
        }
        $this->assertMatchesRegularExpression('/<span class="tn-widget-instance-icon" aria-hidden="true">/', $html, 'the dimmed icon is decorative');
    }

    private static function luminance(string $hex): float
    {
        $linear = array_map(static fn (string $c): float => ($v = hexdec($c) / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    public function test_the_disabled_badge_text_meets_4_5_to_1_on_its_pill(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match('/\.tn-widget-badge\s*\{[^}]*color:\s*var\(--tnw-badge-text\)/', $html), 'the badge uses its own text token');
        $this->assertSame(1, preg_match('/\.tn-widget-page\s*\{[^}]*--tnw-badge-text:\s*(#[0-9a-f]{6})[^}]*--tnw-icon-bg:\s*(#[0-9a-f]{6})/i', $html, $light));
        $this->assertSame(1, preg_match('/\.dark \.tn-widget-page\s*\{[^}]*--tnw-badge-text:\s*(#[0-9a-f]{6})/i', $html, $dark));

        [$fg, $bg] = [self::luminance($light[1]), self::luminance($light[2])];
        $this->assertGreaterThanOrEqual(4.5, (max($fg, $bg) + 0.05) / (min($fg, $bg) + 0.05), 'light badge text on the light pill');
        // Dark pill: 8 % white over the dark panel rgb(17 24 39) is about #252b3a.
        [$fg, $bg] = [self::luminance($dark[1]), self::luminance('#252b3a')];
        $this->assertGreaterThanOrEqual(4.5, (max($fg, $bg) + 0.05) / (min($fg, $bg) + 0.05), 'dark badge text on the dark pill');
    }

    public function test_the_inactive_state_is_conveyed_as_text(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('/<span class="tn-widget-badge">\s*Disabled\s*<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span class="tn-widget-badge is-active">\s*Active\s*<\/span>/', $html);
    }
}
