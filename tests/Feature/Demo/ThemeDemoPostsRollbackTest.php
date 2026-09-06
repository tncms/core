<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * EG-9 Phase 3 — ownership-safe rollback. Reset removes ONLY provenance-owned
 * posts/categories/tags (never a slug/title match), preserves user content and
 * user relationships, never deletes a taxonomy a user object still references
 * (shared-taxonomy safety), and supports the full import→retry→reset→re-import
 * lifecycle.
 */
final class ThemeDemoPostsRollbackTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'eg9r';

    protected function setUp(): void
    {
        parent::setUp();
        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        $this->makeThemeWithDemo($this->slug);
        $this->superAdmin();
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

    private function superAdmin(): User
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        /** @var User $user */
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password']);
        $role->users()->syncWithoutDetaching([$user->getKey()]);

        return $user;
    }

    public function test_reset_removes_only_importer_owned_posts_and_taxonomy(): void
    {
        $this->importer()->import($this->package());
        $prov = settings()->get('demo.imports.'.$this->slug.'.starter');
        $postId = $prov['imported_keys']['post:hello-tncms'];
        $newsId = $prov['imported_keys']['category:news'];
        $tagId = $prov['imported_keys']['tag:tncms'];

        $result = $this->importer()->reset($this->package());
        $this->assertTrue($result->success);

        $this->assertNull(Content::query()->find($postId), 'demo post removed');
        $this->assertNull(Term::query()->find($newsId), 'demo category removed');
        $this->assertNull(Term::query()->find($tagId), 'demo tag removed');
        $this->assertNull(settings()->get('demo.imports.'.$this->slug.'.starter'), 'provenance dropped');
    }

    public function test_user_content_and_relationships_survive_import_and_reset(): void
    {
        // Pre-existing USER graph created BEFORE the demo import.
        $userCat = app(TaxonomyManager::class)->createTerm('category', ['locale' => 'vi', 'name' => 'User Cat', 'slug' => 'user-cat']);
        $userMedia = Media::query()->create([
            'disk' => 'public', 'filename' => 'u.jpg', 'original_filename' => 'u.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'size' => 10, 'path' => 'u.jpg', 'url' => '/u.jpg',
        ]);
        $userPost = app(ContentManager::class)->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'User Post', 'slug' => 'user-post',
            'content' => '<p>mine</p>', 'term_ids' => [$userCat->id],
        ]);

        $this->importer()->import($this->package());
        $this->assertTrue($this->importer()->reset($this->package())->success);

        // Every user object + relationship is intact.
        $this->assertNotNull(Term::query()->find($userCat->id), 'user category survives');
        $this->assertNotNull(Media::query()->find($userMedia->id), 'user media survives');
        $userPost->refresh();
        $this->assertNotNull(Content::query()->find($userPost->id), 'user post survives');
        $this->assertTrue($userPost->terms()->whereKey($userCat->id)->exists(), 'user post↔category relation survives');
    }

    public function test_shared_taxonomy_is_preserved_when_user_content_references_it(): void
    {
        $this->importer()->import($this->package());
        $prov = settings()->get('demo.imports.'.$this->slug.'.starter');
        $newsId = (int) $prov['imported_keys']['category:news'];

        // A USER attaches their own post to the demo-created category AFTER import.
        $userPost = app(ContentManager::class)->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'User uses demo cat',
            'slug' => 'user-uses-demo-cat', 'content' => '<p>x</p>', 'term_ids' => [$newsId],
        ]);

        $result = $this->importer()->reset($this->package());
        $this->assertTrue($result->success);

        // The shared category is PRESERVED (not deleted) and reported as such.
        $this->assertNotNull(Term::query()->find($newsId), 'shared demo category is preserved');
        $userPost->refresh();
        $this->assertTrue($userPost->terms()->whereKey($newsId)->exists(), 'user relationship to shared category survives');
        $this->assertNotSame([], array_filter($result->warnings, fn ($w) => str_contains($w, 'preserved')));
    }

    public function test_full_lifecycle_import_retry_reset_reimport(): void
    {
        // import
        $this->assertTrue($this->importer()->import($this->package())->success);
        // retry (idempotent)
        $this->assertTrue($this->importer()->import($this->package())->success);
        // reset
        $this->assertTrue($this->importer()->reset($this->package())->success);
        $this->assertSame(0, Content::query()->where('type', 'post')->count(), 'no demo posts after reset');

        // re-import must recreate cleanly (no failed writes).
        $result = $this->importer()->import($this->package());
        $this->assertTrue($result->success);
        $this->assertSame([], array_values(array_filter($result->warnings, fn ($w) => str_contains($w, 'could not be imported'))));

        $prov = settings()->get('demo.imports.'.$this->slug.'.starter');
        $this->assertIsInt($prov['imported_keys']['post:hello-tncms'] ?? null, 'post recreated after reset');
        $this->assertSame(1, Content::query()->where('type', 'post')->count());
        $this->assertSame(2, Term::query()->whereHas('taxonomy', fn ($q) => $q->where('type', 'category'))->count());
    }

    private function makeThemeWithDemo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        File::ensureDirectoryExists($dir.'/views/layouts');
        File::put($dir.'/views/layouts/master.blade.php', 'EG9R-MASTER');
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));

        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);
        File::put($demo.'/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));

        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter', 'name' => 'EG9R', 'description' => 'fixture', 'version' => '1.0.0',
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
