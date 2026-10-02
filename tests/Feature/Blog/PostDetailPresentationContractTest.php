<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use TheNguyen\CMS\Contracts\AdjacentPostResolver;
use TheNguyen\CMS\Contracts\PostBreadcrumbResolver;
use TheNguyen\CMS\Contracts\PublicAuthorResolver;
use TheNguyen\CMS\Contracts\RelatedPostResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\Blog\PostTaxonomyProjector;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\SectionDataProvider;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoPackage;
use TheNguyen\CMS\View\PostCardViewModel;
use TheNguyen\CMS\View\PublicAuthorViewModel;
use TheNguyen\CMS\View\TaxonomyLinkViewModel;

/**
 * CORE-BLOG-1 — certification of the secure post-detail presentation contracts
 * through the real pipeline. Seeds published posts/categories/tags/author via the
 * DemoImporter (so URLs resolve exactly like production) plus one directly-created
 * DRAFT to prove visibility exclusion.
 *
 * Fixture posts (vi slugs; all published unless noted), newest → oldest:
 *   Delta (delta)   2026-01-05  no category/tag
 *   [DraftNews]     2026-01-04  category=news   STATUS=draft (direct, excluded)
 *   Gamma (gamma)   2026-01-03  category=tech  tag=t1
 *   Beta  (beta)    2026-01-02  category=news  tag=t2   featured media
 *   Alpha (alpha)   2026-01-01  category=news  tag=t1
 */
