<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthorizationR2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ContentAuthorizationR2\Concerns\AuthorizationR2Fixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;
use TheNguyen\CMS\ContentAuthority\ContentActionDenied;
use TheNguyen\CMS\ContentAuthority\ContentActionReason;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;

/**
 * CORE-CONTENT-AUTHORIZATION-R2 Gate B — `ContentActionContext::privateDraft()`.
 *
 * Valid only for `edit` / `edit_translation`; it skips exactly one rule (published
 * content needs publish) and nothing else; it never authorizes a Core write.
 */
class PrivateDraftContractTest extends TestCase
{
    use AuthorizationR2Fixtures;
    use RefreshDatabase;

    private function draftDecision(?\App\Models\User $actor, ContentAction $action, mixed $subject, ?string $locale = 'en'): \TheNguyen\CMS\ContentAuthority\ContentActionDecision
    {
        $context = ($locale === null ? ContentActionContext::none() : ContentActionContext::forLocale($locale))->privateDraft();

        return $this->actions()->decision($actor, $action, $subject, $context);
    }

    public function test_edit_only_may_prepare_a_private_draft_of_its_own_published_content(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published'], $actor);

        $edit = $this->draftDecision($actor, ContentAction::Edit, $post);
        $this->assertTrue($edit->allowed());
        $this->assertTrue($edit->privateDraft, 'the decision is scoped to a private draft');
        $this->assertTrue($this->draftDecision($actor, ContentAction::EditTranslation, $post)->allowed());
        $this->assertTrue($this->draftDecision($actor, ContentAction::Edit, $post, null)->allowed());

        // Without the context nothing changes (R1 semantics).
        $this->assertSame(ContentActionReason::StatusDenied, $this->decide($actor, ContentAction::Edit, $post, 'en')->reason);
        $this->assertFalse($this->decide($actor, ContentAction::Edit, $post, 'en')->privateDraft);
    }

    public function test_private_draft_is_unsupported_for_every_other_action(): void
    {
        $this->setUpR2Site();
        $publisher = $this->publisher();
        $post = $this->makeContent(['status' => 'published'], $publisher);

        foreach ([[ContentAction::Publish, null], [ContentAction::Unpublish, null], [ContentAction::View, null], [ContentAction::CreateTranslation, 'vi']] as [$action, $locale]) {
            $this->assertSame(ContentActionReason::UnsupportedAction, $this->draftDecision($publisher, $action, $post, $locale)->reason, $action->value);
        }

        $this->assertSame(ContentActionReason::UnsupportedAction, $this->draftDecision($publisher, ContentAction::Publish, $post, null)->reason, 'publish rights do not help');
        $this->assertSame(ContentActionReason::UnsupportedAction, $this->actions()->decision($publisher, 'edit_layout', $post, ContentActionContext::none()->privateDraft())->reason);
    }

    public function test_every_other_rule_still_applies(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $other = $this->publisher();
        $othersPost = $this->makeContent(['status' => 'published'], $other);
        $own = $this->makeContent(['status' => 'published'], $actor);

        $this->assertSame(ContentActionReason::Unauthenticated, $this->draftDecision(null, ContentAction::Edit, $own)->reason);
        $this->assertSame(ContentActionReason::PermissionDenied, $this->draftDecision($this->noPermissionUser(), ContentAction::Edit, $own)->reason);
        $this->assertSame(ContentActionReason::OwnershipDenied, $this->draftDecision($actor, ContentAction::Edit, $othersPost)->reason, 'other author');
        $this->assertTrue($this->draftDecision($this->contributor(), ContentAction::Edit, $othersPost)->allowed(), 'strict edit_others');
        $this->assertSame(ContentActionReason::LocaleDenied, $this->draftDecision($actor, ContentAction::Edit, $own, 'fr')->reason, 'inactive');
        $this->assertSame(ContentActionReason::LocaleDenied, $this->draftDecision($actor, ContentAction::Edit, $own, 'zz!')->reason, 'invalid');
        $this->assertSame(ContentActionReason::LocaleDenied, $this->draftDecision($actor, ContentAction::EditTranslation, $own, 'vi')->reason, 'missing translation');
        $this->assertSame(ContentActionReason::UnsupportedSubject, $this->draftDecision($actor, ContentAction::Edit, 999999)->reason, 'missing content');

        $trashed = $this->makeContent(['status' => 'published'], $actor);
        DB::table('cms_contents')->where('id', $trashed->id)->update(['deleted_at' => now()]);
        $this->assertSame(ContentActionReason::StatusDenied, $this->draftDecision($actor, ContentAction::Edit, $trashed->id)->reason, 'deleted');
    }

    public function test_revocation_applies_immediately(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published'], $actor);
        $this->assertTrue($this->draftDecision($actor, ContentAction::Edit, $post)->allowed());

        $this->revoke($actor, 'posts.edit');

        $this->assertSame(ContentActionReason::PermissionDenied, $this->draftDecision($actor, ContentAction::Edit, $post)->reason);
    }

    public function test_private_draft_never_authorizes_a_core_write_or_a_status_change(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published'], $actor);
        $before = $this->contentState($post->id);
        $this->assertTrue($this->draftDecision($actor, ContentAction::Edit, $post)->allowed());
        $this->actingAs($actor);

        foreach ([
            fn () => $this->contents()->update($post, ['locale' => 'en', 'title' => 'Public replace'], $post->revision, ContentWriteContext::interactive($actor)),
            fn () => $this->contents()->changeStatus($post, 'draft', $post->revision, ContentWriteContext::interactive($actor)),
        ] as $write) {
            try {
                $write();
                $this->fail('a Core write of published content must still need publish');
            } catch (ContentWriteDenied $e) {
                $this->assertSame('unauthorized_publish', $e->errorCode());
            }
        }

        $this->assertSame($before, $this->contentState($post->id));
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_decisions_are_side_effect_free_and_refusals_leak_nothing(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published', 'title' => 'Secret title'], $actor);
        $fingerprint = $this->databaseFingerprint();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->draftDecision($actor, ContentAction::Edit, $post);
        $this->draftDecision($actor, ContentAction::Publish, $post, null);

        $statements = array_map(static fn (array $q): string => strtolower(ltrim($q['query'])), DB::getQueryLog());
        DB::disableQueryLog();
        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select', $sql);
            $this->assertStringNotContainsString('for update', $sql);
        }
        $this->assertSame($fingerprint, $this->databaseFingerprint());

        try {
            $this->actions()->authorize($actor, ContentAction::Publish, $post, ContentActionContext::none()->privateDraft());
            $this->fail('expected a refusal');
        } catch (ContentActionDenied $e) {
            $this->assertSame('This action is unauthorized.', $e->getMessage());
            $this->assertSame(403, $e->status());
            $this->assertSame(ContentActionReason::UnsupportedAction, $e->decision()->reason);
        }
    }
}
