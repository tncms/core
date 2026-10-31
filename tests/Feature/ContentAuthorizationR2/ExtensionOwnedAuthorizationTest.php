<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthorizationR2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentAuthorizationR2\Concerns\AuthorizationR2Fixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;
use TheNguyen\CMS\ContentAuthority\ContentActionReason;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;

/**
 * CORE-CONTENT-AUTHORIZATION-R2 Gate C — `ContentActionContext::asOwningExtension()`.
 *
 * The owning plugin (identity derived from its code on disk, re-verified at decision
 * time against `cms_contents.managed_by`) gets Core's RBAC decision for its own
 * content. It never gets more: Core RBAC still decides, the guard (business
 * approval) and the write gate (transition authority) still apply to writes.
 */
class ExtensionOwnedAuthorizationTest extends TestCase
{
    use AuthorizationR2Fixtures;
    use RefreshDatabase;

    public function test_the_owner_gets_cores_rbac_decision_for_its_content(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $editOnly = $this->editOnly();
        $publisher = $this->publisher();
        $draft = $this->managedPost($owner, $editOnly);
        $published = $this->managedPost($owner, $editOnly, 'published');
        $pubOwn = $this->managedPost($owner, $publisher);

        $edit = $decider->decide($editOnly, 'edit', $draft->id, 'en', asOwner: true);
        $this->assertTrue($edit->allowed());
        $this->assertSame('r2-owner', $edit->owner);
        $this->assertSame(ContentActionReason::PermissionDenied, $decider->decide($editOnly, 'publish', $draft->id, asOwner: true)->reason, 'edit-only cannot publish');
        $this->assertSame(ContentActionReason::StatusDenied, $decider->decide($editOnly, 'edit', $published->id, 'en', asOwner: true)->reason, 'published needs publish');
        $this->assertTrue($decider->decide($editOnly, 'edit', $published->id, 'en', privateDraft: true, asOwner: true)->allowed(), 'private draft of owned published content');
        $this->assertSame(ContentActionReason::UnsupportedAction, $decider->decide($editOnly, 'publish', $published->id, privateDraft: true, asOwner: true)->reason);
        $this->assertTrue($decider->decide($publisher, 'publish', $pubOwn->id, asOwner: true)->allowed());
        $this->assertSame(ContentActionReason::OwnershipDenied, $decider->decide($publisher, 'publish', $draft->id, asOwner: true)->reason, 'other author still needs edit_others');
        $this->assertSame(ContentActionReason::PermissionDenied, $decider->decide($this->noPermissionUser(), 'edit', $draft->id, asOwner: true)->reason);
        $this->assertSame(ContentActionReason::Unauthenticated, $decider->decide(null, 'edit', $draft->id, asOwner: true)->reason);
        $this->assertTrue($decider->decide($editOnly, 'create_translation', $draft->id, 'vi', asOwner: true)->allowed());
        $this->assertSame(ContentActionReason::LocaleDenied, $decider->decide($editOnly, 'create_translation', $draft->id, 'fr', asOwner: true)->reason, 'inactive locale');
    }

    public function test_revoked_permission_is_effective_for_the_owner(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $actor = $this->editOnly();
        $post = $this->managedPost($owner, $actor, 'published');
        $this->assertTrue($decider->decide($actor, 'edit', $post->id, 'en', privateDraft: true, asOwner: true)->allowed());

        $this->revoke($actor, 'posts.edit');

        $this->assertSame(ContentActionReason::PermissionDenied, $decider->decide($actor, 'edit', $post->id, 'en', privateDraft: true, asOwner: true)->reason);
    }

    public function test_no_one_else_obtains_owner_capabilities(): void
    {
        $this->setUpR2Site();
        [$owner, $ownerDecider] = $this->r2Plugin('r2-owner');
        [, $impostor] = $this->r2Plugin('r2-impostor');
        $editor = $this->userWithRole('editor');
        $post = $this->managedPost($owner, $editor);

        $this->assertTrue($ownerDecider->decide($editor, 'edit', $post->id, 'en', asOwner: true)->allowed());

        // Another active plugin claiming ownership.
        $this->assertSame(ContentActionReason::OwnershipDenied, $impostor->decide($editor, 'edit', $post->id, 'en', asOwner: true)->reason);
        // Non-plugin (Core/host/test) code claiming ownership.
        $this->assertSame(ContentActionReason::OwnershipDenied, $this->actions()->decision($editor, 'edit', $post->id, ContentActionContext::forLocale('en')->asOwningExtension())->reason);
        // The owner's context handed to anyone still names only the owner.
        $handed = $ownerDecider->context('en', asOwner: true);
        $this->assertTrue($this->actions()->decision($editor, 'edit', $post->id, $handed)->allowed());
        $foreign = $this->managedPost($this->r2Plugin('r2-third')[0], $editor);
        $this->assertSame(ContentActionReason::OwnershipDenied, $this->actions()->decision($editor, 'edit', $foreign->id, $handed)->reason);

        // A caller-modified model is never trusted: the persisted managed_by decides.
        $spoofed = $post->fresh();
        $spoofed->managed_by = 'r2-impostor';
        $this->assertSame(ContentActionReason::OwnershipDenied, $impostor->decide($editor, 'edit', $spoofed, 'en', asOwner: true)->reason);
        $cleared = $post->fresh();
        $cleared->managed_by = null;
        $this->assertSame(ContentActionReason::OwnershipDenied, $impostor->decide($editor, 'edit', $cleared, 'en')->reason);
    }

