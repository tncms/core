<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Admin\Resources\PageResource\Pages\EditPage;
use App\Filament\Admin\Resources\PageResource\Pages\ListPages;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Filament\Admin\Resources\PostResource\Pages\ListPosts;
use App\Filament\Admin\Resources\TagResource\Pages\EditTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1, Track A admin surface: the per-item
 * "Search engine indexing" control on Post, Page, Category and Tag, its Livewire
 * validation (a forged value never persists) and the established permissions
 * (publish rule for published content, taxonomy.manage for terms).
 */
final class PerItemRobotsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        // An installed site stores the active theme (CmsSettingsSeeder); the Page form reads its templates.
        app('cms.settings')->set('theme.active', 'default');
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder::class);
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        return $admin;
    }

    private function withRole(string $slug): User
    {
        $user = User::factory()->create();
        Role::query()->where('slug', $slug)->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();
        $this->actingAs($user);

        return $user;
    }

    private function content(string $type, string $status = 'published', ?int $authorId = null): Content
    {
        $title = ucfirst($type).' '.Str::random(6);

        return app('cms.content')->create([
            'type' => $type, 'status' => $status, 'locale' => 'vi', 'title' => $title,
            'slug' => Str::slug($title), 'content' => '<p>x</p>', 'author_id' => $authorId,
        ], ContentFixtures::context());
    }

    private function term(string $type): Term
    {
        $name = ucfirst($type).' '.Str::random(6);

        return app('cms.taxonomy')->createTerm($type, ['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name)]);
    }

    public function test_post_and_page_save_the_policy_and_reload_it(): void
    {
        $this->superAdmin();

        foreach ([EditPost::class => $this->content('post'), EditPage::class => $this->content('page')] as $page => $record) {
            Livewire::test($page, ['record' => $record->getRouteKey()])
                ->assertSet('data.seo_robots', null)
                ->fillForm(['seo_robots' => 'noindex'])
                ->call('save')
                ->assertHasNoFormErrors();
            $this->assertSame('noindex', $record->fresh()->seo_robots, $page);

            Livewire::test($page, ['record' => $record->getRouteKey()])
                ->assertSet('data.seo_robots', 'noindex')
                ->fillForm(['seo_robots' => null])
                ->call('save')
                ->assertHasNoFormErrors();
            $this->assertNull($record->fresh()->seo_robots, $page.' reset to inherit');
        }
    }

    public function test_category_and_tag_save_the_policy(): void
    {
        $this->superAdmin();

        foreach ([EditCategory::class => $this->term('category'), EditTag::class => $this->term('tag')] as $page => $term) {
            Livewire::test($page, ['record' => $term->getRouteKey()])
                ->fillForm(['seo_robots' => 'noindex,nofollow'])
                ->call('save')
                ->assertHasNoFormErrors();
            $this->assertSame('noindex,nofollow', $term->fresh()->seo_robots, $page);
        }
    }

    public function test_a_forged_livewire_value_is_rejected_and_never_stored(): void
    {
        $this->superAdmin();
        $post = $this->content('post');
        $category = $this->term('category');

        foreach (['index', 'all', 'noindex,follow', '<b>x</b>'] as $forged) {
            Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
                ->set('data.seo_robots', $forged)
                ->call('save')
                ->assertHasFormErrors(['seo_robots']);

            Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
                ->set('data.seo_robots', $forged)
                ->call('save')
                ->assertHasFormErrors(['seo_robots']);
        }

        $this->assertNull($post->fresh()->seo_robots);
        $this->assertNull($category->fresh()->seo_robots);
    }

    public function test_an_author_cannot_change_the_policy_of_published_content(): void
    {
        $author = $this->withRole('author');
        $published = $this->content('post', 'published', $author->id);
        $draft = $this->content('post', 'draft', $author->id);

        try {
            app('cms.content')->update($published, ['seo_robots' => 'noindex'], app('cms.content')->revisionOf($published), ContentWriteContext::interactive());
            $this->fail('an author changed a published item\'s robots policy');
        } catch (ContentWriteDenied) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($published->fresh()->seo_robots);

        // The same editorial rule as every other base field: allowed on their own draft.
        app('cms.content')->update($draft, ['seo_robots' => 'noindex'], app('cms.content')->revisionOf($draft), ContentWriteContext::interactive());
        $this->assertSame('noindex', $draft->fresh()->seo_robots);
    }

    public function test_an_author_cannot_reach_another_users_content_or_terms(): void
    {
        $other = User::factory()->create();
        $foreign = $this->content('post', 'draft', $other->id);
        $category = $this->term('category');
        $this->withRole('author');

        try {
            app('cms.content')->update($foreign, ['seo_robots' => 'noindex'], app('cms.content')->revisionOf($foreign), ContentWriteContext::interactive());
            $this->fail('an author changed another user\'s robots policy');
        } catch (ContentWriteDenied) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($foreign->fresh()->seo_robots);

        $this->get('/admin/categories/'.$category->id.'/edit')->assertForbidden();
        $this->assertNull($category->fresh()->seo_robots);
    }

    /** @return list<string> every loc across the sitemap index children */
    private function sitemapLocs(): array
    {
        $locs = [];
        preg_match_all('#<loc>([^<]*)</loc>#', (string) $this->get('/sitemap.xml')->assertOk()->getContent(), $children);
        foreach ($children[1] as $child) {
            preg_match_all('#<loc>([^<]*)</loc>#', $this->get((string) parse_url($child, PHP_URL_PATH))->streamedContent(), $m);
            array_push($locs, ...$m[1]);
        }

        return $locs;
    }

    public function test_bulk_status_changes_keep_the_policy_and_never_list_a_noindex_item(): void
    {
        $this->superAdmin();
        $hidden = $this->content('post', 'draft');
        app('cms.content')->update($hidden, ['seo_robots' => 'noindex'], null, ContentFixtures::context());
        $open = $this->content('post', 'draft');
        $page = $this->content('page', 'draft');
        app('cms.content')->update($page, ['seo_robots' => 'nofollow'], null, ContentFixtures::context());

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$hidden->id, $open->id], ['status' => 'published'])
            ->assertHasNoTableBulkActionErrors();
        Livewire::test(ListPages::class)
            ->callTableBulkAction('changeStatus', [$page->id], ['status' => 'published'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(['published', 'noindex'], [$hidden->fresh()->status, $hidden->fresh()->seo_robots], 'bulk publish keeps the policy');
        $this->assertSame(['published', 'nofollow'], [$page->fresh()->status, $page->fresh()->seo_robots]);
        $locs = $this->sitemapLocs();
        $this->assertNotContains(url(content_url($hidden->fresh(), 'vi')), $locs, 'a bulk-published noindex item is never listed');
        $this->assertContains(url(content_url($open->fresh(), 'vi')), $locs);
        $this->assertContains(url(content_url($page->fresh(), 'vi')), $locs, 'nofollow stays listed');

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$hidden->id, $open->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame('noindex', $hidden->fresh()->seo_robots, 'bulk unpublish keeps the policy');
        $this->assertNotContains(url(content_url($open->fresh(), 'vi')), $this->sitemapLocs(), 'bulk unpublish leaves the sitemap');
    }

    public function test_bulk_delete_removes_a_noindex_item_without_touching_others(): void
    {
        $this->superAdmin();
        $gone = $this->content('post');
        app('cms.content')->update($gone, ['seo_robots' => 'noindex'], null, ContentFixtures::context());
        $kept = $this->content('post');
        app('cms.content')->update($kept, ['seo_robots' => 'nofollow'], null, ContentFixtures::context());

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('deleteContent', [$gone->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertNull(Content::query()->find($gone->id));
        $this->assertSame('nofollow', $kept->fresh()->seo_robots);
        $this->assertContains(url(content_url($kept->fresh(), 'vi')), $this->sitemapLocs());
    }
}
