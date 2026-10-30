<?php

declare(strict_types=1);

namespace Tests\Feature\ContentExtension;

use App\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\OwnerState;
use TheNguyen\CMS\Contracts\AdjacentPostResolver;
use TheNguyen\CMS\Contracts\RelatedPostResolver;
use TheNguyen\CMS\Models\MenuItem;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Widgets\RecentPostsWidget;

/**
 * CORE-CONTENT-EXT-R1 M4 — every Core discovery surface honours the same
 * persisted visibility as the single page (owner decision D-5), with one
 * correlated filter and batched markers (no per-item provider calls).
 *
 * Fixture (all published, all in category "news", oldest → newest):
 *   Core Alpha        Core content
 *   Owned Visible     cx-alpha, available
 *   Owned Hidden      cx-alpha, unavailable
 *   Owned Paused      cx-alpha, temporarily_unavailable
 *   Fallback Render   cx-beta (deactivated), fallback core_render
 *   Fallback Hidden   cx-beta (deactivated), fallback hide_404
 */
final class ContentDiscoveryTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    private const VISIBLE = ['Core Alpha', 'Owned Visible', 'Fallback Render'];

    private const HIDDEN = ['Owned Hidden', 'Owned Paused', 'Fallback Hidden'];

    private Term $news;

    /** @var array<string, \TheNguyen\CMS\Models\Content> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCxLanguages();
        $this->useCxPlugins('cx-alpha', 'cx-beta');

        $this->news = app(TaxonomyManager::class)->createTerm('category', ['locale' => 'en', 'name' => 'News', 'slug' => 'news']);
        $terms = ['term_ids' => [$this->news->id]];
        $day = 1;
        $at = fn () => ['published_at' => now()->subDays(10 - $day++)];

        $this->posts['Core Alpha'] = $this->corePost('Core Alpha', $terms + $at());
        $this->posts['Owned Visible'] = $this->ownedPost('cx-alpha', 'Owned Visible', $terms + $at());
        $this->posts['Owned Hidden'] = $this->ownedPost('cx-alpha', 'Owned Hidden', $terms + $at());
        $this->posts['Owned Paused'] = $this->ownedPost('cx-alpha', 'Owned Paused', $terms + $at());
        $this->posts['Fallback Render'] = $this->ownedPost('cx-beta', 'Fallback Render', $terms + $at());
        $this->posts['Fallback Hidden'] = $this->ownedPost('cx-beta', 'Fallback Hidden', $terms + $at());

        $alpha = $this->cxPlugin('cx-alpha');
        $alpha->availability($this->posts['Owned Hidden']->id, ContentAvailability::Unavailable);
        $alpha->availability($this->posts['Owned Paused']->id, ContentAvailability::TemporarilyUnavailable);
        $this->cxPlugin('cx-beta')->fallback($this->posts['Fallback Render']->id, ContentFallbackPolicy::CoreRender);

        app('cms.extension')->deactivatePlugin('cx-beta');
        app('cms.extension')->flushRegistry();
    }

    private function assertOnlyVisible(string $haystack, string $surface): void
    {
        foreach (self::VISIBLE as $title) {
            $this->assertStringContainsString($title, $haystack, "{$surface} lists visible '{$title}'");
        }

        foreach (self::HIDDEN as $title) {
            $this->assertStringNotContainsString($title, $haystack, "{$surface} hides '{$title}'");
        }
    }

    public function test_single_pages_match_discovery(): void
    {
        foreach (self::VISIBLE as $title) {
            $this->get(parse_url(content_url($this->posts[$title], 'en'), PHP_URL_PATH))->assertOk();
        }

        $this->get(parse_url(content_url($this->posts['Owned Hidden'], 'en'), PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url(content_url($this->posts['Owned Paused'], 'en'), PHP_URL_PATH))->assertStatus(503);
        $this->get(parse_url(content_url($this->posts['Fallback Hidden'], 'en'), PHP_URL_PATH))->assertNotFound();
    }

    public function test_category_archive(): void
    {
        $html = $this->get(parse_url(term_url($this->news, 'en'), PHP_URL_PATH))->assertOk()->getContent();

        $this->assertOnlyVisible($html, 'archive');
    }

    public function test_latest_posts_home(): void
    {
        app('cms.settings')->set('reading.homepage_display', 'latest_posts');

        $this->assertOnlyVisible($this->get('/')->assertOk()->getContent(), 'home');
    }

    public function test_recent_posts_widget_and_section_data(): void
    {
        $this->assertOnlyVisible(app(RecentPostsWidget::class)->render(['limit' => 20], 'en'), 'widget');
        $this->assertOnlyVisible((string) json_encode(app('cms.section_data')->resolvePosts(['limit' => 20], 'en')), 'sections');
    }

    public function test_search(): void
    {
        $results = app(\App\Search\Contracts\SearchManagerInterface::class)
            ->search(SearchQuery::create('body', 'en', []))
            ->results;

        $this->assertOnlyVisible((string) json_encode(array_map(fn ($r) => $r->title, $results)), 'search');
    }

    public function test_sitemap(): void
    {
        $locs = implode("\n", array_column(app('cms.seo')->sitemap(), 'loc'));

        foreach (self::VISIBLE as $title) {
            $this->assertStringContainsString(content_url($this->posts[$title], 'en'), $locs, "sitemap lists '{$title}'");
        }

        foreach (self::HIDDEN as $title) {
            $this->assertStringNotContainsString(content_url($this->posts[$title], 'en'), $locs, "sitemap hides '{$title}'");
        }
    }

    public function test_related_and_adjacent_cards_skip_hidden_items_and_carry_markers(): void
    {
        $cards = app(RelatedPostResolver::class)->forPost($this->posts['Core Alpha'], 'en', 12);
        $titles = $cards->map(fn ($c) => $c->title)->all();
        $this->assertOnlyVisible(implode('|', $titles).'|Core Alpha', 'related');

        $byTitle = $cards->keyBy('title');
        $this->assertSame(['owner' => 'cx-alpha', 'state' => 'active'], $byTitle['Owned Visible']->toArray()['ownership']);
        $this->assertSame(OwnerState::Inactive, $byTitle['Fallback Render']->ownership->state);

        // Adjacent: the neighbour of Owned Visible (newer side) skips Hidden/Paused.
        $next = app(AdjacentPostResolver::class)->next($this->posts['Owned Visible'], 'en');
        $this->assertSame('Fallback Render', $next->title);
        $this->assertSame(['owner' => 'cx-beta', 'state' => 'inactive'], $next->toArray()['ownership']);

        $previous = app(AdjacentPostResolver::class)->previous($this->posts['Owned Visible'], 'en');
        $this->assertSame('Core Alpha', $previous->title);
        $this->assertArrayNotHasKey('ownership', $previous->toArray(), 'Core content arrays are unchanged');
    }

    public function test_menu_links_and_theme_page_references(): void
    {
        $item = new MenuItem(['type' => 'post', 'reference_type' => 'content', 'reference_id' => $this->posts['Owned Hidden']->id]);
        $this->assertSame('#', $item->resolvedUrl('en'));

        $item = new MenuItem(['type' => 'post', 'reference_type' => 'content', 'reference_id' => $this->posts['Owned Visible']->id]);
        $this->assertSame(content_url($this->posts['Owned Visible'], 'en'), $item->resolvedUrl('en'));

        $page = $this->cxPlugin('cx-alpha')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Owned Page', 'slug' => 'owned-page', 'content' => 'x']);
        $resolver = app(\TheNguyen\CMS\Services\ThemePageReferenceResolver::class);
        $this->assertNotNull($resolver->urlForId($page->id, 'en'));
        $this->cxPlugin('cx-alpha')->availability($page->id, ContentAvailability::Gone);
        $this->assertNull($resolver->urlForId($page->id, 'en'));
    }

    public function test_reactivating_the_owner_restores_its_items_without_cache_flush(): void
    {
        $this->assertStringNotContainsString('Fallback Hidden', app(RecentPostsWidget::class)->render(['limit' => 20], 'en'));

        app('cms.extension')->activatePlugin('cx-beta');
        app('cms.extension')->flushRegistry();

        $this->assertStringContainsString('Fallback Hidden', app(RecentPostsWidget::class)->render(['limit' => 20], 'en'));
        $this->get(parse_url(content_url($this->posts['Fallback Hidden'], 'en'), PHP_URL_PATH))->assertOk();
    }

    public function test_discovery_filter_is_one_correlated_subquery_with_no_per_item_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(RecentPostsWidget::class)->render(['limit' => 20], 'en');
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        // CORE-UNIFIED-OWNERSHIP-R1: managed_by on the row + one policy subquery.
        $ownershipQueries = $log->filter(fn ($q) => str_contains($q['query'], 'cms_content_owner_policies'));
        $this->assertCount(1, $ownershipQueries);
        $this->assertStringContainsString('managed_by', $ownershipQueries->first()['query']);
        $this->assertStringContainsString('exists', strtolower($ownershipQueries->first()['query']));
    }

    public function test_language_switcher_alternates_follow_the_rendered_resource(): void
    {
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $post = $this->cxPlugin('cx-alpha')->update($this->posts['Owned Visible'], [
            'type' => 'post', 'locale' => 'vi', 'title' => 'Hien thi', 'slug' => 'hien-thi', 'content' => 'vi',
        ]);

        $html = $this->get(parse_url(content_url($post->fresh('translations'), 'en'), PHP_URL_PATH))->assertOk()->getContent();
        $this->assertStringContainsString('hreflang="vi"', $html);

        // Availability is per content item, so every locale agrees.
        $this->cxPlugin('cx-alpha')->availability($post->id, ContentAvailability::Unavailable);
        $this->get(parse_url(content_url($post->fresh('translations'), 'vi'), PHP_URL_PATH))->assertNotFound();
    }
}
