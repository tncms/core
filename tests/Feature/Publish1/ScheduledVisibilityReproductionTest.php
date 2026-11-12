<?php

declare(strict_types=1);

namespace Tests\Feature\Publish1;

use App\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Contracts\AdjacentPostResolver;
use TheNguyen\CMS\Contracts\RelatedPostResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\MenuItem;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemePageReferenceResolver;
use TheNguyen\CMS\Widgets\RecentPostsWidget;

/**
 * CORE-PUBLISH-1 P3: a published record whose publication time is in the
 * future (a scheduled post) must not be publicly visible before that time, on
 * any Core public surface. It becomes visible at the boundary without any
 * write, cron run or cache flush.
 *
 * Fixture (all status=published, category "news", tag "lead"):
 *   Live Past       published_at = now - 1 day
 *   Live Legacy     published_at = NULL (pre-existing rows; stays visible)
 *   Future Scoop    published_at = now + 1 hour (scheduled)
 */
final class ScheduledVisibilityReproductionTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    private const VISIBLE = ['Live Past', 'Live Legacy'];

    private const THEME = 'publish1_fixture';

    private Term $news;

    private Term $lead;

    /** @var array<string, Content> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->setUpCxLanguages();

        $taxonomy = app(TaxonomyManager::class);
        $this->news = $taxonomy->createTerm('category', ['locale' => 'en', 'name' => 'News', 'slug' => 'news']);
        $this->lead = $taxonomy->createTerm('tag', ['locale' => 'en', 'name' => 'Lead', 'slug' => 'lead']);
        $terms = ['term_ids' => [$this->news->id, $this->lead->id]];

        $this->posts['Live Past'] = $this->corePost('Live Past', $terms + ['published_at' => now()->subDay()]);
        $this->posts['Live Legacy'] = $this->corePost('Live Legacy', $terms + ['published_at' => now()->subDays(2)]);
        Content::query()->whereKey($this->posts['Live Legacy']->id)->toBase()->update(['published_at' => null]);
        $this->posts['Future Scoop'] = $this->corePost('Future Scoop', $terms + ['published_at' => now()->addHour()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function page(string $title, \DateTimeInterface $at): Content
    {
        return app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en', 'published_at' => $at,
            'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title), 'content' => '<p>'.$title.' body</p>',
        ], \Tests\Support\ContentFixtures::context());
    }

    private function path(string $title): string
    {
        return (string) parse_url(content_url($this->posts[$title], 'en'), PHP_URL_PATH);
    }

    private function assertScheduledHidden(string $haystack, string $surface): void
    {
        foreach (self::VISIBLE as $title) {
            $this->assertStringContainsString($title, $haystack, "{$surface} lists '{$title}'");
        }

        $this->assertStringNotContainsString('Future Scoop', $haystack, "{$surface} hides the scheduled post");
    }

    public function test_direct_url_is_not_found_before_the_publication_time(): void
    {
        $this->get($this->path('Live Past'))->assertOk();
        $this->get($this->path('Live Legacy'))->assertOk();
        $this->get($this->path('Future Scoop'))->assertNotFound();
    }

    public function test_category_and_tag_archives(): void
    {
        $this->assertScheduledHidden($this->get((string) parse_url(term_url($this->news, 'en'), PHP_URL_PATH))->assertOk()->getContent(), 'category');
        $this->assertScheduledHidden($this->get((string) parse_url(term_url($this->lead, 'en'), PHP_URL_PATH))->assertOk()->getContent(), 'tag');
    }

    public function test_latest_posts_homepage(): void
    {
        app('cms.settings')->set('reading.homepage_display', 'latest_posts');

        $this->assertScheduledHidden($this->get('/')->assertOk()->getContent(), 'home');
    }

    public function test_widget_sections_search_and_sitemap(): void
    {
        $this->assertScheduledHidden(app(RecentPostsWidget::class)->render(['limit' => 20], 'en'), 'widget');
        $this->assertScheduledHidden((string) json_encode(app('cms.section_data')->resolvePosts(['limit' => 20], 'en')), 'sections');

        $results = app(\App\Search\Contracts\SearchManagerInterface::class)->search(SearchQuery::create('body', 'en', []))->results;
        $this->assertScheduledHidden((string) json_encode(array_map(fn ($r) => $r->title, $results)), 'search');

        $locs = implode("\n", array_column(app('cms.seo')->sitemap(), 'loc'));
        $this->assertStringContainsString(content_url($this->posts['Live Past'], 'en'), $locs);
        $this->assertStringNotContainsString(content_url($this->posts['Future Scoop'], 'en'), $locs, 'sitemap hides the scheduled post');
    }

    public function test_related_and_adjacent_cards(): void
    {
        $related = app(RelatedPostResolver::class)->forPost($this->posts['Live Past'], 'en', 12)->map(fn ($c) => $c->title)->all();
        $this->assertNotContains('Future Scoop', $related, 'related hides the scheduled post');

        $next = app(AdjacentPostResolver::class)->next($this->posts['Live Past'], 'en');
        $this->assertNotSame('Future Scoop', $next?->title, 'adjacent "next" never points at the scheduled post');
    }

    public function test_menu_links_and_theme_page_references(): void
    {
        $item = new MenuItem(['type' => 'post', 'reference_type' => 'content', 'reference_id' => $this->posts['Future Scoop']->id]);
        $this->assertSame('#', $item->resolvedUrl('en'), 'menu link to a scheduled post is inert');

        $page = $this->page('Future Page', now()->addDay());
        $this->assertNull(app(ThemePageReferenceResolver::class)->urlForId($page->id, 'en'));
    }

    public function test_static_homepage_scheduled_page_is_not_served(): void
    {
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE-{{ $content->id ?? "" }}-END');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'P1 fixture', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
        app('cms.settings')->set('reading.homepage_display', 'static_page');

        // Control: a live static homepage is served.
        $live = $this->page('Live Home', now()->subDay());
        app('cms.settings')->set('reading.homepage_page_id', $live->id);
        $this->assertStringContainsString('PAGE-'.$live->id.'-END', (string) $this->get('/')->getContent());

        $page = $this->page('Future Home', now()->addDay());
        app('cms.settings')->set('reading.homepage_page_id', $page->id);
        $this->assertStringNotContainsString('PAGE-'.$page->id.'-END', (string) $this->get('/')->getContent(), 'a scheduled static homepage is not served');
    }

    public function test_the_post_becomes_visible_at_the_boundary_without_a_write_or_flush(): void
    {
        $this->get($this->path('Future Scoop'))->assertNotFound();

        Carbon::setTestNow('2026-11-06 10:59:59');
        $this->get($this->path('Future Scoop'))->assertNotFound();

        Carbon::setTestNow('2026-11-06 11:00:00');
        $this->get($this->path('Future Scoop'))->assertOk();
        $this->assertStringContainsString('Future Scoop', app(RecentPostsWidget::class)->render(['limit' => 20], 'en'));
    }

    public function test_preview_link_of_a_scheduled_post_is_a_signed_preview_not_the_public_url(): void
    {
        $url = app('cms.preview_url')->forContent($this->posts['Future Scoop'], 'en');

        $this->assertNotNull($url);
        $this->assertStringContainsString('cms/preview', (string) $url, 'scheduled content previews through the signed route');
        $this->assertStringNotContainsString($this->path('Future Scoop'), (string) $url);
    }

    public function test_boundary_holds_with_the_public_cache_enabled(): void
    {
        config(['cms.cache.public_ttl' => 3600]);
        $this->assertTrue(app('cms.public_cache')->enabled(), 'public cache is on for this test');

        $this->get($this->path('Future Scoop'))->assertNotFound();
        $this->get($this->path('Future Scoop'))->assertNotFound(); // second request reads the cached resolution

        Carbon::setTestNow('2026-11-06 11:00:00');
        $this->get($this->path('Future Scoop'))->assertOk();
    }

    public function test_boundary_is_judged_in_the_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        date_default_timezone_set('Asia/Ho_Chi_Minh');

        try {
            Carbon::setTestNow(Carbon::parse('2026-11-06 20:00:00', 'Asia/Ho_Chi_Minh'));
            $post = $this->corePost('Evening Edition', ['published_at' => Carbon::parse('2026-11-06 21:00:00', 'Asia/Ho_Chi_Minh')]);
            $path = (string) parse_url(content_url($post, 'en'), PHP_URL_PATH);

            $this->get($path)->assertNotFound();
            Carbon::setTestNow(Carbon::parse('2026-11-06 20:59:59', 'Asia/Ho_Chi_Minh'));
            $this->get($path)->assertNotFound();
            Carbon::setTestNow(Carbon::parse('2026-11-06 21:00:00', 'Asia/Ho_Chi_Minh'));
            $this->get($path)->assertOk();
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_a_signed_in_editor_sees_the_scheduled_post_only_through_its_signed_preview(): void
    {
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder::class);
        $editor = \App\Models\User::factory()->create();
        \TheNguyen\CMS\Models\Role::query()->where('slug', 'editor')->firstOrFail()->users()->attach($editor->id);
        $this->actingAs($editor);

        $this->get($this->path('Future Scoop'))->assertNotFound();

        $preview = (string) app('cms.preview_url')->forContent($this->posts['Future Scoop'], 'en');
        $this->get((string) parse_url($preview, PHP_URL_PATH).'?'.(string) parse_url($preview, PHP_URL_QUERY))->assertOk()->assertSee('Future Scoop');
    }

    public function test_a_row_loaded_without_its_publication_time_is_not_treated_as_public(): void
    {
        $partial = Content::query()->whereKey($this->posts['Future Scoop']->id)->first(['id', 'status']);
        $this->assertFalse($partial->isPubliclyPublished(), 'an unknown publication time fails closed');

        $live = Content::query()->whereKey($this->posts['Live Legacy']->id)->first(['id', 'status', 'published_at']);
        $this->assertTrue($live->isPubliclyPublished(), 'a loaded NULL publication time is a legacy live row');
    }
}
