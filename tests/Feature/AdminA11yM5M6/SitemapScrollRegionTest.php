<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM5M6;

use App\Filament\Admin\Pages\SitemapPage;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-40-H-Z1-M6 (WCAG 2.1.1, Level A). On /admin/xml-sitemap the Provider status and
 * Diagnostic checks tables are wider than a 390 px viewport and scroll horizontally inside
 * their container, which held nothing focusable: a keyboard user could not reach the hidden
 * columns.
 *
 * Contract (server side; the overflow itself is measured in the browser, see the closure
 * docs): each horizontally scrolling container is a Core scroll region whose focusability,
 * role and accessible name are bound to "this container overflows". Nothing is focusable
 * statically, so a table that fits adds no Tab stop. The name points at the table's own
 * caption. Scrolling is the browser's own; the state only measures.
 */
final class SitemapScrollRegionTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPost('Scroll Post');
        $this->actingAs($this->userWith('sitemap.view', 'sitemap.diagnostics'));
    }

    private function xpath(): DOMXPath
    {
        $html = $this->get('/admin/xml-sitemap')->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    /** @return list<DOMElement> */
    private function scrollers(DOMXPath $x): array
    {
        return iterator_to_array($x->query("//main//div[contains(concat(' ', normalize-space(@class), ' '), ' overflow-x-auto ')][.//table]"));
    }

    public function test_both_tables_scroll_inside_a_bound_scroll_region(): void
    {
        app('cms.sitemap_diagnostics')->run();
        $x = $this->xpath();

        $scrollers = $this->scrollers($x);
        $this->assertCount(2, $scrollers, 'Provider status + Diagnostic checks');

        foreach ($scrollers as $div) {
            $this->assertStringContainsString('tn-scroll-region', $div->getAttribute('class'));
            $this->assertStringContainsString('scrollWidth > this.$el.clientWidth', $div->getAttribute('x-data'), 'state = the container overflows');
            $this->assertSame('scrolls ? 0 : null', $div->getAttribute('x-bind:tabindex'));
            $this->assertSame("scrolls ? 'region' : null", $div->getAttribute('x-bind:role'));
            $this->assertTrue($div->hasAttribute('wire:ignore.self'), 'Livewire re-renders keep the bound attributes');

            preg_match("/'([a-z-]+)'/", $div->getAttribute('x-bind:aria-labelledby'), $m);
            $caption = $x->query('.//table/caption', $div)->item(0);
            $this->assertNotNull($caption);
            $this->assertSame($m[1] ?? null, $caption->getAttribute('id'), 'the region is named by its own table caption');
            $this->assertNotSame('', trim($caption->textContent));
        }
    }

    public function test_nothing_is_focusable_statically_so_a_fitting_table_adds_no_tab_stop(): void
    {
        app('cms.sitemap_diagnostics')->run();

        foreach ($this->scrollers($this->xpath()) as $div) {
            $this->assertFalse($div->hasAttribute('tabindex'));
            $this->assertFalse($div->hasAttribute('role'));
            $this->assertFalse($div->hasAttribute('aria-labelledby'));
            $this->assertStringNotContainsString('scrollLeft', $div->getAttribute('x-data'), 'the script never scrolls');
        }
    }

    public function test_caption_ids_are_unique_on_the_page(): void
    {
        app('cms.sitemap_diagnostics')->run();
        $ids = array_map(fn (DOMElement $c) => $c->getAttribute('id'), iterator_to_array($this->xpath()->query('//table/caption[@id]')));

        $this->assertSame(['sm-providers-caption', 'sm-diagnostics-caption'], $ids);
    }

    public function test_the_region_survives_a_livewire_rerender(): void
    {
        Livewire::test(SitemapPage::class)
            ->callAction('runDiagnostics')
            ->assertSeeHtml("x-bind:aria-labelledby=\"scrolls ? 'sm-diagnostics-caption' : null\"")
            ->assertSeeHtml("x-bind:aria-labelledby=\"scrolls ? 'sm-providers-caption' : null\"");
    }

    public function test_focus_is_drawn_for_the_region_in_light_and_dark(): void
    {
        $css = view('filament.admin.a11y-overrides')->render();

        $this->assertStringContainsString('.tn-scroll-region:focus-visible { outline: 2px solid var(--primary-700);', $css);
        $this->assertStringContainsString('.dark .tn-scroll-region:focus-visible { outline-color: var(--primary-400); }', $css);
    }
}
