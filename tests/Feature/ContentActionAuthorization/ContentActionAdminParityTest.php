<?php

declare(strict_types=1);

namespace Tests\Feature\ContentActionAuthorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentActionAuthorization\Concerns\ActionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionReason;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-CONTENT-ACTION-AUTHORIZATION-R1 — the decision equals what the real Core
 * Admin authority (ContentManager + ContentWriteGate, rolled back) does for the
 * same actor, subject, action and locale; plus the named negative cases.
 */
final class ContentActionAdminParityTest extends TestCase
{
    use ActionFixtures;
    use RefreshDatabase;

    /** @return list<array{0: ContentAction, 1: ?string}> */
    private function actionCases(): array
    {
        return [
            [ContentAction::View, null],
            [ContentAction::View, 'vi'],
            [ContentAction::Edit, null],
            [ContentAction::Edit, 'en'],
            [ContentAction::Edit, 'vi'],
            [ContentAction::Publish, null],
            [ContentAction::Unpublish, null],
            [ContentAction::CreateTranslation, 'vi'],
            [ContentAction::CreateTranslation, 'en'],
            [ContentAction::EditTranslation, 'en'],
            [ContentAction::EditTranslation, 'vi'],
            [ContentAction::Edit, 'fr'],
            [ContentAction::Edit, 'zz'],
        ];
    }

    public function test_every_actor_subject_action_and_locale_matches_the_admin_authority(): void
    {
        $this->seedActionSite();
        $actors = [
            'no permission' => $this->noPermissionUser(),
            'author' => $this->userWithRole('author'),
            'contributor' => $this->contributor(),
            'editor' => $this->userWithRole('editor'),
            'admin' => $this->userWithRole('admin'),
            'super-admin' => $this->superAdmin(),
        ];
        $stranger = User::factory()->create();
        $checked = 0;
        $allowed = 0;

        foreach ($actors as $label => $actor) {
            foreach (['post', 'page'] as $type) {
                foreach (['own' => $actor, 'others' => $stranger] as $whose => $author) {
                    foreach (['draft', 'pending', 'published'] as $status) {
                        $content = $this->makeContent(['type' => $type, 'status' => $status], $author);

                        foreach ($this->actionCases() as [$action, $locale]) {
                            $expected = $this->adminAllows($actor, $content, $action, $locale);
                            $decision = $this->decide($actor, $action, $content, $locale);

                            $this->assertSame($expected, $decision->allowed(), sprintf(
                                '%s / %s %s %s / %s [%s] → decision %s, admin %s',
                                $label, $whose, $status, $type, $action->value, $locale ?? '-',
                                $decision->reason->value, $expected ? 'allow' : 'deny',
                            ));
                            $this->assertSame($decision->allowed(), $decision->reason === ContentActionReason::Allowed);
                            $checked++;
                            $allowed += (int) $expected;
                        }
                    }
                }
            }
        }

        $this->assertSame(6 * 2 * 2 * 3 * 13, $checked);
        $this->assertGreaterThan(100, $allowed, 'the matrix exercises allowed cases too');
    }

