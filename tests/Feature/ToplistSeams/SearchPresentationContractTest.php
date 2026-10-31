<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use App\Search\Presentation\SearchPageViewModel;
use App\Search\Presentation\SearchResultView;
use App\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentOwnershipMarker;
use TheNguyen\CMS\Content\Extension\OwnerState;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate B (H-1): a search hit keeps its content
 * identity and the public ownership marker, resolved in one batched lookup
 * per page, so a theme can treat owner-managed results without URL guessing,
 * title matching or per-result queries.
 */
final class SearchPresentationContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
        $this->sxPlugin('sx-owner');
    }

    private function page(string $q, string $locale = 'vi'): SearchPageViewModel
    {
        $prefix = $locale === 'vi' ? '' : '/'.$locale;
        $response = $this->get($prefix.'/search?q='.urlencode($q))->assertOk();

        return $response->viewData('page');
    }

    /** @return array<string, SearchResultView> title => view */
    private function byTitle(SearchPageViewModel $page): array
    {
        $out = [];
        foreach ($page->results as $view) {
            $out[$view->title] = $view;
        }

        return $out;
    }

    public function test_ordinary_core_content_carries_its_id_and_no_ownership(): void
    {
        $core = $this->sxCorePost('Seamword Core');

        $view = $this->byTitle($this->page('Seamword'))['Seamword Core'];

        $this->assertSame((int) $core->id, $view->contentId);
        $this->assertNull($view->ownership);
        $this->assertSame(url($this->sxPath($core)), url((string) $view->url));
    }

    public function test_plugin_owned_content_carries_id_and_active_marker_without_changing_core_metadata(): void
    {
        $owned = $this->sxOwnedPost('sx-owner', 'Seamword Owned');

        $view = $this->byTitle($this->page('Seamword'))['Seamword Owned'];

        $this->assertSame((int) $owned->id, $view->contentId);
        $this->assertInstanceOf(ContentOwnershipMarker::class, $view->ownership);
        $this->assertSame(['owner' => 'sx-owner', 'state' => 'active'], $view->ownership->toArray());
    }

    public function test_inactive_and_missing_owners_are_reported_by_state(): void
    {
        $inactive = $this->sxOwnedPost('sx-owner', 'Seamword Inactive', fallback: ContentFallbackPolicy::CoreRender);
        $missing = $this->sxCorePost('Seamword Missing');
        $this->sxMarkOwned($missing, 'sx-gone', ContentFallbackPolicy::CoreRender);

        app('cms.extension')->deactivatePlugin('sx-owner');
        app('cms.extension')->flushRegistry();

        $views = $this->byTitle($this->page('Seamword'));

        $this->assertSame(OwnerState::Inactive, $views['Seamword Inactive']->ownership?->state);
        $this->assertSame((int) $inactive->id, $views['Seamword Inactive']->contentId);
        $this->assertSame(OwnerState::Missing, $views['Seamword Missing']->ownership?->state);
        $this->assertSame('sx-gone', $views['Seamword Missing']->ownership?->owner);
    }

    public function test_multilingual_results_carry_the_same_identity_per_locale(): void
    {
        $owned = $this->sxOwnedPost('sx-owner', 'Seamword Bilingual');

        $vi = $this->byTitle($this->page('Seamword', 'vi'))['Seamword Bilingual'];
        $en = $this->byTitle($this->page('Seamword', 'en'))['Seamword Bilingual EN'];

        $this->assertSame((int) $owned->id, $vi->contentId);
        $this->assertSame((int) $owned->id, $en->contentId);
        $this->assertSame('active', $vi->ownership?->state->value);
        $this->assertSame('active', $en->ownership?->state->value);
        $this->assertStringContainsString('/en/', (string) $en->url);
    }

    public function test_markers_are_one_batched_query_whatever_the_page_size(): void
    {
        foreach (range(1, 6) as $n) {
            $this->sxOwnedPost('sx-owner', "Seamword Batch {$n}");
            $this->sxCorePost("Seamword Plain {$n}");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $this->page('Seamword');
        $markerQueries = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with($q['query'], 'select "id", "managed_by" from "cms_contents"'));
        DB::disableQueryLog();

        $this->assertCount(12, $page->results);
        $this->assertCount(1, $markerQueries, 'one batched ownership lookup for the whole page');
        $this->assertCount(6, array_filter($page->results, static fn (SearchResultView $v): bool => $v->ownership !== null));
    }

    public function test_non_content_results_and_legacy_construction_stay_compatible(): void
    {
        $legacy = new SearchResultView('Title', 'Excerpt', '/x', 'Label', 1.0);
        $this->assertNull($legacy->contentId);
        $this->assertNull($legacy->ownership);

        $product = SearchResultView::fromResult(new SearchResult('product', 7, 'P', '', '/p', 1.0, 'vi'), 'Product');
        $this->assertNull($product->contentId, 'only Core content results carry a content id');
        $this->assertNull($product->ownership);

        foreach (['12' => 12, '1e2' => null, '1.5' => null, 'abc' => null] as $id => $expected) {
            $this->assertSame($expected, SearchResultView::contentIdOf(new SearchResult('content', (string) $id, 'T', '', '/t', 1.0, 'vi')), (string) $id);
        }
        $this->assertSame(7, SearchResultView::contentIdOf(new SearchResult('content', 7, 'T', '', '/t', 1.0, 'vi')));
    }
}
