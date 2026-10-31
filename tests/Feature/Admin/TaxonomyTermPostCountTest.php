<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Admin\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Admin\Resources\TagResource\Pages\ListTags;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\ContentManager;

/**
 * CORE-39-FINAL-BUGFIX: the Categories/Tags admin "Posts" column.
 *
 * The column used to render the dormant cms_terms.count column, which Core
 * only ever writes as 0, so a category with a published post showed "0"
 * (reproduced on ketqua.demo: term "tin-tuc" with post 2 assigned → 0).
 *
 * Contract (the documented Core "published-post count", the same rows the
 * public term archive lists before locale discoverability): distinct,
 * non-trashed contents of the taxonomy's content type, status "published",
 * directly assigned to the term. Locale-independent; children not rolled up.
 *
 * Every assertion drives the real Filament list component.
 */
final class TaxonomyTermPostCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();

        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    private function category(string $name, ?Term $parent = null): Term
    {
        return app('cms.taxonomy')->createTerm('category', array_filter([
            'locale' => 'vi', 'name' => $name, 'parent_id' => $parent?->id,
        ], static fn ($v) => $v !== null));
    }

    private function tag(string $name): Term
    {
        return app('cms.taxonomy')->createTerm('tag', ['locale' => 'vi', 'name' => $name]);
    }

    /** @param list<Term> $terms */
    private function makePost(string $marker, array $terms, string $status = 'published', string $type = 'post'): Content
    {
        return $this->contents()->create([
            'type' => $type, 'status' => $status, 'locale' => 'vi',
            'title' => "Bài $marker", 'slug' => "bai-$marker",
            'content' => "<p>$marker</p>",
            'term_ids' => array_map(static fn (Term $t): int => (int) $t->id, $terms),
        ], ContentFixtures::context());
    }

    /** @param list<Term> $terms */
    private function retag(Content $post, array $terms): void
    {
        $this->contents()->update($post->refresh(), [
            'term_ids' => array_map(static fn (Term $t): int => (int) $t->id, $terms),
        ], null, ContentFixtures::context());
    }

    private function categories()
    {
        return Livewire::test(ListCategories::class);
    }

    // ── the reported defect ──────────────────────────────────────────────

    public function test_category_with_one_published_post_shows_one(): void
    {
        $news = $this->category('Tin tức');
        $this->makePost('dau-tien', [$news]);

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $news);
    }

    public function test_reproduction_shape_seeded_pivot_with_dormant_count_zero(): void
    {
        // ketqua.demo shape: the pivot row exists while cms_terms.count = 0.
        $news = $this->category('Tin tức');
        $post = $this->makePost('seeded', []);
        DB::table('cms_content_terms')->insert(['content_id' => $post->id, 'term_id' => $news->id]);
        $this->assertSame(0, (int) DB::table('cms_terms')->where('id', $news->id)->value('count'));

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $news);
    }

    // ── cardinality ──────────────────────────────────────────────────────

    public function test_zero_one_and_multiple_posts(): void
    {
        $empty = $this->category('Rỗng');
        $one = $this->category('Một');
        $many = $this->category('Nhiều');
        $this->makePost('a', [$one]);
        $this->makePost('b', [$many]);
        $this->makePost('c', [$many]);
        $this->makePost('d', [$many]);

        $this->categories()
            ->assertTableColumnStateSet('posts_count', 0, $empty)
            ->assertTableColumnStateSet('posts_count', 1, $one)
            ->assertTableColumnStateSet('posts_count', 3, $many);
    }

    public function test_post_in_multiple_categories_counts_once_in_each(): void
    {
        $a = $this->category('A');
        $b = $this->category('B');
        $this->makePost('shared', [$a, $b]);

        $this->categories()
            ->assertTableColumnStateSet('posts_count', 1, $a)
            ->assertTableColumnStateSet('posts_count', 1, $b);
    }

    public function test_moving_and_removing_a_post_updates_counts_without_stale_values(): void
    {
        $from = $this->category('Nguồn');
        $to = $this->category('Đích');
        $other = $this->category('Khác');
        $this->makePost('other', [$other]);
        $post = $this->makePost('moving', [$from]);

        $this->categories()
            ->assertTableColumnStateSet('posts_count', 1, $from)
            ->assertTableColumnStateSet('posts_count', 0, $to);

        $this->retag($post, [$to]);
        $this->categories()
            ->assertTableColumnStateSet('posts_count', 0, $from)
            ->assertTableColumnStateSet('posts_count', 1, $to)
            ->assertTableColumnStateSet('posts_count', 1, $other);

        $this->retag($post, []);
        $this->categories()
            ->assertTableColumnStateSet('posts_count', 0, $to)
            ->assertTableColumnStateSet('posts_count', 1, $other);
    }

    // ── status, type, trash, ownership ───────────────────────────────────

    public function test_only_published_posts_are_counted(): void
    {
        $cat = $this->category('Trạng thái');
        $this->makePost('pub', [$cat]);
        $draft = $this->makePost('draft', [$cat], 'draft');
        $this->makePost('pending', [$cat], 'pending');

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $cat);

        $this->contents()->update($draft->refresh(), ['status' => 'published'], null, ContentFixtures::context());
        $this->categories()->assertTableColumnStateSet('posts_count', 2, $cat);
    }

    public function test_pages_attached_through_the_pivot_are_not_counted(): void
    {
        $cat = $this->category('Chỉ bài viết');
        $this->makePost('real', [$cat]);
        $page = $this->makePost('page', [], 'published', 'page');
        DB::table('cms_content_terms')->insert(['content_id' => $page->id, 'term_id' => $cat->id]);

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $cat);
    }

    public function test_trashed_posts_are_excluded_and_restored_posts_return(): void
    {
        $cat = $this->category('Thùng rác');
        $this->makePost('keep', [$cat]);
        $gone = $this->makePost('gone', [$cat]);

        $this->contents()->delete($gone->refresh(), null, ContentFixtures::context());
        $this->assertSoftDeleted('cms_contents', ['id' => $gone->id]);
        $this->categories()->assertTableColumnStateSet('posts_count', 1, $cat);

        Content::withTrashed()->findOrFail($gone->id)->restore();
        $this->categories()->assertTableColumnStateSet('posts_count', 2, $cat);
    }

    public function test_extension_managed_post_of_an_inactive_owner_is_still_an_assigned_post(): void
    {
        // The admin Posts list shows managed posts regardless of the owner's
        // state, so the admin count does too; public discoverability is a
        // locale/request-time rule that stays with the archive.
        $cat = $this->category('Quản lý');
        $managed = $this->makePost('managed', [$cat]);
        DB::table('cms_contents')->where('id', $managed->id)->update(['managed_by' => 'inactive-ghost-plugin']);

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $cat);
    }

    // ── locale and hierarchy ─────────────────────────────────────────────

    public function test_multilingual_post_counts_once_in_every_viewing_locale(): void
    {
        $cat = $this->category('Đa ngôn ngữ');
        $post = $this->makePost('ml', [$cat]);
        $this->contents()->update($post->refresh(), [
            'locale' => 'en', 'title' => 'Multilingual', 'slug' => 'multilingual', 'content' => '<p>en</p>',
        ], null, ContentFixtures::context());
        $this->assertSame(2, DB::table('cms_content_translations')->where('content_id', $post->id)->count());

        Livewire::withQueryParams(['locale' => 'vi'])->test(ListCategories::class)
            ->assertTableColumnStateSet('posts_count', 1, $cat);
        Livewire::withQueryParams(['locale' => 'en'])->test(ListCategories::class)
            ->assertTableColumnStateSet('posts_count', 1, $cat);
    }

    public function test_parent_and_child_counts_are_direct_assignments_only(): void
    {
        $parent = $this->category('Cha');
        $child = $this->category('Con', $parent);
        $this->makePost('p', [$parent]);
        $this->makePost('c1', [$child]);
        $this->makePost('c2', [$child]);

        $this->categories()
            ->assertTableColumnStateSet('posts_count', 1, $parent)
            ->assertTableColumnStateSet('posts_count', 2, $child);
    }

    // ── table behaviour ──────────────────────────────────────────────────

    public function test_sort_by_posts_search_and_pagination(): void
    {
        $low = $this->category('Thấp');
        $high = $this->category('Cao');
        $mid = $this->category('Giữa');
        $this->makePost('h1', [$high]);
        $this->makePost('h2', [$high]);
        $this->makePost('h3', [$high]);
        $this->makePost('m1', [$mid]);

        $this->categories()
            ->sortTable('posts_count')
            ->assertCanSeeTableRecords([$low, $mid, $high], inOrder: true)
            ->sortTable('posts_count', 'desc')
            ->assertCanSeeTableRecords([$high, $mid, $low], inOrder: true)
            ->searchTable('Cao')
            ->assertCanSeeTableRecords([$high])
            ->assertCanNotSeeTableRecords([$low, $mid])
            ->assertTableColumnStateSet('posts_count', 3, $high);

        foreach (range(1, 12) as $i) {
            $this->category("Trang $i");
        }

        $this->categories()
            ->set('tableRecordsPerPage', 5)
            ->call('gotoPage', 2, 'page')
            ->assertCountTableRecords(15);
    }

    public function test_counts_are_one_aggregate_query_not_n_plus_one(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makePost("q$i", [$this->category("Q$i")]);
        }

        DB::enableQueryLog();
        $this->categories();
        $termCounts = collect(DB::getQueryLog())
            ->filter(static fn (array $q): bool => str_contains($q['query'], 'cms_content_terms'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $termCounts, 'post counts must come from one aggregate, not per row');
    }

    public function test_tags_use_the_same_count_contract(): void
    {
        $laravel = $this->tag('Laravel');
        $this->makePost('t1', [$laravel]);
        $this->makePost('t2', [$laravel], 'draft');

        Livewire::test(ListTags::class)->assertTableColumnStateSet('posts_count', 1, $laravel);
    }

    // ── authorization ────────────────────────────────────────────────────

    public function test_authorized_category_edit_keeps_the_count(): void
    {
        $cat = $this->category('Sửa');
        $this->makePost('e', [$cat]);

        Livewire::test(EditCategory::class, ['record' => $cat->getRouteKey()])
            ->fillForm(['name' => 'Đã sửa'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->categories()->assertTableColumnStateSet('posts_count', 1, $cat);
    }

    public function test_user_without_taxonomy_manage_cannot_list_or_edit_categories(): void
    {
        $cat = $this->category('Bảo vệ');
        $this->makePost('x', [$cat]);

        config(['cms.super_admin_emails' => []]);
        $this->seed(CmsRolePermissionSeeder::class);
        $role = Role::create(['name' => 'No Taxonomy', 'slug' => 'no-taxonomy']);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $this->actingAs($user);

        $this->get('/admin/categories')->assertForbidden();
        $this->get("/admin/categories/{$cat->id}/edit")->assertForbidden();
    }
}