final class PostDetailPresentationContractTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'blog1';

    private string $authorEmail = 'editor@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        // Active languages: vi is the default (unprefixed), en secondary — so the
        // localized URL helpers resolve real paths (content_url/term_url).
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->makeThemeWithDemo($this->slug);
        $this->superAdmin();
        app(ThemeManager::class)->activate($this->slug);
        app('cms.demo_importer')->import($this->package());
        $this->createDraftNewsPost();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.$this->slug));
        File::deleteDirectory(public_path('themes/'.$this->slug));
        parent::tearDown();
    }

    // ---- Adjacent posts ---------------------------------------------------

    public function test_adjacent_previous_and_next_are_deterministic_and_published_only(): void
    {
        $resolver = app(AdjacentPostResolver::class);

        $prev = $resolver->previous($this->fixturePost('beta'), 'vi');
        $next = $resolver->next($this->fixturePost('beta'), 'vi');

        $this->assertNotNull($prev);
        $this->assertSame('Alpha', $prev->title, 'previous = immediately older');
        $this->assertNotNull($next);
        $this->assertSame('Gamma', $next->title, 'next skips the 2026-01-04 DRAFT and lands on Gamma');
    }

    public function test_first_post_has_no_previous_and_newest_has_no_next(): void
    {
        $resolver = app(AdjacentPostResolver::class);

        $this->assertNull($resolver->previous($this->fixturePost('alpha'), 'vi'), 'oldest has no previous');
        $this->assertNull($resolver->next($this->fixturePost('delta'), 'vi'), 'newest published has no next (draft excluded)');
    }

    // ---- Related posts ----------------------------------------------------

    public function test_related_shares_taxonomy_excludes_self_and_draft(): void
    {
        $related = app(RelatedPostResolver::class)->forPost($this->fixturePost('beta'), 'vi');

        $titles = $related->map(fn (PostCardViewModel $c) => $c->title)->all();

        // Beta (news, t2): shares category news with Alpha only (DraftNews excluded,
        // Delta has none). Gamma is tech/t1 → not related.
        $this->assertSame(['Alpha'], $titles);
        $this->assertContainsOnlyInstancesOf(PostCardViewModel::class, $related);
    }

    public function test_related_degrades_to_latest_excluding_self_when_no_terms(): void
    {
        $related = app(RelatedPostResolver::class)->forPost($this->fixturePost('delta'), 'vi', 3);

        $titles = $related->map(fn (PostCardViewModel $c) => $c->title)->all();

        // Delta has no terms → "automatic" degrades to latest excluding self; the
        // draft is excluded; newest-first, capped at 3.
        $this->assertSame(['Gamma', 'Beta', 'Alpha'], $titles);
        $this->assertNotContains('Delta', $titles);
    }

    public function test_related_resolver_matches_page_builder_engine(): void
    {
        $beta = $this->fixturePost('beta');

        $resolverTitles = app(RelatedPostResolver::class)->forPost($beta, 'vi')
            ->map(fn (PostCardViewModel $c) => $c->title)->values()->all();

        /** @var SectionDataProvider $sections */
        $sections = app(SectionDataProvider::class);
        $pb = $sections->resolve('post-grid', [
            'data_source' => 'posts',
            'source_type' => 'related',
            'related_mode' => 'automatic',
            'limit' => 3,
        ], 'vi', $beta);

        $pbTitles = array_map(static fn (array $item): string => (string) $item['title'], $pb['posts'] ?? []);

        $this->assertSame($resolverTitles, $pbTitles, 'post-detail related == Page Builder related (shared algorithm)');
    }

    // ---- Breadcrumbs ------------------------------------------------------

    public function test_breadcrumbs_home_primary_category_current(): void
    {
        $items = app(PostBreadcrumbResolver::class)->forPost($this->fixturePost('beta'), 'vi');

        $this->assertCount(3, $items);
        $this->assertSame('/', $items[0]->url, 'home is locale-aware root');
        $this->assertFalse($items[0]->isCurrent);
        $this->assertSame('Tin tức', $items[1]->label, 'primary category label (vi)');
        $this->assertStringContainsString('tin-tuc', (string) $items[1]->url);
        $this->assertSame('Beta', $items[2]->label);
        $this->assertTrue($items[2]->isCurrent);
        $this->assertNull($items[2]->url);
    }

    public function test_breadcrumbs_omit_category_when_post_has_none(): void
    {
        $items = app(PostBreadcrumbResolver::class)->forPost($this->fixturePost('delta'), 'vi');

        $this->assertCount(2, $items, 'Home + current only');
        $this->assertTrue($items[1]->isCurrent);
    }

    // ---- Public author (privacy) -----------------------------------------

    public function test_public_author_is_safe_viewmodel_without_pii(): void
    {
        $author = app(PublicAuthorResolver::class)->forContent($this->fixturePost('beta'), 'vi');

        $this->assertInstanceOf(PublicAuthorViewModel::class, $author);
        $this->assertSame('Site Editor', $author->name);

        $json = json_encode($author->toArray());
        $this->assertStringNotContainsString($this->authorEmail, (string) $json, 'author email never leaks');
        $this->assertStringNotContainsStringIgnoringCase('password', (string) $json);
    }

    // ---- Taxonomy projection ---------------------------------------------

    public function test_categories_projection_returns_safe_locale_links(): void
    {
        $categories = app(PostTaxonomyProjector::class)->categories($this->fixturePost('beta'), 'vi');

        $this->assertCount(1, $categories);
        $this->assertInstanceOf(TaxonomyLinkViewModel::class, $categories[0]);
        $this->assertSame('category', $categories[0]->type);
        $this->assertSame('Tin tức', $categories[0]->label);
        $this->assertStringContainsString('tin-tuc', $categories[0]->url);
    }

    // ---- HTTP render: zero theme queries, no raw User --------------------

    public function test_post_detail_http_render_uses_safe_viewmodels_and_hides_pii(): void
    {
        $response = $this->get('/blog/beta');
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('AUTHORCLASS:'.PublicAuthorViewModel::class, $html, 'author is the safe ViewModel');
        $this->assertStringContainsString('ALIASCLASS:'.PublicAuthorViewModel::class, $html, '$author alias is the safe ViewModel, not App\\Models\\User');
        $this->assertStringNotContainsString(User::class, $html, 'no raw User class in view data');
        $this->assertStringNotContainsString($this->authorEmail, $html, 'author email never rendered');
        $this->assertStringContainsString('AUTHORNAME:Site Editor', $html);
        $this->assertStringContainsString('PREV:Alpha', $html);
        $this->assertStringContainsString('NEXT:Gamma', $html);
        $this->assertStringContainsString('REL:Alpha;', $html);
        $this->assertStringContainsString('RELCLASS:'.PostCardViewModel::class, $html);
        $this->assertStringContainsString('CAT:Tin tức,', $html);
        $this->assertStringContainsString('BC:', $html);
    }

    public function test_non_default_locale_uses_prefixed_canonical_urls(): void
    {
        $items = app(PostBreadcrumbResolver::class)->forPost($this->fixturePost('beta'), 'en');

        $this->assertSame('/en', $items[0]->url, 'home crumb is locale-prefixed in en');
        $this->assertStringContainsString('/en/', (string) $items[1]->url, 'category crumb is locale-prefixed');

        $related = app(RelatedPostResolver::class)->forPost($this->fixturePost('beta'), 'en');
        $this->assertSame(['Alpha'], $related->map(fn (PostCardViewModel $c) => $c->title)->all());
        $this->assertStringContainsString('/en/', $related->first()->url, 'related URL is locale-prefixed');
    }

    public function test_post_detail_http_render_in_non_default_locale(): void
    {
        $response = $this->get('/en/blog/beta');
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('PREV:Alpha', $html);
        $this->assertStringContainsString('NEXT:Gamma', $html);
        $this->assertStringContainsString('REL:Alpha;', $html);
        $this->assertStringNotContainsString($this->authorEmail, $html);
    }

    public function test_post_detail_query_budget_is_bounded(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/blog/beta')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded whole-request ceiling (route+SEO+resolvers+render). Not a
        // per-card scaling cost — see test_related_no_n_plus_one.
        $this->assertLessThanOrEqual(60, $count, "post-detail request ran {$count} queries");
    }

    public function test_related_resolver_has_no_relation_n_plus_one(): void
    {
        // The audit-forbidden N+1 is per-card translation/slug/taxonomy queries.
        // Eager loading batches them, so the number of queries touching the
        // relation tables must be CONSTANT regardless of how many cards are built
        // (1 related card for Beta vs 3 for the degrade-to-latest Delta).
        $relationQueries = function (Content $post, int $limit): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(RelatedPostResolver::class)->forPost($post, 'vi', $limit);
            $count = collect(DB::getQueryLog())
                ->filter(fn (array $q) => Str::contains($q['query'], [
                    'cms_content_translations', 'cms_term_translations', 'cms_taxonomies', 'cms_terms',
                ]))
                ->count();
            DB::disableQueryLog();

            return $count;
        };

        $oneCard = $relationQueries($this->fixturePost('beta'), 3);
        $threeCards = $relationQueries($this->fixturePost('delta'), 3);

        $this->assertSame(
            $oneCard,
            $threeCards,
            "relation queries must not scale with card count (1-card={$oneCard}, 3-card={$threeCards})",
        );
    }

    // ---- fixture helpers --------------------------------------------------

    private function fixturePost(string $viSlug): Content
    {
        $content = app(ContentManager::class)->findBySlug($viSlug, 'vi', 'post');
        $this->assertNotNull($content, "fixture post '{$viSlug}' missing");

        return $content;
    }

    private function package(): DemoPackage
    {
        return app('cms.demo_importer')->find($this->slug, 'starter');
    }

    private function superAdmin(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        $user = User::query()->create([
            'name' => 'Site Editor',
            'email' => $this->authorEmail,
            'password' => 'password',
        ]);
        $role->users()->syncWithoutDetaching([$user->getKey()]);
    }

    private function createDraftNewsPost(): void
    {
        $author = User::query()->first();
        $news = Term::query()
            ->whereHas('translations', fn ($q) => $q->where('locale', 'vi')->where('slug', 'tin-tuc'))
            ->first();

        $draft = Content::query()->create([
            'type' => 'post',
            'status' => 'draft',
            'author_id' => $author?->getKey(),
            'published_at' => '2026-01-04 00:00:00',
        ]);
        ContentTranslation::query()->create([
            'content_id' => $draft->getKey(),
            'locale' => 'vi',
            'title' => 'Draft News',
            'slug' => 'draft-news',
            'excerpt' => 'draft',
            'content' => '<p>draft</p>',
        ]);
        if ($news !== null) {
            $draft->terms()->syncWithoutDetaching([$news->getKey()]);
        }
    }

    private function makeThemeWithDemo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'posts', 'archives', 'pages'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));
        File::put($dir.'/views/layouts/master.blade.php', '@yield("content")');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE|{{ $title }}');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE|{{ $title }}');

        // Single-post view consumes ONLY the safe CORE-BLOG-1 contracts — no model,
        // no query. Emits markers the test asserts.
        File::put($dir.'/views/posts/post.blade.php', implode('', [
            'POST',
            '|T:{{ $title }}',
            "|AUTHORCLASS:{{ \$publicAuthor ? get_class(\$publicAuthor) : 'null' }}",
            "|ALIASCLASS:{{ isset(\$author) && is_object(\$author) ? get_class(\$author) : 'none' }}",
            '|AUTHORNAME:{{ $publicAuthor?->name }}',
            '|BC:@foreach($breadcrumbs as $b){{ $b->label }}>@endforeach',
            '|CAT:@foreach($categories as $c){{ $c->label }},@endforeach',
            "|PREV:{{ \$previousPost?->title ?? '-' }}",
            "|NEXT:{{ \$nextPost?->title ?? '-' }}",
            '|REL:@foreach($relatedPosts as $r){{ $r->title }};@endforeach',
            '|RELCLASS:@foreach($relatedPosts->take(1) as $r){{ get_class($r) }}@endforeach',
            '|END',
        ]));

        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);
        File::put($demo.'/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));

        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter', 'name' => 'BLOG1', 'description' => 'fixture', 'version' => '1.0.0',
            'files' => ['media' => 'media.json', 'categories' => 'categories.json', 'tags' => 'tags.json', 'posts' => 'posts.json'],
        ]));
        File::put($demo.'/media.json', json_encode(['media' => [['key' => 'cover', 'file' => 'cover.png', 'type' => 'image/png', 'width' => 1, 'height' => 1]]]));
        File::put($demo.'/categories.json', json_encode(['categories' => [
            ['key' => 'news', 'translations' => ['en' => ['name' => 'News', 'slug' => 'news'], 'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc']]],
            ['key' => 'tech', 'translations' => ['en' => ['name' => 'Tech', 'slug' => 'tech'], 'vi' => ['name' => 'Công nghệ', 'slug' => 'cong-nghe']]],
        ]]));
        File::put($demo.'/tags.json', json_encode(['tags' => [
            ['key' => 't1', 'translations' => ['en' => ['name' => 'Alpha Tag', 'slug' => 't1'], 'vi' => ['name' => 'Thẻ 1', 'slug' => 'the-1']]],
            ['key' => 't2', 'translations' => ['en' => ['name' => 'Beta Tag', 'slug' => 't2'], 'vi' => ['name' => 'Thẻ 2', 'slug' => 'the-2']]],
        ]]));
        File::put($demo.'/posts.json', json_encode(['posts' => [
            $this->postFixture('alpha', 'Alpha', '2026-01-01T00:00:00Z', ['category:news'], ['tag:t1'], null),
            $this->postFixture('beta', 'Beta', '2026-01-02T00:00:00Z', ['category:news'], ['tag:t2'], 'media:cover'),
            $this->postFixture('gamma', 'Gamma', '2026-01-03T00:00:00Z', ['category:tech'], ['tag:t1'], null),
            $this->postFixture('delta', 'Delta', '2026-01-05T00:00:00Z', [], [], null),
        ]]));
    }

    /**
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $tags
     * @return array<string, mixed>
     */
    private function postFixture(string $slug, string $title, string $publishedAt, array $categories, array $tags, ?string $media): array
    {
        $post = [
            'key' => $slug,
            'status' => 'published',
            'published_at' => $publishedAt,
            'author' => 'first_super_admin',
            'categories' => $categories,
            'tags' => $tags,
            'translations' => [
                'en' => ['title' => $title, 'slug' => $slug, 'excerpt' => $title.' excerpt', 'content' => '<p>'.$title.'</p>'],
                'vi' => ['title' => $title, 'slug' => $slug, 'excerpt' => $title.' tóm tắt', 'content' => '<p>'.$title.'</p>'],
            ],
        ];

        if ($media !== null) {
            $post['featured_media'] = $media;
        }

        return $post;
    }
}
