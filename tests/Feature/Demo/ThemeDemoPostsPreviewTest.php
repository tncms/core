<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoConflict;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * EG-9 Phase 2 — preview (dry-run) for categories/tags/posts: forecast + conflict
 * classification (owned match/changed, unowned slug, unresolved author) with a
 * hard ZERO-WRITE guarantee across every touched table and no provenance.
 */
final class ThemeDemoPostsPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'eg9p';

    protected function setUp(): void
    {
        parent::setUp();
        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        $this->makeThemeWithDemo($this->slug);
        app(ThemeManager::class)->activate($this->slug);
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
        return $this->importer()->find($this->slug, 'starter');
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return [
            'terms' => DB::table('cms_terms')->count(),
            'term_translations' => DB::table('cms_term_translations')->count(),
            'contents' => DB::table('cms_contents')->count(),
            'content_translations' => DB::table('cms_content_translations')->count(),
            'slugs' => DB::table('cms_slugs')->count(),
            'content_terms' => DB::table('cms_content_terms')->count(),
            'media' => DB::table('cms_media')->count(),
        ];
    }

    private function superAdmin(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        /** @var User $user */
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password']);
        $role->users()->syncWithoutDetaching([$user->getKey()]);

        return $user;
    }

    public function test_fresh_preview_forecasts_creates_and_writes_nothing(): void
    {
        $before = $this->rowCounts();

        $plan = $this->importer()->preview($this->package());

        $this->assertTrue($plan['ok']);
        $this->assertFalse($plan['reimport']);

        $forecast = fn (string $file, string $action) => array_filter(
            $plan['actions'],
            fn ($a) => $a['file'] === $file && $a['action'] === $action,
        );
        $this->assertCount(2, $forecast('categories', 'create'));
        $this->assertCount(1, $forecast('tags', 'create'));
        $this->assertCount(1, $forecast('posts', 'create'));

        // No super-admin exists → author cannot resolve → classified in preview.
        $this->assertContains(DemoConflict::AUTHOR_UNRESOLVED, array_column($plan['conflicts'], 'class'));

        // HARD zero-write proof across every touched table + no provenance.
        $this->assertSame($before, $this->rowCounts(), 'preview must not write any row');
        $this->assertNull(settings()->get('demo.imports.'.$this->slug.'.starter'), 'preview must not record provenance');
    }

    public function test_reimport_preview_classifies_owned_match_and_writes_nothing(): void
    {
        $this->superAdmin();
        $this->importer()->import($this->package());

        $after = $this->rowCounts();
        $plan = $this->importer()->preview($this->package());

        $this->assertTrue($plan['reimport']);

        // Identical source → owned objects classified OWNED_MATCH (fingerprints).
        $classesByKey = [];
        foreach ($plan['conflicts'] as $c) {
            $classesByKey[$c['key']][] = $c['class'];
        }
        $this->assertContains(DemoConflict::OWNED_MATCH, $classesByKey['hello-tncms'] ?? []);
        $this->assertContains(DemoConflict::OWNED_MATCH, $classesByKey['news'] ?? []);

        // Preview after an import writes nothing new.
        $this->assertSame($after, $this->rowCounts(), 're-preview must not write');
    }

    public function test_changed_source_preview_classifies_owned_changed(): void
    {
        $this->superAdmin();
        $this->importer()->import($this->package());

        // Mutate the on-disk declarative source for the post.
        $postsFile = base_path('themes/'.$this->slug.'/demo/starter/posts.json');
        $posts = json_decode(File::get($postsFile), true);
        $posts['posts'][0]['translations']['en']['content'] = '<p>Changed body</p>';
        File::put($postsFile, json_encode($posts));

        $plan = $this->importer()->preview($this->importer()->find($this->slug, 'starter'));

        $classesByKey = [];
        foreach ($plan['conflicts'] as $c) {
            $classesByKey[$c['key']][] = $c['class'];
        }
        $this->assertContains(DemoConflict::OWNED_CHANGED, $classesByKey['hello-tncms'] ?? []);
    }

    public function test_user_slug_collision_is_classified_in_preview(): void
    {
        // A user owns the demo category's default-locale slug before preview.
        $default = app('cms.language')->defaultCode();
        app(TaxonomyManager::class)->createTerm('category', ['locale' => $default, 'name' => 'User', 'slug' => 'tin-tuc']);

        $before = $this->rowCounts();
        $plan = $this->importer()->preview($this->package());

        $this->assertContains(DemoConflict::UNOWNED_SAME_SLUG, array_column($plan['conflicts'], 'class'));
        $this->assertSame($before, $this->rowCounts(), 'preview must not write');
    }

    private function makeThemeWithDemo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'posts'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'EG9P-MASTER');
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));

        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);
        File::put($demo.'/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));

        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter', 'name' => 'EG9P', 'description' => 'fixture', 'version' => '1.0.0',
            'files' => ['media' => 'media.json', 'categories' => 'categories.json', 'tags' => 'tags.json', 'posts' => 'posts.json'],
        ]));
        File::put($demo.'/media.json', json_encode(['media' => [['key' => 'cover', 'file' => 'cover.png', 'type' => 'image/png', 'width' => 1, 'height' => 1]]]));
        File::put($demo.'/categories.json', json_encode(['categories' => [
            ['key' => 'updates', 'translations' => ['en' => ['name' => 'Updates', 'slug' => 'updates'], 'vi' => ['name' => 'Cập nhật', 'slug' => 'cap-nhat']]],
            ['key' => 'news', 'parent' => 'category:updates', 'translations' => ['en' => ['name' => 'News', 'slug' => 'news'], 'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc']]],
        ]]));
        File::put($demo.'/tags.json', json_encode(['tags' => [
            ['key' => 'tncms', 'translations' => ['en' => ['name' => 'TN CMS', 'slug' => 'tncms'], 'vi' => ['name' => 'TN CMS', 'slug' => 'tncms-vi']]],
        ]]));
        File::put($demo.'/posts.json', json_encode(['posts' => [
            [
                'key' => 'hello-tncms', 'status' => 'published', 'published_at' => '2026-01-01T00:00:00Z',
                'author' => 'first_super_admin', 'featured_media' => 'media:cover',
                'categories' => ['category:news'], 'tags' => ['tag:tncms'],
                'translations' => [
                    'en' => ['title' => 'Hello TN CMS', 'slug' => 'hello-tncms', 'excerpt' => 'Hi', 'content' => '<p>Hello</p>'],
                    'vi' => ['title' => 'Xin chào TN CMS', 'slug' => 'xin-chao-tncms', 'excerpt' => 'Chào', 'content' => '<p>Xin chào</p>'],
                ],
            ],
        ]]));
    }
}
