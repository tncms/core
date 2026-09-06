<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Filament\Admin\Pages\ImportDemoPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * EG-9 Phase 5E — active-theme demo authority with EG-9 (category/tag/post) data.
 * Only the COMMITTED active theme's preset can be applied; an inactive theme's
 * preset is rejected SERVER-SIDE by the import action (not merely UI-hidden).
 * Authority is settings/ThemeManager (activeSlug), never config('cms.theme.active').
 */
final class ThemeDemoActiveThemeScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $themes = ['eg9a', 'eg9b'];

    protected function setUp(): void
    {
        parent::setUp();
        app(TaxonomyManager::class)->ensureCoreTaxonomies();
        foreach ($this->themes as $slug) {
            $this->makeThemeWithEg9Demo($slug);
        }
        // Act as a super-admin (bypasses the themes.import permission gate).
        $role = Role::query()->firstOrCreate(['slug' => Role::SUPER_ADMIN], ['name' => 'Super Admin']);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'a@ex.test', 'password' => 'password']);
        $role->users()->syncWithoutDetaching([$user->getKey()]);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->themes as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }
        parent::tearDown();
    }

    private function prov(string $theme): mixed
    {
        return settings()->get('demo.imports.'.$theme.'.starter');
    }

    public function test_only_committed_active_theme_eg9_preset_is_importable(): void
    {
        app(ThemeManager::class)->activate('eg9a');

        // Authority is the committed setting / ThemeManager — not config/env.
        $this->assertSame('eg9a', app(ThemeManager::class)->activeSlug());
        $this->assertSame('eg9a', (string) settings()->get('theme.active'));

        $page = new ImportDemoPage;

        // Inactive theme B apply is rejected server-side (no provenance written).
        $page->importPackage('theme:eg9b:starter');
        $this->assertNull($this->prov('eg9b'), 'inactive-theme EG-9 preset must be rejected server-side');

        // Active theme A apply is allowed.
        $page->importPackage('theme:eg9a:starter');
        $this->assertIsArray($this->prov('eg9a'), 'active-theme EG-9 preset must import');
        $this->assertArrayHasKey('post:hello', $this->prov('eg9a')['imported_keys']);

        // Switch committed authority to B legitimately; authority follows.
        app(ThemeManager::class)->activate('eg9b');
        $this->assertSame('eg9b', app(ThemeManager::class)->activeSlug());
        $this->assertSame('eg9b', (string) settings()->get('theme.active'));

        $page = new ImportDemoPage;

        // Now A is rejected and B is allowed.
        $countBefore = $this->prov('eg9a');
        $page->importPackage('theme:eg9a:starter'); // A now inactive → still only prior provenance, no re-import mutation expected
        $this->assertEquals($countBefore, $this->prov('eg9a'), 'A (now inactive) apply must be rejected server-side');

        $page->importPackage('theme:eg9b:starter');
        $this->assertIsArray($this->prov('eg9b'), 'B (now active) EG-9 preset must import');
    }

    private function makeThemeWithEg9Demo(string $slug): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/theme.json', json_encode(['name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't']));
        File::put($dir.'/views/layouts/master.blade.php', '@yield("content")');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');

        // A super-admin exists (setUp) so first_super_admin resolves for both.
        $demo = $dir.'/demo/starter';
        File::ensureDirectoryExists($demo);
        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => $slug, 'slug' => 'starter', 'name' => strtoupper($slug), 'description' => 'x', 'version' => '1.0.0',
            'files' => ['categories' => 'categories.json', 'tags' => 'tags.json', 'posts' => 'posts.json'],
        ]));
        File::put($demo.'/categories.json', json_encode(['categories' => [
            ['key' => 'news', 'translations' => ['vi' => ['name' => 'Tin', 'slug' => $slug.'-tin'], 'en' => ['name' => 'News', 'slug' => $slug.'-news']]],
        ]]));
        File::put($demo.'/tags.json', json_encode(['tags' => [
            ['key' => 't', 'translations' => ['vi' => ['name' => 'T', 'slug' => $slug.'-t']]],
        ]]));
        File::put($demo.'/posts.json', json_encode(['posts' => [
            [
                'key' => 'hello', 'status' => 'published', 'author' => 'first_super_admin',
                'categories' => ['category:news'], 'tags' => ['tag:t'],
                'translations' => ['vi' => ['title' => 'Xin chao '.$slug, 'slug' => $slug.'-xin-chao', 'content' => '<p>x</p>']],
            ],
        ]]));
    }
}
