<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * EG-9 Phase 4 — frontend / EG-8 render certification through the REAL HTTP
 * request cycle (routing → middleware → FrontendController → theme views).
 *
 * The theme views deliberately consume ONLY Core-provided data — no
 * `Post::query()`, `Media::find()`, or `DB::table()` — so a green render proves
 * Core supplies enough for the single post AND the taxonomy archive (title,
 * excerpt, featured image via MediaViewModel, author, tags), i.e. EG-8 needs no
 * theme model query.
 */
final class ThemeDemoPostsFrontendRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $slug = 'eg9f';

    protected function setUp(): void
    {
        parent::setUp();
        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        $this->makeThemeWithDemo($this->slug);
        $this->superAdmin();
        app(ThemeManager::class)->activate($this->slug);
        app('cms.demo_importer')->import($this->package());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.$this->slug));
        File::deleteDirectory(public_path('themes/'.$this->slug));
        parent::tearDown();
    }

    private function package(): DemoPackage
    {
        /** @var DemoImporter $importer */
        $importer = app('cms.demo_importer');

        return $importer->find($this->slug, 'starter');
    }

    private function superAdmin(): void
    {
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        $user = User::query()->create(['name' => 'Site Editor', 'email' => 'ed@example.test', 'password' => 'password']);
        $role->users()->syncWithoutDetaching([$user->getKey()]);
    }

    public function test_single_post_renders_from_core_provided_data_only(): void
    {
        // Default-locale (vi) post slug from the fixture.
        $response = $this->get('/blog/xin-chao-tncms');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('POST-VIEW', $html, 'single-post theme view rendered');
        $this->assertStringContainsString('Xin chào TN CMS', $html, 'title from Core');
        $this->assertStringContainsString('Chào', $html, 'excerpt from Core');
        $this->assertStringContainsString('/uploads/', $html, 'featured image URL from Core (media provenance)');
        $this->assertStringContainsString('AUTHOR:Site Editor', $html, 'author supplied by Core (no theme query)');
        $this->assertStringContainsString('TAG:TN CMS', $html, 'tag label supplied by Core (no theme query)');
    }

    public function test_category_archive_lists_post_from_core_provided_data_only(): void
    {
        // Default-locale (vi) category slug from the fixture.
        $response = $this->get('/category/tin-tuc');

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('ARCHIVE-VIEW', $html, 'archive theme view rendered');
        $this->assertStringContainsString('Xin chào TN CMS', $html, 'post title listed from Core');
        $this->assertStringContainsString('/uploads/', $html, 'featured image URL available in archive (MediaViewModel::fromUrl)');
    }

    private function makeThemeWithDemo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        foreach (['pages'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));
        File::put($dir.'/views/layouts/master.blade.php', '@yield("content")');
        // Required view (theme activation is fails-closed on missing required views).
        File::put($dir.'/views/pages/page.blade.php', 'PAGE-VIEW|{{ $title }}');

        // Single-post view — consumes ONLY Core-provided variables.
        File::put($dir.'/views/posts/post.blade.php',
            'POST-VIEW|{{ $title }}|{{ $excerpt }}|{{ $featuredImage }}'.
            '|AUTHOR:@if(!empty($author)){{ $author->name }}@endif'.
            '|@foreach(($tags ?? []) as $t)TAG:{{ $t->displayName(current_locale()) }}@endforeach'
        );

        // Archive view — consumes ONLY the passed $posts collection + MediaViewModel.
        File::put($dir.'/views/archives/index.blade.php',
            'ARCHIVE-VIEW|{{ $title }}|'.
            '@foreach($posts as $p)'.
            'ITEM:{{ $p->translatedTitle(current_locale()) }}'.
            ';IMG:{{ \TheNguyen\CMS\View\MediaViewModel::fromUrl((string) ($p->featured_image ?? ""))->url }}'.
            ';EXCERPT:{{ optional($p->translations->firstWhere("locale", current_locale()))->excerpt }}|'.
            '@endforeach'
        );

        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);
        File::put($demo.'/cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));

        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter', 'name' => 'EG9F', 'description' => 'fixture', 'version' => '1.0.0',
            'files' => ['media' => 'media.json', 'categories' => 'categories.json', 'tags' => 'tags.json', 'posts' => 'posts.json'],
        ]));
        File::put($demo.'/media.json', json_encode(['media' => [['key' => 'cover', 'file' => 'cover.png', 'type' => 'image/png', 'width' => 1, 'height' => 1]]]));
        File::put($demo.'/categories.json', json_encode(['categories' => [
            ['key' => 'news', 'translations' => ['en' => ['name' => 'News', 'slug' => 'news'], 'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc']]],
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
                    'vi' => ['title' => 'Xin chào TN CMS', 'slug' => 'xin-chao-tncms', 'excerpt' => 'Chào bạn', 'content' => '<p>Xin chào</p>'],
                ],
            ],
        ]]));
    }
}
