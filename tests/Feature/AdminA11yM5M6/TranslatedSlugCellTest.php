<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM5M6;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;

/**
 * CORE-40-H-Z1-M5 (WCAG 2.4.4, 4.1.2, Level A). The Posts / Pages / Categories / Tags
 * lists show the slug in the content editing language. A row without a translation in
 * that language has no slug there, and the cell used to render as a focusable empty
 * row link (<a class="fi-ta-col"></a>), a link without an accessible name.
 *
 * Contract: an untranslated row's slug cell is not interactive and shows the visible
 * text "Not translated". A translated row keeps its slug as the row link. The row stays
 * reachable through its (named) title link and its Edit action. No fallback slug from
 * another language is shown, and the Core locale fallback policy is unchanged.
 */
final class TranslatedSlugCellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class); // vi default + en
        app('cms.taxonomy')->ensureCoreTaxonomies();
    }

    private function admin(string $uiLocale): User
    {
        $admin = User::factory()->create(['admin_locale' => $uiLocale]);
        config(['cms.super_admin_emails' => [$admin->email]]);

        return $admin;
    }

    /** vi-only item, or vi + en when $translated. Returns the en slug (or the vi one). */
    private function item(string $kind, string $marker, bool $translated): string
    {
        if (in_array($kind, ['post', 'page'], true)) {
            $c = app('cms.content')->create([
                'type' => $kind, 'status' => 'published', 'locale' => 'vi',
                'title' => "VI $marker", 'slug' => "vi-$marker", 'content' => '<p>VI</p>',
            ], ContentFixtures::context());
            if ($translated) {
                app('cms.content')->update($c, ['locale' => 'en', 'title' => "EN $marker", 'slug' => "en-$marker", 'content' => '<p>EN</p>'], null, ContentFixtures::context());
            }
        } else {
            $t = app('cms.taxonomy')->createTerm($kind, ['locale' => 'vi', 'name' => "VI $marker", 'slug' => "vi-$marker"]);
            if ($translated) {
                app('cms.taxonomy')->updateTerm($t, ['locale' => 'en', 'name' => "EN $marker", 'slug' => "en-$marker"]);
            }
        }

        return $translated ? "en-$marker" : "vi-$marker";
    }

    private function xpath(string $url, string $uiLocale = 'en'): DOMXPath
    {
        $html = $this->actingAs($this->admin($uiLocale))->get($url)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    /** @return list<DOMElement> */
    private function slugCells(DOMXPath $x): array
    {
        return iterator_to_array($x->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' fi-ta-cell-slug ')]"));
    }

    private function accName(DOMElement $el): string
    {
        return trim(preg_replace('/\s+/u', ' ', $el->hasAttribute('aria-label') ? $el->getAttribute('aria-label') : $el->textContent));
    }

    /** Interactive descendants: links with href, buttons, anything with a non-negative tabindex. */
    private function interactive(DOMXPath $x, DOMElement $ctx): array
    {
        return iterator_to_array($x->query('.//a[@href] | .//button | .//*[@tabindex and not(@tabindex="-1")]', $ctx));
    }

    public static function lists(): array
    {
        return [
            'posts' => ['post', '/admin/posts'],
            'pages' => ['page', '/admin/pages'],
            'categories' => ['category', '/admin/categories'],
            'tags' => ['tag', '/admin/tags'],
        ];
    }

    #[DataProvider('lists')]
    public function test_untranslated_row_slug_cell_is_plain_text_not_an_unnamed_link(string $kind, string $url): void
    {
        $this->item($kind, 'only-vi', false);

        $x = $this->xpath("$url?locale=en");
        $cells = $this->slugCells($x);
        $this->assertCount(1, $cells);

        $this->assertSame([], $this->interactive($x, $cells[0]), 'an untranslated slug cell must not be focusable or interactive');
        $this->assertSame('Not translated', $this->accName($cells[0]), 'the state is visible text');
        $this->assertStringNotContainsString('vi-only-vi', $cells[0]->textContent, 'no slug from another language is substituted');
    }

    #[DataProvider('lists')]
    public function test_translated_row_keeps_its_slug_row_link(string $kind, string $url): void
    {
        $slug = $this->item($kind, 'both', true);

        $x = $this->xpath("$url?locale=en");
        $cells = $this->slugCells($x);
        $this->assertCount(1, $cells);

        $links = $this->interactive($x, $cells[0]);
        $this->assertCount(1, $links, 'the translated slug stays the row link');
        $this->assertSame('a', $links[0]->nodeName);
        $this->assertSame($slug, $this->accName($links[0]));
        $this->assertMatchesRegularExpression('#/admin/[a-z]+/\d+/edit$#', $links[0]->getAttribute('href'));
    }

    #[DataProvider('lists')]
    public function test_no_row_link_in_the_list_is_unnamed_in_either_editing_language(string $kind, string $url): void
    {
        $this->item($kind, 'm1', true);
        $this->item($kind, 'm2', false);

        foreach (['en', 'vi'] as $editing) {
            $x = $this->xpath("$url?locale=$editing");
            foreach ($x->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' fi-ta-cell ')]//a[@href]") as $a) {
                /** @var DOMElement $a */
                $hasImgName = $x->query('.//img[@alt!=""]', $a)->length > 0;
                $this->assertTrue($this->accName($a) !== '' || $hasImgName, "unnamed row link in $url (editing $editing): ".$a->ownerDocument->saveHTML($a));
            }
        }
    }

    #[DataProvider('lists')]
    public function test_untranslated_row_stays_reachable_through_its_title_and_edit_action(string $kind, string $url): void
    {
        $this->item($kind, 'only-vi', false);

        $x = $this->xpath("$url?locale=en");
        $titleColumn = in_array($kind, ['post', 'page'], true) ? 'title' : 'name';
        $title = $x->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' fi-ta-cell-$titleColumn ')]//a[@href]");
        $this->assertSame(1, $title->length, 'the title cell is still the named row link');
        $this->assertNotSame('', $this->accName($title->item(0)));
        $this->assertMatchesRegularExpression('#/admin/[a-z]+/\d+/edit$#', $title->item(0)->getAttribute('href'));
    }

    #[DataProvider('lists')]
    public function test_vietnamese_interface_localizes_the_state_and_vi_editing_shows_the_slug(string $kind, string $url): void
    {
        $this->item($kind, 'only-vi', false);

        $cell = $this->slugCells($this->xpath("$url?locale=en", 'vi'))[0];
        $this->assertSame('Chưa dịch', $this->accName($cell));

        $cell = $this->slugCells($this->xpath("$url?locale=vi", 'vi'))[0];
        $links = $this->interactive(new DOMXPath($cell->ownerDocument), $cell);
        $this->assertCount(1, $links, 'in its own language the row is translated: its slug is the row link');
        $this->assertSame('vi-only-vi', $this->accName($links[0]));
    }

    #[DataProvider('lists')]
    public function test_the_untranslated_state_adds_no_query(string $kind, string $url): void
    {
        // The same rows listed in a language where all of them are translated (vi) and in one
        // where none of them is (en): the untranslated state must cost no extra query. (The
        // taxonomy lists have a pre-existing per-row taxonomy-id lookup, PERF-TAX-1; it is the
        // same in both runs and is not part of M5.)
        for ($i = 0; $i < 4; $i++) {
            $this->item($kind, "q-$i", false);
        }
        $admin = $this->admin('en');
        $count = function (string $editing) use ($admin, $url): int {
            $this->actingAs($admin)->get("$url?locale=$editing")->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->get("$url?locale=$editing")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertLessThanOrEqual($count('vi'), $count('en'), 'untranslated rows must not add queries');
    }
}
