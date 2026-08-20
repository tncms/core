<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Search\Providers\ContentSearchDefinition;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Models\Content;
use Tests\TestCase;

/**
 * CORE-SEARCH-1 (GAP 1) — the Core Post/Page provider.
 *
 * Proves the CMS can search its own published content with NO optional plugin
 * installed, that retrieval honours the canonical published/locale authority
 * (drafts, trashed rows, and other-locale content never leak), and that hits carry
 * the canonical public URL for the query locale.
 */
final class ContentSearchProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $language->setCurrent('en');
        app()->setLocale('en');
    }

    /** @return list<\App\Search\SearchResult> */
    private function search(string $keyword, string $locale = 'en', array $types = []): array
    {
        return app(\App\Search\Contracts\SearchManagerInterface::class)
            ->search(SearchQuery::create($keyword, $locale, $types))
            ->results;
    }

    private function publishedPost(string $title, string $slug, string $body = '<p>body</p>', string $excerpt = '', string $status = 'published'): Content
    {
        return app('cms.content')->create([
            'type' => 'post', 'status' => $status, 'locale' => 'en',
            'title' => $title, 'slug' => $slug, 'content' => $body, 'excerpt' => $excerpt,
        ]);
    }

    // ── Core works with no optional plugins ─────────────────────────────────────

    public function test_content_type_is_registered_on_a_bare_install(): void
    {
        $registry = app(SearchRegistry::class);

        $this->assertTrue($registry->hasType(ContentSearchDefinition::TYPE));
        $this->assertSame('content', $registry->providerFor(ContentSearchDefinition::TYPE)?->key());
    }

    // ── Published Post / Page retrieval ─────────────────────────────────────────

    public function test_searches_published_post_by_title(): void
    {
        $this->publishedPost('Laravel Rocks', 'laravel-rocks');

        $results = $this->search('Laravel');

        $this->assertCount(1, $results);
        $this->assertSame('content', $results[0]->type);
        $this->assertSame('Laravel Rocks', $results[0]->title);
        $this->assertSame('post', $results[0]->metadata['subtype']);
        $this->assertStringContainsString('/blog/laravel-rocks', (string) $results[0]->url);
    }

    public function test_searches_published_page(): void
    {
        app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'About Laravel', 'slug' => 'about', 'content' => '<p>x</p>',
        ]);

        $results = $this->search('About');

        $this->assertCount(1, $results);
        $this->assertSame('page', $results[0]->metadata['subtype']);
    }

    public function test_matches_body_and_excerpt_not_only_title(): void
    {
        $this->publishedPost('Alpha', 'alpha', '<p>mentions Zebra deep in the body</p>');
        $this->publishedPost('Beta', 'beta', '<p>x</p>', 'a short Zebra excerpt');

        $titles = array_map(fn ($r) => $r->title, $this->search('Zebra'));
        sort($titles);

        $this->assertSame(['Alpha', 'Beta'], $titles);
    }

    // ── Visibility: drafts, trashed, missing-slug never leak ─────────────────────

    public function test_excludes_draft_content(): void
    {
        $this->publishedPost('Draft Widget', 'draft-widget', '<p>x</p>', '', 'draft');

        $this->assertCount(0, $this->search('Widget'));
    }

    public function test_excludes_soft_deleted_content(): void
    {
        $post = $this->publishedPost('Deletable', 'deletable');
        $post->delete();

        $this->assertCount(0, $this->search('Deletable'));
    }

    // ── Locale isolation (release blocker, §17) ─────────────────────────────────

    public function test_current_locale_only_no_cross_locale_leak(): void
    {
        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => 'Elephant', 'slug' => 'elephant', 'content' => '<p>en</p>',
        ]);
        app('cms.content')->update($post, [
            'type' => 'post', 'locale' => 'vi', 'title' => 'Con Voi', 'slug' => 'con-voi', 'content' => '<p>vi</p>',
        ]);

        // English keyword resolves in English, not in Vietnamese scope.
        $this->assertCount(1, $this->search('Elephant', 'en'));
        $this->assertCount(0, $this->search('Elephant', 'vi'));

        // Vietnamese keyword resolves in Vietnamese, with the vi URL.
        $vi = $this->search('Voi', 'vi');
        $this->assertCount(1, $vi);
        $this->assertStringContainsString('/vi/', (string) $vi[0]->url);
        $this->assertSame('vi', $vi[0]->locale);
    }

    public function test_content_without_translation_in_locale_is_excluded(): void
    {
        // English-only post: it has no vi slug, so it must not appear in vi search.
        $this->publishedPost('OnlyEnglish', 'only-english', '<p>OnlyEnglish body</p>');

        $this->assertCount(1, $this->search('OnlyEnglish', 'en'));
        $this->assertCount(0, $this->search('OnlyEnglish', 'vi'));
    }

    // ── URL comes from the canonical authority ──────────────────────────────────

    public function test_result_url_is_the_canonical_content_url(): void
    {
        $post = $this->publishedPost('Canonical', 'canonical');

        $results = $this->search('Canonical');

        $this->assertSame(content_url($post->fresh(), 'en'), $results[0]->url);
    }

    // ── Safe edges ──────────────────────────────────────────────────────────────

    public function test_empty_query_returns_nothing(): void
    {
        $this->publishedPost('Anything', 'anything');

        $this->assertCount(0, $this->search(''));
    }

    public function test_unknown_scope_is_safe(): void
    {
        $this->publishedPost('Real', 'real');

        // A stale/hostile scope key resolves to no known type → empty, no error.
        $this->assertCount(0, $this->search('Real', 'en', ['no-such-type']));
    }
}
