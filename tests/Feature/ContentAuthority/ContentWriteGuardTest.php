<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentOperation;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\ContentAuthority\ContentWriteException;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — vetoable write guard, durable
 * ownership marker, fail-closed owner, detach/recovery (T-A1…T-A6).
 */
final class ContentWriteGuardTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
    }

    private function managedPost(object $plugin, string $status = 'draft'): Content
    {
        $admin = $this->superAdmin();
        // CORE-UNIFIED-OWNERSHIP-R1: ownership changes need the signed-in actor.
        $this->actingAs($admin);

        return $plugin->createManaged([
            'type' => 'post', 'status' => $status, 'locale' => 'en',
            'title' => 'Managed article', 'content' => '<p>Structured projection</p>',
        ], $admin)->refresh();
    }

    private function assertDenied(string $code, callable $write): ContentWriteException
    {
        try {
            $write();
        } catch (ContentWriteException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());

            return $e;
        }

        $this->fail("Expected a content write refusal [{$code}].");
    }

    // --- registration authority -------------------------------------------------

    public function test_only_the_owner_plugin_can_register_its_guard_and_only_once(): void
    {
        $this->activateAuthorityPlugins('ca-alpha', 'ca-beta');
        $alphaGuard = $this->guardClass('ca-alpha');

        // Host code (the test) is not plugin code.
        $this->assertFalse(app('cms.content.guards')->register(new $alphaGuard));
        // Another plugin cannot register alpha's guard.
        $this->assertFalse($this->pluginCode('ca-beta')->registerGuard(new $alphaGuard));
        // The owner can, once.
        $this->assertTrue($this->pluginCode('ca-alpha')->registerGuard(new $alphaGuard));
        $this->assertFalse($this->pluginCode('ca-alpha')->registerGuard(new $alphaGuard));

        $this->assertSame(
            ['registration must come from the owner plugin', 'owner mismatch', 'duplicate guard'],
            array_column(app('cms.content.guards')->diagnostics(), 'reason'),
        );
        $this->assertSame(['ca-alpha'], app('cms.content.guards')->owners());
    }

    // --- marker -------------------------------------------------------------------

    public function test_extension_create_sets_the_marker_and_ordinary_content_has_none(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $managed = $this->managedPost($plugin);
        $ordinary = $this->makeContent(['title' => 'Ordinary']);

        $this->assertSame('ca-alpha', $managed->managed_by);
        $this->assertNull($ordinary->managed_by);
        $this->assertSame(1, DB::table('cms_content_authority_events')->where('content_id', $managed->id)->where('action', 'created')->count(), 'owned creation is audited as created (CORE-UNIFIED-OWNERSHIP-R1)');
        $this->assertSame(0, DB::table('cms_content_authority_events')->where('content_id', $ordinary->id)->count());
    }

    public function test_marker_cannot_be_spoofed_through_request_data_or_direct_eloquent(): void
    {
        $this->guardedPlugin('ca-alpha');
        $this->actingAs($admin = $this->superAdmin());

        // A request field named managed_by is ignored by the content service.
        $post = $this->contents()->create(['type' => 'post', 'locale' => 'en', 'title' => 'X', 'managed_by' => 'ca-alpha']);
        $this->assertNull($post->refresh()->managed_by);

        // A direct Eloquent write of the marker is refused by the model backstop.
        $this->assertDenied('unsanctioned_write', fn () => $post->forceFill(['managed_by' => 'ca-alpha'])->save());
        $this->assertNull(Content::query()->find($post->id)->managed_by);

        // A non-plugin caller cannot mint an extension context or claim.
        $this->expectException(LogicException::class);
        ContentWriteContext::extension($admin);
    }

    public function test_claim_needs_a_registered_guard_unmanaged_content_and_the_revision(): void
    {
        $this->activateAuthorityPlugins('ca-noguard');
        // CORE-UNIFIED-OWNERSHIP-R1: adoption needs the signed-in actor and an
        // explicit content.ownership.adopt grant (super-admin does not imply it).
        $admin = $this->userWithRole('super-admin', ['content.ownership.adopt']);
        $post = $this->makeContent(['title' => 'Existing post']);
        $this->actingAs($admin);

        $this->assertDenied('owner_inactive', fn () => $this->pluginCode('ca-noguard')->claim($post, $post->revision, $admin));

        $plugin = $this->guardedPlugin('ca-alpha');
        $this->assertDenied('revision_conflict', fn () => $plugin->claim($post, $post->revision + 7, $admin));

        $opened = $post->revision;
        $claimed = $plugin->claim($post, $opened, $admin);
        $this->assertSame('ca-alpha', $claimed->managed_by);
        $this->assertSame($opened + 1, $claimed->revision);
        $this->assertDatabaseHas('cms_content_authority_events', ['content_id' => $post->id, 'action' => 'claimed', 'owner' => 'ca-alpha', 'actor_id' => $admin->id]);

        $this->assertDenied('already_managed', fn () => $plugin->claim($claimed, $claimed->revision, $admin));
    }

    // --- guard decisions --------------------------------------------------------------

    public function test_guard_denial_rolls_back_every_write_and_fires_no_saved_hook(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha', 'allow');
        $post = $this->managedPost($plugin);
        $before = $this->contentState($post->id);
        $guard = $this->guardClass('ca-alpha');
        $guard::$mode = 'deny';

        $saved = 0;
        add_action('cms.content.saved', function () use (&$saved): void {
            $saved++;
        });

        $this->actingAs($this->superAdmin());
        $e = $this->assertDenied('guard_denied.locked', fn () => $this->contents()->update(
            $post, ['locale' => 'en', 'title' => 'Hijacked', 'term_ids' => []], $post->revision, ContentWriteContext::interactive(),
        ));

        $this->assertSame('ca-alpha', $e->owner());
        $this->assertSame('Structured article: edit it in the article editor.', $e->getMessage());
        $this->assertSame($before, $this->contentState($post->id));
        $this->assertSame(0, $saved);
    }

    public function test_guard_sees_the_locked_state_the_changes_and_the_server_derived_origin(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha', 'own-only');
        $admin = $this->superAdmin();
        $post = $this->managedPost($plugin);
        $guard = $this->guardClass('ca-alpha');
        // The extension acts for the signed-in user (CORE-UNIFIED-OWNERSHIP-R1).
        $this->actingAs($admin);

        // The owner's own write is recognised and allowed.
        $opened = $post->revision;
        $updated = $plugin->update($post, ['locale' => 'en', 'title' => 'Owner edit'], $opened, $admin);
        $intent = end($guard::$seen);
        $this->assertSame(ContentOperation::Update, $intent->operation);
        $this->assertTrue($intent->isFromExtension('ca-alpha'));
        $this->assertSame('Managed article', $intent->current['translations']['en']['title']);
        $this->assertSame(['title' => 'Owner edit'], $intent->changes['translations']['en']);
        $this->assertSame($opened, $intent->currentRevision);

        // The same edit from a Core admin adapter is someone else's write: denied.
        $this->actingAs($admin);
        $this->assertDenied('guard_denied.foreign_write', fn () => $this->contents()->update(
            $updated, ['locale' => 'en', 'title' => 'Admin edit'], $updated->revision, ContentWriteContext::interactive(),
        ));
        $this->assertSame('Owner edit', $updated->refresh()->translations()->where('locale', 'en')->value('title'));
    }

    public function test_guard_crash_and_malformed_decisions_fail_closed(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $post = $this->managedPost($plugin);
        $before = $this->contentState($post->id);
        $guard = $this->guardClass('ca-alpha');
        $this->actingAs($this->superAdmin());

        $guard::$mode = 'throw';
        $e = $this->assertDenied('guard_failed', fn () => $this->contents()->changeStatus($post, 'published', $post->revision, ContentWriteContext::interactive()));
        $this->assertStringNotContainsString('hunter2', $e->getMessage());

        $guard::$mode = 'malformed';
        $this->assertDenied('guard_invalid_decision', fn () => $this->contents()->changeStatus($post, 'published', $post->revision, ContentWriteContext::interactive()));

        $this->assertSame($before, $this->contentState($post->id));
    }

    public function test_every_sanctioned_entry_point_consults_the_guard(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha', 'deny');
        $guard = $this->guardClass('ca-alpha');
        $guard::$mode = 'allow';
        $post = $this->managedPost($plugin);
        $guard::$mode = 'deny';
        $before = $this->contentState($post->id);
        $this->actingAs($this->superAdmin());
        $ctx = ContentWriteContext::interactive();

        $this->assertDenied('guard_denied.locked', fn () => $this->contents()->update($post, ['locale' => 'vi', 'title' => 'Bản dịch'], $post->revision, $ctx));
        $this->assertDenied('guard_denied.locked', fn () => $this->contents()->update($post, ['term_ids' => [1]], $post->revision, $ctx));
        $this->assertDenied('guard_denied.locked', fn () => $this->contents()->changeStatus($post, 'published', $post->revision, $ctx));
        $this->assertDenied('guard_denied.locked', fn () => $this->contents()->delete($post, $post->revision, $ctx));

        $this->assertSame(
            ['update', 'update', 'status', 'delete'],
            array_map(static fn ($i) => $i->operation->value, array_slice($guard::$seen, -4)),
        );
        $this->assertSame($before, $this->contentState($post->id));
    }

    public function test_direct_eloquent_writes_to_managed_rows_and_translations_are_refused(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $post = $this->managedPost($plugin);
        $before = $this->contentState($post->id);

        $this->assertDenied('unsanctioned_write', fn () => $post->forceFill(['status' => 'published'])->save());
        $this->assertDenied('unsanctioned_write', fn () => Content::query()->find($post->id)->delete());
        $this->assertDenied('unsanctioned_write', fn () => ContentTranslation::query()->where('content_id', $post->id)->first()->update(['title' => 'x']));
        $this->assertDenied('unsanctioned_write', fn () => ContentTranslation::query()->create(['content_id' => $post->id, 'locale' => 'vi', 'slug' => 'x', 'title' => 'x']));

        $this->assertSame($before, $this->contentState($post->id));
    }

    // --- missing owner, legacy path, recovery --------------------------------------------

    public function test_inactive_owner_fails_closed_without_clearing_the_marker(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $post = $this->managedPost($plugin);
        $before = $this->contentState($post->id);

        app('cms.plugin_lifecycle')->deactivate('ca-alpha');
        app('cms.extension')->flushRegistry();
        $this->actingAs($this->superAdmin());

        $this->assertDenied('owner_inactive', fn () => $this->contents()->update($post, ['locale' => 'en', 'title' => 'Now ordinary?'], $post->revision, ContentWriteContext::interactive()));
        $this->assertDenied('owner_inactive', fn () => $this->contents()->delete($post, $post->revision, ContentWriteContext::interactive()));
        $this->assertDenied('owner_inactive', fn () => $this->contents()->changeStatus($post, 'draft', $post->revision, ContentWriteContext::system('demo_import')));

        $this->assertSame($before, $this->contentState($post->id));
        $this->assertSame('ca-alpha', $post->refresh()->managed_by);
    }

    public function test_legacy_signature_cannot_write_managed_content(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $post = $this->managedPost($plugin);
        $this->actingAs($this->superAdmin());

        $this->assertDenied('managed_requires_context', fn () => $this->contents()->update($post, ['locale' => 'en', 'title' => 'Legacy'], $post->revision));
        $this->assertDenied('revision_required', fn () => $plugin->update($post, ['locale' => 'en', 'title' => 'No token'], null, auth()->user()));
    }

    public function test_detach_requires_strict_recovery_permission_confirmation_reason_and_revision(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');
        $post = $this->managedPost($plugin);
        app('cms.plugin_lifecycle')->deactivate('ca-alpha');
        app('cms.extension')->flushRegistry();

        // An admin (all default grants) does NOT hold content.authority.recover.
        $this->actingAs($this->userWithRole('admin'));
        $this->assertDenied('unauthorized_recovery', fn () => $this->contents()->detachOwnership($post, $post->revision, 'ca-alpha', 'Plugin removed'));

        $this->actingAs($recoverer = $this->userWithRole('admin', ['content.authority.recover']));
        $this->assertDenied('confirmation_mismatch', fn () => $this->contents()->detachOwnership($post, $post->revision, 'ca-beta', 'Plugin removed'));
        $this->assertDenied('reason_required', fn () => $this->contents()->detachOwnership($post, $post->revision, 'ca-alpha', '  '));
        $this->assertDenied('revision_conflict', fn () => $this->contents()->detachOwnership($post, $post->revision - 1, 'ca-alpha', 'Plugin removed'));
        $this->assertSame('ca-alpha', $post->refresh()->managed_by);

        $detached = $this->contents()->detachOwnership($post, $post->revision, 'ca-alpha', 'Plugin removed');
        $this->assertNull($detached->managed_by);
        $this->assertDatabaseHas('cms_content_authority_events', [
            'content_id' => $post->id, 'action' => 'detached', 'owner' => 'ca-alpha', 'actor_id' => $recoverer->id, 'reason' => 'Plugin removed',
        ]);

        // Ordinary content again: the normal editor rules apply (the content was
        // written by someone else, so editing it needs posts.edit_others).
        $this->assertDenied('unauthorized_edit_others', fn () => $this->contents()->update($detached, ['locale' => 'en', 'title' => 'Recovered'], $detached->revision, ContentWriteContext::interactive()));
        $this->actingAs($this->userWithRole('admin', ['posts.edit_others']));
        $this->contents()->update($detached, ['locale' => 'en', 'title' => 'Recovered'], $detached->revision, ContentWriteContext::interactive());
        $this->assertSame('Recovered', $detached->translations()->where('locale', 'en')->value('title'));
    }

    public function test_an_active_owner_is_consulted_on_detach(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha', 'allow');
        $post = $this->managedPost($plugin);
        $this->guardClass('ca-alpha')::$mode = 'deny';
        $this->actingAs($this->superAdmin());

        $this->assertDenied('guard_denied.locked', fn () => $this->contents()->detachOwnership($post, $post->revision, 'ca-alpha', 'Take over'));
        $this->assertSame('ca-alpha', $post->refresh()->managed_by);
    }

    public function test_system_context_cannot_be_minted_by_plugins_or_for_unknown_reasons(): void
    {
        $plugin = $this->guardedPlugin('ca-alpha');

        try {
            $plugin->systemContext();
            $this->fail('A plugin minted a system context.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        ContentWriteContext::system('because_i_said_so');
    }
}
