<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Filament\Admin\Resources\PageResource\Pages\ListPages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

/**
 * CORE 7.1.32 PRE-RELEASE INTEGRATION R1 — cross-feature coexistence.
 *
 * Proves the Content Admin UX bulk actions and the Theme Page Reference
 * resolver interoperate: a Page referenced by a Theme Option, bulk-drafted via
 * the Pages admin, immediately stops producing a public link; republishing
 * restores it without re-selecting; slug changes follow; bulk deletion preserves
 * the stored id while the public URL goes null. Both locale paths verified.
 */
final class ContentThemePageCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'int7132_fixture';

    private const KEY = 'privacy_policy_page';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $this->actingAs(User::query()->create([
            'name' => 'Admin', 'email' => 'int-admin@example.test', 'password' => bcrypt('secret-password'),
        ]));
        $this->activateFixture();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    private function makePrivacyPage(): Content
    {
        $page = $this->contents()->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Chính sách', 'slug' => 'chinh-sach', 'content' => '<p>vi</p>',
        ]);

        return $this->contents()->update($page, [
            'locale' => 'en', 'title' => 'Privacy', 'slug' => 'privacy', 'content' => '<p>en</p>',
        ]);
    }

    private function storedOptionId(): mixed
    {
        return app('cms.theme_option')->get(self::KEY, null, self::THEME);
    }

    public function test_bulk_draft_a_referenced_page_nulls_the_theme_link_then_republish_restores_it(): void
    {
        $page = $this->makePrivacyPage();
        $other = $this->contents()->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Khác', 'slug' => 'khac', 'content' => '<p>x</p>',
        ]);
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);

        // Published → both locale links resolve.
        $this->assertStringContainsString('/chinh-sach', (string) theme_page_url(self::KEY, 'vi'));
        $this->assertStringContainsString('/en/privacy', (string) theme_page_url(self::KEY, 'en'));

        // Bulk-draft ONLY the referenced page via the Pages admin bulk action.
        Livewire::test(ListPages::class)
            ->callTableBulkAction('changeStatus', [$page->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame('draft', $page->fresh()->status);
        $this->assertSame('published', $other->fresh()->status);

        // Public link is now null in BOTH locales; the stored option id is intact.
        $this->assertNull(theme_page_url(self::KEY, 'vi'));
        $this->assertNull(theme_page_url(self::KEY, 'en'));
        $this->assertEquals($page->id, $this->storedOptionId());

        // Republish via the same bulk action → link restored, no re-selection.
        Livewire::test(ListPages::class)
            ->callTableBulkAction('changeStatus', [$page->id], ['status' => 'published'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertStringContainsString('/chinh-sach', (string) theme_page_url(self::KEY, 'vi'));
        $this->assertEquals($page->id, $this->storedOptionId());
    }

    public function test_slug_change_of_referenced_page_updates_theme_link_without_reselection(): void
    {
        $page = $this->makePrivacyPage();
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);

        $this->contents()->update($page, ['locale' => 'vi', 'slug' => 'chinh-sach-moi']);

        $this->assertStringContainsString('/chinh-sach-moi', (string) theme_page_url(self::KEY, 'vi'));
        $this->assertEquals($page->id, $this->storedOptionId());
    }

    public function test_bulk_delete_referenced_page_preserves_option_id_but_nulls_url(): void
    {
        $page = $this->makePrivacyPage();
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);
        $this->assertNotNull(theme_page_url(self::KEY, 'vi'));

        Livewire::test(ListPages::class)
            ->callTableBulkAction('deleteContent', [$page->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('cms_contents', ['id' => $page->id]);
        $this->assertNull(theme_page_url(self::KEY, 'vi'));
        $this->assertNull(theme_page_url(self::KEY, 'en'));
        // The stable reference id is preserved — never dropped by the delete.
        $this->assertEquals($page->id, $this->storedOptionId());

        // Cross-feature nuance (documented): the bulk delete routes through
        // ContentManager::delete(), which ALSO removes the cms_slugs rows. So a
        // bare restore un-soft-deletes the record but leaves it without public
        // slugs → the link legitimately stays null after a bare restore. The
        // reversible recovery path for the theme link is the status lifecycle
        // (unpublish → republish), proven in
        // test_bulk_draft_a_referenced_page_nulls_the_theme_link_then_republish_restores_it,
        // which never touches slugs. Deletion is the terminal path.
        $page->restore();
        $this->assertNull(theme_page_url(self::KEY, 'vi'), 'Bulk delete cleans slugs; a bare restore does not resurrect the public URL.');
        $this->assertEquals($page->id, $this->storedOptionId());
    }

    private function activateFixture(): void
    {
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::ensureDirectoryExists($dir.'/assets/css');
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/assets/css/main.css', '/*int*/');
        File::put($dir.'/theme.json', json_encode([
            'name' => 'Int Fixture', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't',
            'assets' => [['handle' => 'int-css', 'src' => 'css/main.css', 'primary' => true]],
        ]));
        File::put($dir.'/theme.options.json', json_encode(['options' => ['sections' => [
            ['key' => 'advanced', 'group' => 'advanced', 'label' => 'Advanced', 'fields' => [
                ['key' => self::KEY, 'label' => 'Privacy Policy Page', 'type' => 'page'],
            ]],
        ]]]));
        app('cms.theme')->activate(self::THEME);
        $this->assertSame(self::THEME, app('cms.theme')->active()?->slug);
    }
}
