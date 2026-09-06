<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\DemoPostImporter;
use TheNguyen\CMS\Services\DemoSymbolResolver;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Support\DemoConflict;

/**
 * EG-9 Phase 1D — native post importer. Posts are Core {@see Content} rows
 * (type=post) written through {@see \TheNguyen\CMS\Services\ContentManager}
 * (Core owns persistence, slug uniqueness, term sync, revisions). Category/tag
 * are symbolic refs → term ids; featured media is a `media:{key}` ref; the author
 * is a safe strategy token, never a raw user id.
 */
final class DemoPostImporterTest extends TestCase
{
    use RefreshDatabase;

    private DemoPostImporter $importer;

    private string $default;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomyManager::class)->ensureCoreTaxonomies();

        $this->importer = app(DemoPostImporter::class);
        $this->default = app('cms.language')->defaultCode();
    }

    private function superAdmin(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Site Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
        ]);
        $role->users()->syncWithoutDetaching([$user->getKey()]);

        return $user;
    }

    /**
     * Build a resolver pre-populated with a category, a tag and a media row
     * (as the DemoImporter would have, importing media→categories→tags→posts).
     *
     * @return array{0: DemoSymbolResolver, 1: int, 2: int, 3: int} [resolver, categoryId, tagId, mediaId]
     */
    private function graph(): array
    {
        $tax = app(TaxonomyManager::class);
        $category = $tax->createTerm('category', ['locale' => $this->default, 'name' => 'News', 'slug' => 'news']);
        $tag = $tax->createTerm('tag', ['locale' => $this->default, 'name' => 'TN CMS', 'slug' => 'tncms']);

        /** @var Media $media */
        $media = Media::query()->create([
            'disk' => 'public',
            'filename' => 'cover.jpg',
            'original_filename' => 'cover.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1234,
            'path' => 'media/cover.jpg',
            'url' => '/storage/media/cover.jpg',
        ]);

        $resolver = new DemoSymbolResolver;
        $resolver->register('category', 'news', (int) $category->id);
        $resolver->register('tag', 'tncms', (int) $tag->id);
        $resolver->register('media', 'starter-post-cover', (int) $media->id);

        return [$resolver, (int) $category->id, (int) $tag->id, (int) $media->id];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return [
            'posts' => [[
                'key' => 'hello-tncms',
                'status' => 'published',
                'published_at' => '2026-01-01T00:00:00Z',
                'author' => 'first_super_admin',
                'featured_media' => 'media:starter-post-cover',
                'categories' => ['category:news'],
                'tags' => ['tag:tncms'],
                'is_featured' => true,
                'translations' => [
                    'en' => ['title' => 'Hello TN CMS', 'slug' => 'hello-tncms', 'excerpt' => 'Hi', 'content' => '<p>Hello</p>'],
                    'vi' => ['title' => 'Xin chào TN CMS', 'slug' => 'xin-chao-tncms', 'excerpt' => 'Chào', 'content' => '<p>Xin chào</p>'],
                ],
            ]],
        ];
    }

    public function test_fresh_post_import_persists_all_declared_facets(): void
    {
        $admin = $this->superAdmin();
        [$resolver, $categoryId, $tagId, $mediaId] = $this->graph();
        $media = Media::query()->findOrFail($mediaId);

        [$keyMap, $postIds, $fingerprints, $warnings, $conflicts, $any] =
            $this->importer->import($this->fixture(), $resolver, []);

        $this->assertTrue($any, 'import ran: '.json_encode($warnings));
        $this->assertArrayHasKey('post:hello-tncms', $keyMap);

        $post = Content::query()->with(['translations', 'terms'])->findOrFail($keyMap['post:hello-tncms']);

        $this->assertSame('post', $post->type);
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame(2026, (int) $post->published_at->year);
        $this->assertTrue((bool) $post->is_featured);

        // Default-locale-first + VI/EN translations.
        $this->assertCount(2, $post->translations);
        $this->assertSame('Hello TN CMS', $post->translatedTitle('en'));
        $this->assertSame('Xin chào TN CMS', $post->translatedTitle('vi'));

        // Featured media resolved to the media URL (never a path guess).
        $this->assertSame($media->url, $post->featured_image);

        // Category + tag symbolic refs attached as term relations.
        $termIds = $post->terms->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains($categoryId, $termIds);
        $this->assertContains($tagId, $termIds);

        // Safe author mapping.
        $this->assertSame($admin->getKey(), $post->author_id);

        // Symbolic identity recoverable + fingerprint recorded.
        $this->assertSame($keyMap['post:hello-tncms'], $resolver->idFor('post', 'hello-tncms'));
        $this->assertArrayHasKey('post:hello-tncms', $fingerprints);
    }

    public function test_same_input_retry_creates_no_duplicate_post_or_relations(): void
    {
        $this->superAdmin();
        [$resolver1] = $this->graph();

        [$first] = $this->importer->import($this->fixture(), $resolver1, []);
        $postCount = Content::query()->where('type', 'post')->count();
        $post = Content::query()->findOrFail($first['post:hello-tncms']);
        $relCount = $post->terms()->count();

        // Retry (fresh resolver rebuilt from the same graph + prior imported_keys).
        [$resolver2] = $this->graph2($resolver1);
        [$second] = $this->importer->import($this->fixture(), $resolver2, $first);

        $this->assertSame($first['post:hello-tncms'], $second['post:hello-tncms'], 'retry reuses the same post row');
        $this->assertSame($postCount, Content::query()->where('type', 'post')->count(), 'no duplicate posts');

        $post->refresh();
        $this->assertSame($relCount, $post->terms()->count(), 'no duplicate post↔term relations');
        $this->assertSame(2, $post->terms()->count(), 'exactly one category + one tag');
    }

    /**
     * The retry resolver must still resolve the SAME category/tag/media the first
     * import used. Re-register the identical ids on a fresh resolver.
     */
    private function graph2(DemoSymbolResolver $prior): array
    {
        // The DemoImporter rebuilds the per-run resolver from THIS run's
        // media/category/tag imports (same reused ids) — never from prior post
        // keys (those are detected as in-run duplicates). Mirror that here.
        $map = array_filter(
            $prior->toArray(),
            static fn ($k) => ! str_starts_with($k, 'post:'),
            ARRAY_FILTER_USE_KEY,
        );

        return [new DemoSymbolResolver($map)];
    }

    public function test_unresolved_author_is_classified_and_post_is_skipped(): void
    {
        // No super-admin exists → first_super_admin cannot resolve → fail closed.
        [$resolver] = $this->graph();

        [$keyMap, $postIds, , $warnings, $conflicts, $any] =
            $this->importer->import($this->fixture(), $resolver, []);

        $this->assertFalse($any);
        $this->assertCount(0, $postIds);
        $this->assertContains(DemoConflict::AUTHOR_UNRESOLVED, array_column($conflicts, 'class'));
    }
}
