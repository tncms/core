<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthorizationR2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ContentAuthorizationR2\Concerns\AuthorizationR2Fixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionReason;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteDenied;

/**
 * CORE-CONTENT-AUTHORIZATION-R2 Gate A — the two gaps as they behave without the
 * new context options (unchanged R1 semantics, which stay in force).
 *
 *  G-1  ContentActionAuthority rule "published content needs publish" refuses an
 *       edit-only actor's `edit` although the caller only prepares private data.
 *  G-2  ContentActionAuthority rule "managed content is refused" refuses even the
 *       owning extension, whatever its guard would say.
 */
class GapReproductionTest extends TestCase
{
    use AuthorizationR2Fixtures;
    use RefreshDatabase;

    public function test_g1_plain_edit_of_published_content_is_status_denied_for_edit_only(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published'], $actor);

        $this->assertSame(ContentActionReason::StatusDenied, $this->decide($actor, ContentAction::Edit, $post, 'en')->reason);
        $this->assertSame(ContentActionReason::StatusDenied, $this->decide($actor, ContentAction::EditTranslation, $post, 'en')->reason);
        // The Admin write authority agrees for a Core write.
        $this->assertFalse($this->adminAllows($actor, $post, ContentAction::Edit, 'en'));
    }

    public function test_g2_owner_plugin_without_owner_context_is_ownership_denied(): void
    {
        $this->setUpR2Site();
        [$owner, $decider] = $this->r2Plugin('r2-owner');
        $editor = $this->userWithRole('editor');
        $post = $this->managedPost($owner, $editor);

        foreach ([[ContentAction::Edit, 'en'], [ContentAction::Publish, null], [ContentAction::EditTranslation, 'en']] as [$action, $locale]) {
            $this->assertSame(ContentActionReason::OwnershipDenied, $decider->decide($editor, $action->value, $post->id, $locale)->reason, $action->value);
        }

        // ...while the write gate lets the owner's sanctioned write through.
        $this->actingAs($editor);
        $updated = $owner->update($post, ['locale' => 'en', 'title' => 'Owner write'], $post->revision, $editor);
        $this->assertSame('Owner write', $updated->translations()->where('locale', 'en')->value('title'));
    }

    public function test_g1_a_core_write_of_published_content_by_edit_only_is_refused_by_the_gate(): void
    {
        $this->setUpR2Site();
        $actor = $this->editOnly();
        $post = $this->makeContent(['status' => 'published'], $actor);
        $this->actingAs($actor);

        $this->expectException(ContentWriteDenied::class);
        $this->contents()->update($post, ['locale' => 'en', 'title' => 'x'], $post->revision, ContentWriteContext::interactive($actor));
    }
}
