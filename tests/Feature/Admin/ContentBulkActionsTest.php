<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\PageResource\Pages\ListPages;
use App\Filament\Admin\Resources\PostResource\Pages\ListPosts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Services\ContentManager;

/**
 * CORE-CONTENT-ADMIN-UX-1 — Posts/Pages bulk status + delete actions and the
 * toggleable featured-image column.
 *
 * The suite drives the real Filament Livewire list components (not just the
 * service), proving per-record authorization, content/visibility preservation,
 * lifecycle-faithful deletion, the mandatory two-posts→Draft scenario, and the
 * featured-image column's toggle + query-count (N+1) behaviour.
 */
final class ContentBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // vi (default) + en, exactly like an installed site.
        $this->seed(CmsLanguageSeeder::class);

        // A plain authenticated user: RBAC fails open until roles are seeded, so
        // this user is authorized (matches the existing admin test baseline).
        $this->actingAs(User::factory()->create());
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    /**
     * A published post with EN+VI translations, a slug, a featured image and an
     * author, so content-preservation can be asserted field by field.
     */
    private function makePost(string $marker, string $status = 'published'): Content
    {
        $author = User::factory()->create();

        $post = $this->contents()->create([
            'type' => 'post', 'status' => $status, 'locale' => 'en',
            'title' => "Post $marker EN", 'slug' => "post-$marker-en",
            'excerpt' => "Excerpt $marker EN",
            'content' => "<p>Body $marker EN</p>",
            'meta_title' => "Meta $marker",
            'featured_image' => "https://cdn.example.test/$marker.jpg",
            'author_id' => $author->id,
        ]);

        return $this->contents()->update($post, [
            'locale' => 'vi', 'title' => "Post $marker VI", 'slug' => "post-$marker-vi",
            'excerpt' => "Excerpt $marker VI",
            'content' => "<p>Body $marker VI</p>",
        ]);
    }

    private function makePage(string $marker, string $status = 'published'): Content
    {
        return $this->contents()->create([
            'type' => 'page', 'status' => $status, 'locale' => 'en',
            'title' => "Page $marker", 'slug' => "page-$marker",
            'content' => "<p>Page body $marker</p>",
        ]);
    }

    // ---------------------------------------------------------------------
    // Registration + column presence
    // ---------------------------------------------------------------------

    public function test_posts_table_registers_bulk_actions_and_featured_image_column(): void
    {
        Livewire::test(ListPosts::class)
            ->assertTableBulkActionExists('changeStatus')
            ->assertTableBulkActionExists('deleteContent')
            ->assertTableColumnExists('featured_image');
    }

    public function test_pages_table_registers_bulk_actions_and_featured_image_column(): void
    {
        Livewire::test(ListPages::class)
            ->assertTableBulkActionExists('changeStatus')
            ->assertTableBulkActionExists('deleteContent')
            ->assertTableColumnExists('featured_image');
    }

    public function test_featured_image_column_is_toggleable_like_slug(): void
    {
        $table = Livewire::test(ListPosts::class)->instance()->getTable();

        $image = $table->getColumn('featured_image');
        $slug = $table->getColumn('slug');

        $this->assertNotNull($image);
        $this->assertNotNull($slug);
        // Same toggle mechanism as slug; both visible by default (documented default).
        $this->assertTrue($image->isToggleable());
        $this->assertTrue($slug->isToggleable());
        $this->assertFalse($image->isToggledHiddenByDefault());
        $this->assertFalse($slug->isToggledHiddenByDefault());
    }

    // ---------------------------------------------------------------------
    // Mandatory scenario: TWO published posts -> Draft
    // ---------------------------------------------------------------------

    public function test_bulk_change_two_published_posts_to_draft_preserves_content_and_updates_public_visibility(): void
    {
        $a = $this->makePost('a');
        $b = $this->makePost('b');
        $c = $this->makePost('c'); // NOT selected

        // Snapshot everything that must survive a status-only change on post A.
        $enBefore = $a->translations()->where('locale', 'en')->first()->toArray();
        $viBefore = $a->translations()->where('locale', 'vi')->first()->toArray();
        $slugsBefore = Slug::query()->where('reference_type', 'content')->where('reference_id', $a->id)
            ->orderBy('locale')->get(['locale', 'slug', 'full_path'])->toArray();
        $featuredBefore = $a->featured_image;
        $authorBefore = $a->author_id;
        $publishedAtBefore = $a->published_at?->toDateTimeString();

        $cacheVersionBefore = app('cms.public_cache')->version();

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$a->id, $b->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        // Both selected posts are now draft; the unselected one is untouched.
        $this->assertSame('draft', $a->fresh()->status);
        $this->assertSame('draft', $b->fresh()->status);
        $this->assertSame('published', $c->fresh()->status);

        // Public visibility contract: every public listing/archive/route gates on
        // status === 'published' (FrontendController + scopePublished), so the two
        // drafted posts drop out of the published set while C remains.
        $publishedIds = Content::query()->where('type', 'post')->where('status', 'published')->pluck('id')->all();
        $this->assertNotContains($a->id, $publishedIds);
        $this->assertNotContains($b->id, $publishedIds);
        $this->assertContains($c->id, $publishedIds);

        // The save path bumped the public-cache epoch (no stale resolution served).
        $this->assertGreaterThan($cacheVersionBefore, app('cms.public_cache')->version());

        // Content, translations, slug, SEO, featured media and ownership preserved.
        $a->refresh();
        $enAfter = $a->translations()->where('locale', 'en')->first()->toArray();
        $viAfter = $a->translations()->where('locale', 'vi')->first()->toArray();
        $slugsAfter = Slug::query()->where('reference_type', 'content')->where('reference_id', $a->id)
            ->orderBy('locale')->get(['locale', 'slug', 'full_path'])->toArray();

        $this->assertSame($enBefore['title'], $enAfter['title']);
        $this->assertSame($enBefore['slug'], $enAfter['slug']);
        $this->assertSame($enBefore['content'], $enAfter['content']);
        $this->assertSame($enBefore['meta_title'], $enAfter['meta_title']);
        $this->assertSame($viBefore['title'], $viAfter['title']);
        $this->assertSame($viBefore['content'], $viAfter['content']);
        $this->assertSame($slugsBefore, $slugsAfter);
        $this->assertSame($featuredBefore, $a->featured_image);
        $this->assertSame($authorBefore, $a->author_id);
        // published->draft preserves the original publish timestamp.
        $this->assertSame($publishedAtBefore, $a->published_at?->toDateTimeString());
    }

    // ---------------------------------------------------------------------
    // Publish / draft transitions, idempotency, timestamps
    // ---------------------------------------------------------------------

    public function test_bulk_publish_sets_published_at_on_first_publish(): void
    {
        $draft = $this->makePost('draft', 'draft');
        $this->assertNull($draft->published_at);

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$draft->id], ['status' => 'published'])
            ->assertHasNoTableBulkActionErrors();

        $draft->refresh();
        $this->assertSame('published', $draft->status);
        $this->assertNotNull($draft->published_at); // stamped by the shared resolvePublishedAt rule
    }

    public function test_bulk_change_to_same_status_is_idempotent_and_does_not_rewrite(): void
    {
        $post = $this->makePost('idem', 'draft');
        $updatedAtBefore = $post->translations()->where('locale', 'en')->first()->updated_at->toDateTimeString();

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$post->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        $post->refresh();
        $this->assertSame('draft', $post->status);
        // Already at target → skipped, so the translation row was NOT re-saved.
        $this->assertSame(
            $updatedAtBefore,
            $post->translations()->where('locale', 'en')->first()->updated_at->toDateTimeString(),
        );
    }

    public function test_invalid_target_status_is_rejected(): void
    {
        $post = $this->makePost('inv');

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$post->id], ['status' => 'archived']);

        $this->assertSame('published', $post->fresh()->status); // untouched
    }

    // ---------------------------------------------------------------------
    // Deletion lifecycle
    // ---------------------------------------------------------------------

    public function test_bulk_delete_soft_deletes_and_removes_slugs(): void
    {
        $a = $this->makePost('del-a');
        $b = $this->makePost('del-b');
        $keep = $this->makePost('del-keep');

        $this->assertDatabaseHas('cms_slugs', ['reference_type' => 'content', 'reference_id' => $a->id]);

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('deleteContent', [$a->id, $b->id])
            ->assertHasNoTableBulkActionErrors();

        // Soft-deleted (ContentManager::delete), lifecycle-faithful.
        $this->assertSoftDeleted('cms_contents', ['id' => $a->id]);
        $this->assertSoftDeleted('cms_contents', ['id' => $b->id]);
        $this->assertNull(Content::query()->find($keep->id)?->deleted_at);

        // Slug rows cleaned by the domain authority.
        $this->assertDatabaseMissing('cms_slugs', ['reference_type' => 'content', 'reference_id' => $a->id]);
        $this->assertDatabaseMissing('cms_slugs', ['reference_type' => 'content', 'reference_id' => $b->id]);
    }

    public function test_bulk_delete_fires_content_deleted_lifecycle_hook(): void
    {
        $post = $this->makePost('hook');
        $fired = [];
        add_action('cms.content.deleted', function (Content $c) use (&$fired): void {
            $fired[] = $c->id;
        });

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('deleteContent', [$post->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertContains($post->id, $fired);
    }

    // ---------------------------------------------------------------------
    // Authorization (per-record, execution-time)
    // ---------------------------------------------------------------------

    public function test_unauthorized_user_cannot_bulk_change_status_via_ui_or_direct_call(): void
    {
        $this->seedDeniedUser();

        // The toolbar button visibility and the per-record execution gate share
        // the same cms_can check, so an unauthorized user fails both layers.
        $this->assertFalse(cms_can('posts.edit'));
        $this->assertFalse(cms_can('posts.delete'));

        $a = $this->makePost('authz-a');
        $b = $this->makePost('authz-b');

        // Even a direct Livewire invocation must not mutate (execution-time gate).
        try {
            Livewire::test(ListPosts::class)
                ->callTableBulkAction('changeStatus', [$a->id, $b->id], ['status' => 'draft']);
        } catch (\Throwable) {
            // A hidden action refusing to run is also acceptable protection.
        }

        $this->assertSame('published', $a->fresh()->status);
        $this->assertSame('published', $b->fresh()->status);
    }

    public function test_unauthorized_user_cannot_bulk_delete(): void
    {
        $this->seedDeniedUser();
        $post = $this->makePost('authz-del');

        try {
            Livewire::test(ListPosts::class)
                ->callTableBulkAction('deleteContent', [$post->id]);
        } catch (\Throwable) {
            // hidden action refused — acceptable.
        }

        $this->assertNull($post->fresh()->deleted_at);
    }

    // ---------------------------------------------------------------------
    // Robustness: stale / mixed selections
    // ---------------------------------------------------------------------

    public function test_stale_and_valid_selection_processes_valid_records_only(): void
    {
        $valid = $this->makePost('stale-valid');
        $ghostId = $valid->id + 9999; // never existed

        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$valid->id, $ghostId], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame('draft', $valid->fresh()->status);
        // Ghost id simply resolves to nothing; no crash, no phantom row.
        $this->assertNull(Content::query()->withTrashed()->find($ghostId));
    }

    // ---------------------------------------------------------------------
    // Pages: same actions, no invented homepage veto
    // ---------------------------------------------------------------------

    public function test_pages_bulk_change_and_delete_work(): void
    {
        $p1 = $this->makePage('p1');
        $p2 = $this->makePage('p2');

        Livewire::test(ListPages::class)
            ->callTableBulkAction('changeStatus', [$p1->id, $p2->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame('draft', $p1->fresh()->status);
        $this->assertSame('draft', $p2->fresh()->status);
    }

    public function test_page_referenced_as_homepage_has_no_special_delete_veto(): void
    {
        // Core has no homepage/system-page delete veto on the single-record path,
        // so none is invented for bulk: a homepage-referenced page deletes exactly
        // like any other page (soft delete), matching established behaviour.
        $home = $this->makePage('home');
        app('cms.settings')->set('reading.homepage_page_id', (string) $home->id);

        Livewire::test(ListPages::class)
            ->callTableBulkAction('deleteContent', [$home->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSoftDeleted('cms_contents', ['id' => $home->id]);
    }

    // ---------------------------------------------------------------------
    // Featured-image column: no N+1
    // ---------------------------------------------------------------------

    public function test_featured_image_column_adds_no_per_row_media_queries(): void
    {
        foreach (range(1, 6) as $i) {
            $this->makePost("nplus1-$i");
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListPosts::class)->assertTableColumnExists('featured_image');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // featured_image is a column on cms_contents (already selected with the
        // row), so rendering the thumbnail issues ZERO cms_media queries.
        $mediaQueries = array_filter(
            $queries,
            static fn (array $q): bool => str_contains((string) $q['query'], 'cms_media'),
        );
        $this->assertCount(0, $mediaQueries, 'Featured-image column must not query cms_media per row.');
    }

    // ---------------------------------------------------------------------
    // Equivalence: changeStatus() vs single-record update()
    // ---------------------------------------------------------------------

    public function test_change_status_matches_single_record_update_status_outcome(): void
    {
        // Two identical published posts; transition one via the narrow
        // changeStatus() authority and the other via a full update() save that
        // only changes status (what the edit form does single-record).
        $viaChange = $this->makePost('eq-change');
        $viaUpdate = $this->makePost('eq-update');

        $enBefore = $viaChange->translations()->where('locale', 'en')->first()->toArray();

        app('cms.content')->changeStatus($viaChange, 'draft');

        app('cms.content')->update($viaUpdate, [
            'locale' => 'en',
            'status' => 'draft',
            'title' => 'Post eq-update EN',
            'slug' => 'post-eq-update-en',
            'excerpt' => 'Excerpt eq-update EN',
            'content' => '<p>Body eq-update EN</p>',
        ]);

        $viaChange->refresh();
        $viaUpdate->refresh();

        // Identical status + published_at outcome (shared resolvePublishedAt rule).
        $this->assertSame('draft', $viaChange->status);
        $this->assertSame('draft', $viaUpdate->status);
        $this->assertSame(
            $viaUpdate->published_at?->toDateTimeString(),
            $viaChange->published_at?->toDateTimeString(),
        );

        // changeStatus() leaves the translation row byte-identical (update() would
        // re-persist it); observable content is equivalent either way.
        $enAfter = $viaChange->translations()->where('locale', 'en')->first()->toArray();
        $this->assertSame($enBefore['title'], $enAfter['title']);
        $this->assertSame($enBefore['slug'], $enAfter['slug']);
        $this->assertSame($enBefore['content'], $enAfter['content']);
        $this->assertSame($enBefore['updated_at'], $enAfter['updated_at']);
    }

    // ---------------------------------------------------------------------
    // Localization
    // ---------------------------------------------------------------------

    public function test_status_options_are_localized_in_vietnamese(): void
    {
        // The status keys the options are built from resolve per locale.
        $this->assertSame('Draft', tn_trans('Draft', [], 'en'));
        $this->assertSame('Bản nháp', tn_trans('Draft', [], 'vi'));
        $this->assertSame('Đã xuất bản', tn_trans('Published', [], 'vi'));

        // And the options method surfaces localized values (current locale = vi default).
        $viOptions = ListPosts::getResource()::contentStatusOptions();
        $this->assertSame('Bản nháp', $viOptions['draft']);
        $this->assertSame('Đã xuất bản', $viOptions['published']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Initialise RBAC (so cms_can stops failing open) and act as a user with a
     * role that grants neither posts.* nor pages.* and is not super-admin.
     */
    private function seedDeniedUser(): void
    {
        config(['cms.super_admin_emails' => []]);
        $this->seed(CmsRolePermissionSeeder::class);

        $role = Role::create(['name' => 'No Access', 'slug' => 'no-access']);
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        $this->actingAs($user);
    }
}
