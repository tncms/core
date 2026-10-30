<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — publication + ownership enforcement at
 * the ContentManager service boundary (T-B1…T-B5). Default role bundles from
 * CmsRolePermissionSeeder: author = posts.view/create/edit (no publish/delete),
 * editor = posts.* incl. publish (no *_others), admin = all but *_others/recover.
 */
final class ContentPublicationAuthorityTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

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

    private function enTitle(Content $content): ?string
    {
        return $content->translations()->where('locale', 'en')->value('title');
    }

    public function test_author_creates_and_edits_own_drafts_but_cannot_publish(): void
    {
        $this->seedSite();
        $this->actingAs($author = $this->userWithRole('author'));

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Live now'], $this->ctx()));
        $this->assertSame(0, Content::query()->count());

        $draft = $this->contents()->create(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'My draft'], $this->ctx());
        $this->assertSame($author->id, $draft->author_id, 'the creator becomes the author');

        $draft = $this->contents()->update($draft, ['locale' => 'en', 'title' => 'My draft v2'], $draft->revision, $this->ctx());
        $this->assertSame('My draft v2', $this->enTitle($draft));

        // pending / private are not public statuses in Core.
        $draft = $this->contents()->changeStatus($draft, 'pending', $draft->revision, $this->ctx());
        $this->assertSame('pending', $draft->status);

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->changeStatus($draft, 'published', $draft->revision, $this->ctx()));
        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->update($draft, ['status' => 'published'], $draft->revision, $this->ctx()));
        $this->assertSame('pending', $draft->refresh()->status);
    }

    public function test_author_cannot_mutate_public_fields_of_a_published_post(): void
    {
        $this->seedSite();
        $author = $this->userWithRole('author');
        $post = $this->makeContent(['status' => 'published', 'title' => 'Published by editor'], $author);
        $this->actingAs($author);
        $state = $this->contentState($post->id);

        foreach ([
            ['locale' => 'en', 'title' => 'Changed title'],
            ['locale' => 'en', 'content' => '<p>Changed body</p>'],
            ['locale' => 'en', 'meta_title' => 'SEO change'],
            ['locale' => 'vi', 'title' => 'Bản dịch mới'],
            ['featured_image' => 'https://cdn.test/new.jpg'],
            ['term_ids' => [99]],
            ['published_at' => '2030-01-01 00:00:00'],
            ['status' => 'draft'],
        ] as $change) {
            $this->assertDenied('unauthorized_publish', fn () => $this->contents()->update($post, $change, $post->revision, $this->ctx()));
        }

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->changeStatus($post, 'private', $post->revision, $this->ctx()));
        $this->assertSame($state, $this->contentState($post->id));

        // A no-op save of the published record is not a public mutation.
        $same = $this->contents()->update($post, ['locale' => 'en', 'title' => 'Published by editor'], $post->revision, $this->ctx());
        $this->assertSame('published', $same->status);
    }

    public function test_ownership_is_enforced_per_record_including_author_reassignment(): void
    {
        $this->seedSite();
        $alice = $this->userWithRole('author');
        $bob = $this->userWithRole('author');
        $alicesDraft = $this->makeContent(['title' => 'Alice draft'], $alice);
        $bobsDraft = $this->makeContent(['title' => 'Bob draft'], $bob);

        $this->actingAs($bob);
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($alicesDraft, ['locale' => 'en', 'title' => 'Bob was here'], $alicesDraft->revision, $this->ctx()));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($alicesDraft, ['locale' => 'vi', 'title' => 'Bản dịch của Bob'], $alicesDraft->revision, $this->ctx()));
        // Taking ownership first is the same refusal: the stored author is judged.
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($alicesDraft, ['author_id' => $bob->id], $alicesDraft->revision, $this->ctx()));
        // Giving his own post away is an author change.
        $this->assertDenied('unauthorized_author_change', fn () => $this->contents()->update($bobsDraft, ['author_id' => $alice->id], $bobsDraft->revision, $this->ctx()));
        // A forged author on create needs edit_others too.
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->create(['type' => 'post', 'locale' => 'en', 'title' => 'Forged', 'author_id' => $alice->id], $this->ctx()));

        $this->assertSame('Alice draft', $this->enTitle($alicesDraft));
        $this->assertSame($alice->id, $alicesDraft->refresh()->author_id);
        $this->assertSame($bob->id, $bobsDraft->refresh()->author_id);
    }

    public function test_delete_needs_delete_and_ownership(): void
    {
        $this->seedSite();
        $author = $this->userWithRole('author');
        $editor = $this->userWithRole('editor');
        $own = $this->makeContent(['title' => 'Editor own'], $editor);
        $others = $this->makeContent(['title' => 'Author draft'], $author);

        $this->actingAs($author);
        $this->assertDenied('unauthorized_delete', fn () => $this->contents()->delete($others, $others->revision, $this->ctx()));

        $this->actingAs($editor);
        $this->assertDenied('unauthorized_delete_others', fn () => $this->contents()->delete($others, $others->revision, $this->ctx()));
        $this->assertTrue($this->contents()->delete($own, $own->revision, $this->ctx()));
        $this->assertSoftDeleted('cms_contents', ['id' => $own->id]);
        $this->assertNull($others->refresh()->deleted_at);
    }

    public function test_editor_granted_edit_others_publishes_another_authors_work_keeping_the_author(): void
    {
        $this->seedSite();
        $author = $this->userWithRole('author');
        $draft = $this->makeContent(['title' => 'Ready for review'], $author);

        // Default editor: publish yes, others' content no.
        $this->actingAs($this->userWithRole('editor'));
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->changeStatus($draft, 'published', $draft->revision, $this->ctx()));

        $this->actingAs($this->userWithRole('editor', ['posts.edit_others']));
        $published = $this->contents()->changeStatus($draft, 'published', $draft->revision, $this->ctx());
        $published = $this->contents()->update($published, ['locale' => 'en', 'title' => 'Edited by editor'], $published->revision, $this->ctx());

        $this->assertSame('published', $published->status);
        $this->assertSame($author->id, $published->author_id, 'publishing never changes the author');
    }

    public function test_super_admin_can_do_everything(): void
    {
        $this->seedSite();
        $other = $this->userWithRole('author');
        $post = $this->makeContent(['title' => 'Someone else'], $other);
        $this->actingAs($this->superAdmin());

        $post = $this->contents()->changeStatus($post, 'published', $post->revision, $this->ctx());
        $post = $this->contents()->update($post, ['locale' => 'en', 'title' => 'SA edit', 'author_id' => $other->id], $post->revision, $this->ctx());
        $this->assertTrue($this->contents()->delete($post, $post->revision, $this->ctx()));
    }

    public function test_uninitialised_rbac_never_grants_publication_or_others_rights(): void
    {
        // Languages only: no roles exist, RBAC is uninitialised (fail-open for base checks).
        $this->seed(CmsLanguageSeeder::class);
        $this->actingAs($user = User::factory()->create());

        $draft = $this->contents()->create(['type' => 'post', 'locale' => 'en', 'title' => 'Fresh install draft'], $this->ctx());
        $this->contents()->update($draft, ['locale' => 'en', 'title' => 'Still allowed'], $draft->revision, $this->ctx());

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->changeStatus($draft, 'published', $draft->refresh()->revision, $this->ctx()));

        $foreign = Content::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => User::factory()->create()->id]);
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($foreign, ['locale' => 'en', 'title' => 'x'], 0, $this->ctx()));
    }

    public function test_unattributed_writes_are_refused_and_cli_is_not_authority(): void
    {
        $this->seedSite();
        auth()->logout();

        $this->assertDenied('unattributed_write', fn () => $this->contents()->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Console publish']));
        $this->assertSame(0, Content::query()->count());

        // Only an explicit, bounded system context authorises an actorless write.
        $seeded = $this->contents()->create(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Seeded'], ContentWriteContext::system('install_seed'));
        $this->assertSame('published', $seeded->status);
    }

    public function test_request_shaped_authority_fields_are_ignored(): void
    {
        $this->seedSite();
        $this->actingAs($author = $this->userWithRole('author'));

        $this->assertDenied('unauthorized_publish', fn () => $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Sneaky',
            'source' => 'system', 'channel' => 'console', 'context' => 'system', 'revision' => 999, 'managed_by' => 'core',
        ], $this->ctx()));
        $this->assertSame(0, Content::query()->count());
    }

    public function test_pages_follow_the_pages_permissions(): void
    {
        $this->seedSite();
        // Authors hold no pages.* permission at all.
        $this->actingAs($this->userWithRole('author'));
        $this->assertDenied('unauthorized_create', fn () => $this->contents()->create(['type' => 'page', 'locale' => 'en', 'title' => 'About'], $this->ctx()));

        $this->actingAs($this->userWithRole('editor'));
        $page = $this->contents()->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'About'], $this->ctx());
        $this->assertSame('published', $page->status);
    }
}