    public function test_an_owner_claim_cannot_be_forged_by_unserialize(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $editor = $this->userWithRole('editor');
        $post = $this->managedPost($owner, $editor);
        $ownerFile = $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'r2-owner'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Decider.php';
        $class = ContentActionContext::class;
        $claim = "\0{$class}\0ownerClaimFile";
        $forged = sprintf('O:%d:"%s":3:{s:6:"locale";s:2:"en";s:12:"privateDraft";b:0;s:%d:"%s";s:%d:"%s";}', strlen($class), $class, strlen($claim), $claim, strlen($ownerFile), $ownerFile);

        try {
            $context = unserialize($forged);
            $this->assertSame(ContentActionReason::OwnershipDenied, $this->actions()->decision($editor, 'edit', $post->id, $context)->reason, 'a forged claim must not verify');
        } catch (\LogicException) {
            // refused outright
        }

        $this->expectException(\LogicException::class);
        serialize($decider->context('en', asOwner: true));
    }

    public function test_inactive_unloadable_or_missing_owners_fail_closed(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $editor = $this->userWithRole('editor');
        $post = $this->managedPost($owner, $editor);
        $context = $decider->context('en', asOwner: true);

        // Unloadable (its runtime failed to boot this request).
        (fn () => $this->unloadable['r2-owner'] = true)->call(app('cms.extension'));
        $this->assertSame(ContentActionReason::OwnershipDenied, $decider->decide($editor, 'edit', $post->id, 'en', asOwner: true)->reason, 'unloadable');
        (function () {
            unset($this->unloadable['r2-owner']);
        })->call(app('cms.extension'));

        // Deactivated after the context was created, then a context created while inactive.
        app('cms.extension')->deactivatePlugin('r2-owner');
        app('cms.extension')->flushRegistry();
        $this->assertSame(ContentActionReason::OwnershipDenied, $this->actions()->decision($editor, 'edit', $post->id, $context)->reason, 'deactivated');
        $this->assertSame(ContentActionReason::OwnershipDenied, $decider->decide($editor, 'edit', $post->id, 'en', asOwner: true)->reason, 'inactive');

        // managed_by naming a plugin that does not exist.
        $orphan = $this->makeContent([], $editor);
        DB::table('cms_contents')->where('id', $orphan->id)->update(['managed_by' => 'not-installed']);
        $this->assertSame(ContentActionReason::OwnershipDenied, $this->actions()->decision($editor, 'edit', $orphan->id, ContentActionContext::none()->asOwningExtension())->reason);
        $this->assertTrue($this->decide($editor, ContentAction::View, $orphan->id)->allowed(), 'view is unchanged');
    }

    public function test_an_owner_decision_is_not_write_authority(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        [$impostorCode] = $this->r2Plugin('r2-impostor');
        $editOnly = $this->editOnly();
        $post = $this->managedPost($owner, $editOnly);
        $published = $this->managedPost($owner, $editOnly, 'published');
        $guard = $this->guardClass('r2-owner');
        $this->actingAs($editOnly);

        // Business approval: the decision allows, the owner's guard refuses the write.
        $this->assertTrue($decider->decide($editOnly, 'edit', $post->id, 'en', asOwner: true)->allowed());
        $guard::$mode = 'deny';
        $this->assertWriteDenied(fn () => $owner->update($post, ['locale' => 'en', 'title' => 'x'], $post->revision, $editOnly), 'guard_denied.locked');
        $guard::$mode = 'own-only';

        // Another plugin's write is refused by the owner's guard.
        $this->assertWriteDenied(fn () => $impostorCode->update($post->fresh(), ['locale' => 'en', 'title' => 'x'], $post->fresh()->revision, $editOnly), 'guard_denied.foreign_write');

        // Transition authority: a private-draft decision never lets the owner publish or
        // replace published content through Core for an edit-only actor.
        $this->assertTrue($decider->decide($editOnly, 'edit', $published->id, 'en', privateDraft: true, asOwner: true)->allowed());
        $this->assertWriteDenied(fn () => $owner->update($published, ['locale' => 'en', 'title' => 'x'], $published->revision, $editOnly), 'unauthorized_publish');
        $this->assertSame('published', $published->fresh()->status);
    }

    public function test_owner_decisions_are_side_effect_free_and_never_call_the_guard(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $editor = $this->userWithRole('editor');
        $post = $this->managedPost($owner, $editor, 'published');
        $guard = $this->guardClass('r2-owner');
        $guard::$seen = [];
        $fingerprint = $this->databaseFingerprint();
        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach (['edit', 'publish', 'unpublish', 'edit_translation'] as $action) {
            $decider->decide($editor, $action, $post->id, $action === 'edit_translation' ? 'en' : null, asOwner: true);
        }

        $statements = array_map(static fn (array $q): string => strtolower(ltrim($q['query'])), DB::getQueryLog());
        DB::disableQueryLog();
        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select', $sql);
        }
        $this->assertSame([], $guard::$seen);
        $this->assertSame($fingerprint, $this->databaseFingerprint());
    }

    private function assertWriteDenied(\Closure $write, string $code): void
    {
        try {
            $write();
            $this->fail('expected the write gate to refuse: '.$code);
        } catch (ContentWriteDenied $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
