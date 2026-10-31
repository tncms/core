<?php

declare(strict_types=1);

namespace Tests\Feature\ContentActionAuthorization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\ContentActionAuthorization\Concerns\ActionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentAction;
use TheNguyen\CMS\ContentAuthority\ContentActionAuthority;
use TheNguyen\CMS\ContentAuthority\ContentActionContext;
use TheNguyen\CMS\ContentAuthority\ContentActionDenied;
use TheNguyen\CMS\ContentAuthority\ContentActionReason;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-CONTENT-ACTION-AUTHORIZATION-R1 — public contract shape, bounded reason
 * codes, fail-closed inputs and the HTTP 403 mapping.
 */
final class ContentActionContractTest extends TestCase
{
    use ActionFixtures;
    use RefreshDatabase;

    public function test_the_service_is_bound_and_the_action_and_reason_sets_are_closed(): void
    {
        $this->assertInstanceOf(ContentActionAuthority::class, app('cms.content.actions'));
        $this->assertSame(app('cms.content.actions'), app(ContentActionAuthority::class));

        $this->assertSame(
            ['view', 'edit', 'publish', 'unpublish', 'create_translation', 'edit_translation'],
            array_map(static fn (ContentAction $a): string => $a->value, ContentAction::cases()),
        );
        $this->assertSame(
            ['allowed', 'unauthenticated', 'permission_denied', 'ownership_denied', 'status_denied', 'locale_denied', 'unsupported_action', 'unsupported_subject'],
            array_map(static fn (ContentActionReason $r): string => $r->value, ContentActionReason::cases()),
        );
    }

    public function test_an_allowed_decision_and_the_three_entry_points_agree(): void
    {
        $this->seedActionSite();
        $editor = $this->userWithRole('editor');
        $post = $this->makeContent(['status' => 'draft'], $editor);

        $decision = $this->decide($editor, ContentAction::Publish, $post);
        $this->assertTrue($decision->allowed());
        $this->assertFalse($decision->denied());
        $this->assertSame(ContentActionReason::Allowed, $decision->reason);
        $this->assertSame(ContentAction::Publish, $decision->action);

        $this->assertTrue($this->actions()->allows($editor, 'publish', $post));
        $this->assertSame(ContentActionReason::Allowed, $this->actions()->authorize($editor, ContentAction::Publish, $post->id)->reason);
    }

    public function test_guests_fail_closed_for_every_action(): void
    {
        $this->seedActionSite();
        $post = $this->makeContent(['status' => 'published']);

        foreach (ContentAction::cases() as $action) {
            $decision = $this->decide(null, $action, $post, 'en');
            $this->assertSame(ContentActionReason::Unauthenticated, $decision->reason, $action->value);
            $this->assertFalse($decision->allowed());
        }
    }

    public function test_unknown_or_page_builder_specific_actions_are_unsupported(): void
    {
        $this->seedActionSite();
        $admin = $this->superAdmin();
        $post = $this->makeContent([]);

        foreach (['edit_layout', 'change_page_builder_mode', 'delete', 'EDIT', '', 'view '] as $action) {
            $decision = $this->decide($admin, $action, $post);
            $this->assertSame(ContentActionReason::UnsupportedAction, $decision->reason, $action);
            $this->assertNull($decision->action);
        }

        $this->assertSame(ContentActionReason::UnsupportedAction, $this->decide(null, 'edit_layout', $post)->reason);
    }

    public function test_subjects_other_than_existing_posts_and_pages_are_unsupported(): void
    {
        $this->seedActionSite();
        $admin = $this->superAdmin();
        $other = $this->makeContent(['type' => 'product']);

        foreach ([
            'unsaved model' => new Content(['type' => 'post']),
            'other content type' => $other,
            'other type by id' => $other->id,
            'missing id' => 999999,
            'zero id' => 0,
            'numeric string' => (string) $other->id,
            'array' => ['id' => $other->id],
            'null' => null,
            'other model' => new Term,
        ] as $label => $subject) {
            $this->assertSame(ContentActionReason::UnsupportedSubject, $this->decide($admin, ContentAction::View, $subject)->reason, $label);
        }

        $page = $this->makeContent(['type' => 'page']);
        $this->assertTrue($this->decide($admin, ContentAction::Edit, $page)->allowed(), 'pages are supported');
        $this->assertTrue($this->decide($admin, ContentAction::Edit, $page->id)->allowed(), 'ids are supported');
    }

    public function test_the_persisted_row_is_judged_not_the_callers_model(): void
    {
        $this->seedActionSite();
        $author = $this->userWithRole('author');
        $post = $this->makeContent(['status' => 'draft']);

        $forged = Content::query()->findOrFail($post->id);
        $forged->author_id = $author->id;   // not saved
        $forged->status = 'draft';

        $this->assertSame(ContentActionReason::OwnershipDenied, $this->decide($author, ContentAction::Edit, $forged)->reason);
    }

    public function test_locale_context_is_validated_against_active_languages(): void
    {
        $this->seedActionSite();
        $editor = $this->userWithRole('editor');
        $post = $this->makeContent([], $editor); // en translation only

        $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::Edit, $post, 'fr')->reason, 'inactive');

        foreach (['xx', '', 'en-US-x-very-long', '../en', 'en;drop'] as $locale) {
            $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::Edit, $post, $locale)->reason, var_export($locale, true));
        }

        $this->assertTrue($this->decide($editor, ContentAction::Edit, $post, 'EN')->allowed(), 'codes are case-insensitive like the editor');
        $this->assertSame('en', ContentActionContext::forLocale(' EN ')->locale);
        $this->assertNull(ContentActionContext::none()->locale);

        // Translation actions need a locale and the matching existence.
        $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::CreateTranslation, $post)->reason);
        $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::EditTranslation, $post)->reason);
        $this->assertTrue($this->decide($editor, ContentAction::CreateTranslation, $post, 'vi')->allowed());
        $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::CreateTranslation, $post, 'en')->reason, 'already exists');
        $this->assertTrue($this->decide($editor, ContentAction::EditTranslation, $post, 'en')->allowed());
        $this->assertSame(ContentActionReason::LocaleDenied, $this->decide($editor, ContentAction::EditTranslation, $post, 'vi')->reason, 'missing');
    }

    public function test_authorize_throws_a_403_authorization_exception_that_leaks_nothing(): void
    {
        $this->seedActionSite();
        $author = $this->userWithRole('author');
        $post = $this->makeContent(['status' => 'draft', 'title' => 'Secret draft title'], $author);

        try {
            $this->actions()->authorize($author, ContentAction::Publish, $post);
            $this->fail('expected a denial');
        } catch (ContentActionDenied $e) {
            $this->assertInstanceOf(AuthorizationException::class, $e);
            $this->assertSame(ContentActionReason::PermissionDenied, $e->decision()->reason);
            $this->assertSame(403, $e->status());
            $this->assertStringNotContainsString('Secret', $e->getMessage());
            $this->assertStringNotContainsString((string) $post->id, $e->getMessage());
        }

        Route::middleware('web')->post('/_caa/publish/{id}', function (int $id) {
            app('cms.content.actions')->authorize(auth()->user(), ContentAction::Publish, $id);

            return response('ok');
        });

        $this->actingAs($author)->post('/_caa/publish/'.$post->id)->assertForbidden()->assertDontSee('Secret draft title');
        $this->actingAs($this->userWithRole('editor', ['posts.edit_others']))->post('/_caa/publish/'.$post->id)->assertOk();
    }
}
