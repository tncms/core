<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * EG-9 Phase 1 (integrated) — the native DemoImporter wiring for the full
 * media → categories → tags → pages → posts graph, symbolic resolution,
 * provenance/fingerprint recording, and deterministic retry idempotency with
 * EXACT row-count evidence.
 */
final class ThemeDemoPostsTaxonomyImportTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'eg9t';

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        $this->makeThemeWithDemo($this->slug);
        $this->superAdmin();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.$this->slug));
        File::deleteDirectory(public_path('themes/'.$this->slug));

        parent::tearDown();
    }

    private function importer(): DemoImporter
    {
        return app('cms.demo_importer');
    }

    private function package(): DemoPackage
    {
        $package = $this->importer()->find($this->slug, 'starter');
        $this->assertNotNull($package, 'demo package must be discovered');

        return $package;
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

    // ---- full graph import ----------------------------------------------

    public function test_import_creates_full_graph_with_symbolic_resolution_and_provenance(): void
    {
        app(ThemeManager::class)->activate($this->slug);

        $result = $this->importer()->import($this->package());
        $this->assertTrue($result->success, 'import failed: '.json_encode($result->warnings));

        $prov = settings()->get('demo.imports.'.$this->slug.'.starter');
        $this->assertIsArray($prov);

        // Namespaced symbolic identity is recoverable in imported_keys.
        $this->assertArrayHasKey('category:news', $prov['imported_keys']);
        $this->assertArrayHasKey('category:updates', $prov['imported_keys']);
        $this->assertArrayHasKey('tag:tncms', $prov['imported_keys']);
        $this->assertArrayHasKey('post:hello-tncms', $prov['imported_keys']);

        // Per-type id lists + fingerprints recorded.
        $this->assertCount(2, $prov['imported_category_ids']);
        $this->assertCount(1, $prov['imported_tag_ids']);
        $this->assertCount(1, $prov['imported_post_ids']);
        $this->assertArrayHasKey('post:hello-tncms', $prov['fingerprints']);
        $this->assertMatchesRegularExpression('/^sha256:/', $prov['fingerprints']['post:hello-tncms']);

        // Hierarchical parent symbolic ref wired.
        $news = Term::query()->findOrFail($prov['imported_keys']['category:news']);
        $this->assertSame($prov['imported_keys']['category:updates'], (int) $news->parent_id);

        // The post carries its taxonomy, featured media (URL) and author.
        $post = Content::query()->with('terms')->findOrFail($prov['imported_keys']['post:hello-tncms']);
        $termIds = $post->terms->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains($prov['imported_keys']['category:news'], $termIds);
        $this->assertContains($prov['imported_keys']['tag:tncms'], $termIds);

        $cover = Media::query()->findOrFail($prov['imported_keys']['cover']);
        $this->assertSame($cover->url, $post->featured_image);
        $this->assertNotNull($post->author_id);
        $this->assertCount(2, $post->translations, 'VI + EN translations');
    }

    // ---- deterministic retry idempotency (exact row counts) -------------

    public function test_retry_is_idempotent_with_exact_row_counts(): void
    {
        app(ThemeManager::class)->activate($this->slug);

        $this->importer()->import($this->package());

        $counts = fn (): array => [
            'categories' => Term::query()->whereHas('taxonomy', fn ($q) => $q->where('type', 'category'))->count(),
            'tags' => Term::query()->whereHas('taxonomy', fn ($q) => $q->where('type', 'tag'))->count(),
            'posts' => Content::query()->where('type', 'post')->count(),
            'content_translations' => DB::table('cms_content_translations')->count(),
            'term_translations' => DB::table('cms_term_translations')->count(),
            'content_slugs' => Slug::query()->where('reference_type', 'content')->count(),
            'term_slugs' => Slug::query()->where('reference_type', 'term')->count(),
            'post_terms' => DB::table('cms_content_terms')->count(),
        ];

        $before = $counts();

        // Retry the identical preset.
        $result = $this->importer()->import($this->package());
        $this->assertTrue($result->success);

        $after = $counts();

        $this->assertSame($before, $after, 'retry must not change any row count: '.json_encode(['before' => $before, 'after' => $after]));

        // Sanity: the graph is what we expect (1 post, 2 categories, 1 tag,
        // 1 post↔category + 1 post↔tag relation).
        $this->assertSame(2, $after['categories']);
        $this->assertSame(1, $after['tags']);
        $this->assertSame(1, $after['posts']);
        $this->assertSame(2, $after['post_terms']);
    }

    // ---- fixture ---------------------------------------------------------

    private function makeThemeWithDemo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'EG9-MASTER');
        File::put($dir.'/views/posts/post.blade.php', 'EG9-POST');
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));

        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);

        // A real (tiny) PNG so MediaManager::importFile copies a valid asset.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC');
        File::put($demo.'/cover.png', $png);

        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter',
            'name' => 'EG9 Starter', 'description' => 'fixture', 'version' => '1.0.0',
            'files' => [
                'media' => 'media.json',
                'categories' => 'categories.json',
                'tags' => 'tags.json',
                'posts' => 'posts.json',
            ],
        ]));

        File::put($demo.'/media.json', json_encode(['media' => [
            ['key' => 'cover', 'file' => 'cover.png', 'type' => 'image/png', 'width' => 1, 'height' => 1, 'alt' => 'Cover'],
        ]]));

        File::put($demo.'/categories.json', json_encode(['categories' => [
            ['key' => 'updates', 'sort_order' => 1, 'translations' => [
                'en' => ['name' => 'Updates', 'slug' => 'updates'],
                'vi' => ['name' => 'Cập nhật', 'slug' => 'cap-nhat'],
            ]],
            ['key' => 'news', 'parent' => 'category:updates', 'sort_order' => 2, 'translations' => [
                'en' => ['name' => 'News', 'slug' => 'news'],
                'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc'],
            ]],
        ]]));

        File::put($demo.'/tags.json', json_encode(['tags' => [
            ['key' => 'tncms', 'translations' => [
                'en' => ['name' => 'TN CMS', 'slug' => 'tncms'],
                'vi' => ['name' => 'TN CMS', 'slug' => 'tncms-vi'],
            ]],
        ]]));

        File::put($demo.'/posts.json', json_encode(['posts' => [
            [
                'key' => 'hello-tncms',
                'status' => 'published',
                'published_at' => '2026-01-01T00:00:00Z',
                'author' => 'first_super_admin',
                'featured_media' => 'media:cover',
                'categories' => ['category:news'],
                'tags' => ['tag:tncms'],
                'translations' => [
                    'en' => ['title' => 'Hello TN CMS', 'slug' => 'hello-tncms', 'excerpt' => 'Hi', 'content' => '<p>Hello</p>'],
                    'vi' => ['title' => 'Xin chào TN CMS', 'slug' => 'xin-chao-tncms', 'excerpt' => 'Chào', 'content' => '<p>Xin chào</p>'],
                ],
            ],
        ]]));
    }
}
