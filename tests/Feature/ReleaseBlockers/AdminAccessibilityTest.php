<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Pages\SitemapPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-RELEASE-BLOCKERS-R1 (H-Z1): Core-owned admin accessibility defects found by the real-Chrome audit
 * (axe + keyboard, doc 08). Structural pins on the shipped markup; the browser re-audit is the behavioural proof.
 */
final class AdminAccessibilityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    /** axe definition-list (WCAG 1.3.1): role="status" on a <dd> removes its definition semantics. */
    public function test_sitemap_status_keeps_definition_list_semantics(): void
    {
        $this->actingAs($this->userWith('sitemap.view'));
        $html = Livewire::test(SitemapPage::class)->html();

        $this->assertDoesNotMatchRegularExpression('/<dd\b[^>]*role="status"/', $html);
        $this->assertMatchesRegularExpression('/<dd\b[^>]*>\s*<span role="status">/', $html);
    }

    /** WCAG 2.4.7: the topbar language select had its focus ring removed by its inline style. */
    public function test_admin_language_select_has_a_visible_focus_indicator(): void
    {
        $this->assertGreaterThan(1, app('cms.language')->active()->count());
        $html = view('filament.admin.locale-switcher')->render();

        $this->assertStringContainsString('.tn-admin-locale-switcher select:focus-visible{outline:2px solid', $html);
        $this->assertStringContainsString('id="tn-admin-locale"', $html);
    }

    /** axe color-contrast (WCAG 1.4.3): the empty media picker hint was 4.41:1 at opacity .55. */
    public function test_media_picker_hint_text_meets_contrast(): void
    {
        $preview = new ReflectionMethod(MediaPicker::class, 'previewHtml');
        $html = (string) $preview->invoke(null, '');

        $this->assertStringNotContainsString('opacity:.55', $html);
        $this->assertStringContainsString('opacity:.7;margin-top:.25rem;', $html);
    }

    /** axe frame-title (WCAG 4.1.2): TinyMCE 8 leaves its editing iframe without a title. */
    public function test_rich_editor_names_its_editing_frame(): void
    {
        $blade = (string) file_get_contents(resource_path('views/filament/admin/components/rich-editor.blade.php'));

        $this->assertStringContainsString('data-cms-editor-label="{{ strip_tags((string) $getLabel()) }}"', $blade);
        $this->assertStringContainsString("editor.iframeElement.setAttribute('title', frameLabel)", $blade);
    }
}
