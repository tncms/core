<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gate I: Line B's edit-conflict and publish
 * protections hold on the unified authority, including across the ownership
 * APIs.
 */
final class ConcurrencyPublishTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
        $this->guardedPlugin('uo-alpha');
        $this->guardedPlugin('uo-beta');
    }

    private function title(Content $c): ?string
    {
        return $c->translations()->where('locale', 'en')->value('title');
    }

    public function test_two_editors_the_second_stale_write_loses_nothing(): void
    {
        $post = $this->coreArticle('Shared');
        $opened = $post->revision;
        $one = $this->userWithRole('admin', ['posts.edit_others', 'posts.publish']);
        $two = $this->userWithRole('admin', ['posts.edit_others', 'posts.publish']);

        $this->actingAs($one);
        app('cms.content')->update(Content::find($post->id), ['locale' => 'en', 'title' => 'First'], $opened, ContentWriteContext::interactive());
        $this->actingAs($two);
        $this->assertSame('revision_conflict', $this->denial(fn () => app('cms.content')->update(Content::find($post->id), ['locale' => 'en', 'title' => 'Second'], $opened, ContentWriteContext::interactive())));
        $this->assertSame('First', $this->title($post));
    }

    public function test_editor_vs_owning_plugin_and_two_plugin_operations(): void
    {
        $this->actingAs($this->superAdmin());
        $alpha = $this->ownerCode('uo-alpha');
        $this->guardClass('uo-alpha')::$mode = 'own-only';
        $post = $alpha->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Managed', 'content' => 'x']);
        $opened = $post->revision;

        // Editor (even super admin) cannot write managed content past the owner's guard.
        $this->assertSame('guard_denied.foreign_write', $this->denial(fn () => app('cms.content')->update(Content::find($post->id), ['locale' => 'en', 'title' => 'Editor'], $opened, ContentWriteContext::interactive())));

        // Two operations of the owner from the same revision: the second is stale.
        $alpha->update(Content::find($post->id), ['locale' => 'en', 'title' => 'Owner 1'], $opened);
        $this->assertSame('revision_conflict', $this->denial(fn () => $alpha->update(Content::find($post->id), ['locale' => 'en', 'title' => 'Owner 2'], $opened)));

        // Another plugin's write is foreign to the owner's guard.
        $fresh = Content::find($post->id);
        $this->assertSame('guard_denied.foreign_write', $this->denial(fn () => $this->ownerCode('uo-beta')->update($fresh, ['locale' => 'en', 'title' => 'Beta'], $fresh->revision)));
        $this->assertSame('Owner 1', $this->title($post));
    }

    public function test_unauthorised_and_stale_publishing_is_refused(): void
    {
        $author = $this->userWithRole('author');
        $this->actingAs($this->superAdmin());
        $alpha = $this->ownerCode('uo-alpha');
        $post = $alpha->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Publish Me', 'content' => 'x', 'author_id' => $author->id]);

        // The author's own draft: editing is allowed, publishing is not.
        $this->actingAs($author);
        $this->assertSame('unauthorized_publish', $this->denial(fn () => $alpha->publish(Content::find($post->id), $post->revision)));

        $this->actingAs($this->userWithRole('editor', ['posts.edit_others']));
        $this->assertSame('revision_conflict', $this->denial(fn () => $alpha->publish(Content::find($post->id), $post->revision + 1)));
        $published = $alpha->publish(Content::find($post->id), $post->revision);
        $this->assertSame('published', $published->status);
    }

    public function test_owner_deactivated_mid_operation_fails_closed_without_partial_writes(): void
    {
        $this->actingAs($this->superAdmin());
        $alpha = $this->ownerCode('uo-alpha');
        $post = $alpha->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Before', 'content' => 'x']);
        $before = $this->persisted($post->id);

        app('cms.extension')->deactivatePlugin('uo-alpha');
        app('cms.extension')->flushRegistry();

        $this->assertSame('owner_inactive', $this->denial(fn () => app('cms.content')->update(Content::find($post->id), ['locale' => 'en', 'title' => 'After'], $post->revision, ContentWriteContext::interactive())));
        $this->assertSame($before, $this->persisted($post->id));
    }

    public function test_a_failing_unit_rolls_back_ownership_writes_too(): void
    {
        $this->actingAs($this->superAdmin());
        $alpha = $this->ownerCode('uo-alpha');
        $post = $alpha->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Unit', 'content' => 'x']);
        $before = $this->persisted($post->id);

        try {
            app('cms.content')->transaction($post, $post->revision, ContentWriteContext::interactive(), function (Content $locked): void {
                app('cms.content')->update($locked, ['locale' => 'en', 'title' => 'Half'], null, ContentWriteContext::interactive());
                throw new \RuntimeException('boom');
            });
        } catch (\Throwable) {
        }

        $this->assertSame($before, $this->persisted($post->id));
    }
}
