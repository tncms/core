<?php

declare(strict_types=1);

namespace Tests\Feature\Publish1;

use App\Filament\Admin\Resources\PostResource\Pages\ListPosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentRevisionConflict;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-PUBLISH-1 inherited findings P1 / P2 / P4 (CLI) / P5 / P6, plus the
 * scheduling permission rules, probed at the service boundary and the admin
 * bulk action on the current HEAD.
 *
 * Default roles (CmsRolePermissionSeeder): author = posts.view/create/edit;
 * editor = posts.* incl. publish/delete, no *_others.
 */
final class PublishingAuthorityProbeTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->seedSite();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ctx(): ContentWriteContext
    {
        return ContentWriteContext::interactive();
    }

    private function assertDenied(string $code, callable $write): void
    {
        try {
            $write();
        } catch (ContentWriteException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());

            return;
        }

        $this->fail("Expected a content write refusal [{$code}].");
    }

    /** @return array<string, mixed> */
    private function state(int $id): array
    {
        $row = Content::withTrashed()->with('translations')->findOrFail($id);

        return [
            'row' => $row->only(['status', 'author_id', 'revision']) + ['published_at' => $row->published_at?->toDateTimeString(), 'deleted_at' => $row->deleted_at?->toDateTimeString()],
            'translations' => $row->translations->map->only(['locale', 'title', 'content'])->sortBy('locale')->values()->all(),
        ];
    }

    // P1 — edit without publish never makes content public -----------------

    public function test_p1_author_cannot_publish_schedule_or_reschedule(): void
    {
        $this->actingAs($author = $this->userWithRole('author'));

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Scheduled by author', 'published_at' => now()->addDay(),
        ], $this->ctx()));

        $draft = $this->contents()->create(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Dated draft', 'published_at' => now()->addDay()], $this->ctx());
        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->changeStatus($draft, 'published', $draft->revision, $this->ctx()));
        $this->assertSame('draft', $draft->fresh()->status);

        // An editor scheduled the author's post; the author may not move the date.
        $scheduled = $this->makeContent(['title' => 'Own scheduled', 'status' => 'published', 'published_at' => now()->addDay()], $author);
        $before = $this->state($scheduled->id);
        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->update($scheduled, ['published_at' => now()->subMinute()], $scheduled->revision, $this->ctx()));
        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->update($scheduled, ['locale' => 'en', 'title' => 'Retitled'], $scheduled->revision, $this->ctx()));
        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->update($scheduled, ['locale' => 'vi', 'title' => 'Ban dich'], $scheduled->revision, $this->ctx()));
        $this->assertSame($before, $this->state($scheduled->id));
    }

    public function test_p1_decision_api_consumed_by_page_builder_refuses_publish_without_the_permission(): void
    {
        $author = $this->userWithRole('author');
        $own = $this->makeContent(['title' => 'Own draft'], $author);

        $this->assertTrue(app('cms.content.actions')->decision($author, ContentAction::Edit, $own)->allowed());
        $this->assertFalse(app('cms.content.actions')->decision($author, ContentAction::Publish, $own)->allowed());
    }

    public function test_p1_bulk_publish_by_author_publishes_nothing(): void
    {
        $author = $this->userWithRole('author');
        $a = $this->makeContent(['title' => 'Bulk A'], $author);
        $b = $this->makeContent(['title' => 'Bulk B'], $author);

        $this->actingAs($author);
        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$a->id, $b->id], ['status' => 'published', 'revisions' => json_encode([(string) $a->id => $a->revision, (string) $b->id => $b->revision])]);

        $this->assertSame(['draft', 'draft'], [$a->fresh()->status, $b->fresh()->status]);
    }

    // P2 — ownership --------------------------------------------------------

    public function test_p2_editor_without_edit_others_cannot_touch_another_authors_post(): void
    {
        $owner = $this->userWithRole('author');
        $foreign = $this->makeContent(['title' => 'Foreign', 'status' => 'published', 'published_at' => now()->subDay()], $owner);
        $draft = $this->makeContent(['title' => 'Foreign draft'], $owner);
        $before = [$foreign->id => $this->state($foreign->id), $draft->id => $this->state($draft->id)];

        $this->actingAs($editor = $this->userWithRole('editor'));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($foreign, ['locale' => 'en', 'title' => 'Hijack'], $foreign->revision, $this->ctx()));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->changeStatus($foreign, 'draft', $foreign->revision, $this->ctx()));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->changeStatus($draft, 'published', $draft->revision, $this->ctx()));
        $this->assertDenied('unauthorized_delete_others', fn () => $this->contents()->delete($foreign, $foreign->revision, $this->ctx()));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->create(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Spoof', 'author_id' => $owner->id], $this->ctx()));

        $own = $this->contents()->create(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Mine'], $this->ctx());
        $this->assertSame($editor->id, $own->author_id);
        $this->assertDenied('unauthorized_author_change', fn () => $this->contents()->update($own, ['author_id' => $owner->id], $own->revision, $this->ctx()));

        $this->assertSame($before[$foreign->id], $this->state($foreign->id));
        $this->assertSame($before[$draft->id], $this->state($draft->id));
    }

    public function test_p2_mixed_owner_bulk_publish_is_judged_per_record(): void
    {
        $owner = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $mine = $this->makeContent(['title' => 'Mine'], $editor);
        $theirs = $this->makeContent(['title' => 'Theirs'], $owner);

        $this->actingAs($editor);
        Livewire::test(ListPosts::class)
            ->callTableBulkAction('changeStatus', [$mine->id, $theirs->id], ['status' => 'published', 'revisions' => json_encode([(string) $mine->id => $mine->revision, (string) $theirs->id => $theirs->revision])]);

        $this->assertSame('published', $mine->fresh()->status);
        $this->assertSame('draft', $theirs->fresh()->status, 'one authorised record never authorises the batch');
    }

    // P4 — CLI / unattributed -----------------------------------------------

    public function test_p4_unattributed_console_writes_are_refused(): void
    {
        auth()->logout();
        $post = $this->makeContent(['title' => 'CLI target']);

        $this->assertDenied('unattributed_write', fn () => $this->contents()->changeStatus($post, 'published', $post->revision, ContentWriteContext::interactive()));
        $this->assertDenied('unattributed_write', fn () => $this->contents()->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'CLI'], ContentWriteContext::interactive()));
        $this->assertSame('draft', $post->fresh()->status);
    }

    // P5 — body preservation ------------------------------------------------

    public function test_p5_omitted_body_and_other_locale_edits_preserve_stored_bodies(): void
    {
        $this->actingAs($admin = $this->superAdmin());
        $post = $this->makeContent(['title' => 'Bodies', 'content' => '<p>English body</p>'], $admin);
        $post = $this->contents()->update($post, ['locale' => 'vi', 'title' => 'Ban', 'content' => '<p>Vietnamese body</p>'], $post->revision, $this->ctx());

        $post = $this->contents()->update($post, ['locale' => 'en', 'title' => 'Bodies v2'], $post->revision, $this->ctx());
        $post = $this->contents()->changeStatus($post, 'pending', $post->revision, $this->ctx());
        $post = $this->contents()->update($post, ['is_featured' => true], $post->revision, $this->ctx());
        $post = $this->contents()->update($post, ['locale' => 'vi', 'title' => 'Ban v2'], $post->revision, $this->ctx());

        $bodies = $post->translations()->pluck('content', 'locale')->all();
        $this->assertSame('<p>English body</p>', $bodies['en']);
        $this->assertSame('<p>Vietnamese body</p>', $bodies['vi']);
    }

    // P6 — concurrency ------------------------------------------------------

    public function test_p6_stale_status_change_is_a_conflict_not_an_overwrite(): void
    {
        $this->actingAs($editor = $this->userWithRole('editor'));
        $post = $this->contents()->create(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Race'], $this->ctx());
        $seen = $post->revision;
        $this->contents()->update($post, ['locale' => 'en', 'title' => 'Race v2'], $seen, $this->ctx());

        $this->expectException(ContentRevisionConflict::class);
        $this->contents()->changeStatus($post->fresh(), 'published', $seen, $this->ctx());
    }

    // Scheduling by a publisher ---------------------------------------------

    public function test_editor_schedules_and_unschedules_own_post(): void
    {
        $this->actingAs($this->userWithRole('editor'));
        $post = $this->contents()->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Planned', 'published_at' => now()->addDay()], $this->ctx());

        $this->assertSame('published', $post->status);
        $this->assertTrue($post->published_at->equalTo(now()->addDay()), 'the requested publication time is kept');

        $post = $this->contents()->changeStatus($post, 'draft', $post->revision, $this->ctx());
        $this->assertSame('draft', $post->status);
    }
}