    public function test_edit_only_actor_can_write_a_draft_but_cannot_publish(): void
    {
        $this->seedActionSite();
        $author = $this->userWithRole('author');
        $draft = $this->makeContent(['status' => 'draft'], $author);

        $this->assertTrue($this->decide($author, ContentAction::Edit, $draft, 'en')->allowed());
        $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($author, ContentAction::Publish, $draft)->reason);
        $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($author, ContentAction::Unpublish, $draft)->reason);

        $published = $this->makeContent(['status' => 'published'], $author);
        $this->assertSame(ContentActionReason::StatusDenied, $this->decide($author, ContentAction::Edit, $published, 'en')->reason, 'published output needs publish');
        $this->assertSame(ContentActionReason::StatusDenied, $this->decide($author, ContentAction::CreateTranslation, $published, 'vi')->reason);
        $this->assertTrue($this->decide($author, ContentAction::View, $published)->allowed());
    }

    public function test_publish_permission_never_bypasses_ownership(): void
    {
        $this->seedActionSite();
        $editor = $this->userWithRole('editor');
        $others = $this->makeContent(['status' => 'draft'], User::factory()->create());
        $authorless = $this->makeContent(['status' => 'draft']);
        DB::table('cms_contents')->where('id', $authorless->id)->update(['author_id' => null]);

        foreach ([ContentAction::Publish, ContentAction::Unpublish, ContentAction::Edit] as $action) {
            $this->assertSame(ContentActionReason::OwnershipDenied, $this->decide($editor, $action, $others)->reason, $action->value);
            $this->assertSame(ContentActionReason::OwnershipDenied, $this->decide($editor, $action, $authorless)->reason, 'NULL author is someone else');
        }

        $publisherOnly = $this->userWithPermissions('publisher-only', ['posts.view', 'posts.publish']);
        $own = $this->makeContent(['status' => 'draft'], $publisherOnly);
        $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($publisherOnly, ContentAction::Publish, $own)->reason, 'publish without edit');
    }

    public function test_revocation_applies_from_the_next_request_without_restart(): void
    {
        $this->seedActionSite();
        $role = Role::query()->create(['name' => 'Revocable', 'slug' => 'revocable', 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', ['posts.view', 'posts.edit', 'posts.publish'])->pluck('id')->all());
        $user = User::factory()->create();
        $role->users()->attach($user->id);
        $post = $this->makeContent(['status' => 'draft'], $user);

        $this->assertTrue($this->decide($user, ContentAction::Publish, $post)->allowed());

        $role->permissions()->detach(Permission::query()->where('slug', 'posts.publish')->value('id'));
        $this->nextRequest();

        $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($user, ContentAction::Publish, $post)->reason);
        $this->assertFalse($this->adminAllows($user, $post, ContentAction::Publish));

        $role->users()->detach($user->id);
        $this->nextRequest();

        $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($user, ContentAction::View, $post)->reason);
    }

    public function test_soft_deleted_content_is_denied(): void
    {
        $this->seedActionSite();
        $admin = $this->superAdmin();
        $post = $this->makeContent(['status' => 'published']);
        $this->actingAs($admin);
        $this->contents()->delete($post, $post->revision, \TheNguyen\CMS\ContentAuthority\ContentWriteContext::interactive());

        foreach (ContentAction::cases() as $action) {
            $this->assertSame(ContentActionReason::StatusDenied, $this->decide($admin, $action, $post->id, 'en')->reason, $action->value);
        }
    }

    public function test_managed_content_is_refused_for_every_write_action_whatever_the_guard_says(): void
    {
        $this->seedActionSite();
        $this->useAuthorityFixturePlugins();
        $plugin = $this->guardedPlugin('caa-owner', 'allow');
        $editor = $this->userWithRole('editor');
        $this->actingAs($editor);
        $managed = $plugin->createManaged(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Owned'], $editor);
        $guard = $this->guardClass('caa-owner');
        $writes = [[ContentAction::Edit, 'en'], [ContentAction::Publish, null], [ContentAction::Unpublish, null], [ContentAction::CreateTranslation, 'vi'], [ContentAction::EditTranslation, 'en']];

        foreach (['allow', 'deny'] as $mode) {
            $guard::$mode = $mode;
            $guard::$seen = [];

            foreach ($writes as [$action, $locale]) {
                $this->assertSame(ContentActionReason::OwnershipDenied, $this->decide($editor, $action, $managed, $locale)->reason, $mode.' '.$action->value);
            }

            $this->assertSame([], $guard::$seen, 'the decision never calls extension guard code');
            $this->assertTrue($this->decide($editor, ContentAction::View, $managed)->allowed(), 'Admin still lists it');
        }

        // A denying guard makes the Admin refuse; an allowing one does not — the
        // decision is never more permissive than either (documented stricter case).
        $guard::$mode = 'deny';
        $this->assertFalse($this->adminAllows($editor, $managed, ContentAction::Edit, 'en'));
        $guard::$mode = 'allow';
        $this->assertTrue($this->adminAllows($editor, $managed, ContentAction::Edit, 'en'));

        // Owner deactivated: the gate refuses every write (owner_inactive).
        app('cms.extension')->deactivatePlugin('caa-owner');
        app('cms.extension')->flushRegistry();

        $this->assertSame(ContentActionReason::OwnershipDenied, $this->decide($editor, ContentAction::Edit, $managed, 'en')->reason);
        $this->assertFalse($this->adminAllows($editor, $managed, ContentAction::Edit, 'en'));
    }

    public function test_actors_without_content_view_rights_learn_nothing_about_subjects(): void
    {
        $this->seedActionSite();
        $subscriber = $this->noPermissionUser();
        $post = $this->makeContent(['status' => 'draft']);
        $product = $this->makeContent(['type' => 'product']);

        foreach ([$post->id, $product->id, 999999, -1] as $subject) {
            foreach ([null, 'en', 'fr', 'bad!'] as $locale) {
                $this->assertSame(ContentActionReason::PermissionDenied, $this->decide($subscriber, ContentAction::Edit, $subject, $locale)->reason, $subject.' '.$locale);
            }
        }

        $author = $this->userWithRole('author');
        $this->assertSame(ContentActionReason::UnsupportedSubject, $this->decide($author, ContentAction::View, $product)->reason);
        $this->assertSame(ContentActionReason::UnsupportedSubject, $this->decide($author, ContentAction::View, 999999)->reason);
    }

    /** What a new HTTP request sees: fresh request-scoped authority services. */
    private function nextRequest(): void
    {
        foreach (['cms.permission', 'cms.content.gate', 'cms.content', 'cms.content.actions'] as $id) {
            app()->forgetInstance($id);
        }
    }
}
