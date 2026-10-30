<?php

declare(strict_types=1);

namespace Tests\Feature\ContentExtension;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\ContentExtension\Concerns\ContentExtensionFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\ContentOwnershipException;
use TheNguyen\CMS\Content\Extension\ContentOwnershipRegistry;
use TheNguyen\CMS\Content\Extension\OwnerState;

/**
 * CORE-CONTENT-EXT-R1 M1 — persistent content ownership: creation context,
 * owner-only writes, operator transfer, lifecycle survival.
 */
final class ContentOwnershipTest extends TestCase
{
    use ContentExtensionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCxLanguages();
        $this->useCxPlugins('cx-alpha', 'cx-beta');
    }

    private function registry(): ContentOwnershipRegistry
    {
        return app('cms.content_ownership');
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ContentOwnershipException $e) {
            return $e->reason;
        }

        $this->fail('expected ContentOwnershipException');
    }

    public function test_migration_is_additive_and_core_content_has_no_owner(): void
    {
        // CORE-UNIFIED-OWNERSHIP-R1: the owner lives only in cms_contents.managed_by;
        // the policy table is additive and carries no owner column.
        $this->assertTrue(Schema::hasColumn('cms_contents', 'managed_by'));
        $this->assertFalse(Schema::hasTable('cms_content_ownership'));
        $this->assertSame(
            ['content_id', 'fallback_policy', 'availability', 'retry_after', 'operator_assigned', 'created_at', 'updated_at'],
            Schema::getColumnListing('cms_content_owner_policies'),
        );

        $core = $this->corePost('Core Article');

        $this->assertNull($this->registry()->ownershipOf($core->id));
        $this->assertNull($this->registry()->markerFor($core->id));
        $this->assertSame(0, DB::table('cms_contents')->whereNotNull('managed_by')->count());
        $this->assertSame(0, DB::table('cms_content_owner_policies')->count());
    }

    public function test_plugin_created_content_is_owned_by_the_call_site_with_hide_404_default(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Managed One');

        $owned = $this->registry()->ownershipOf($post->id);
        $this->assertSame('cx-alpha', $owned->owner);
        $this->assertSame(ContentFallbackPolicy::Hide404, $owned->fallback);
        $this->assertSame(ContentAvailability::Available, $owned->availability);
        $this->assertSame('published', $post->status);
        $this->assertSame(OwnerState::Active, $this->registry()->markerFor($post->id)->state);

        $explicit = $this->cxPlugin('cx-alpha')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Two', 'slug' => 'two', 'content' => 'x',
        ], ContentFallbackPolicy::Temporary503);
        $this->assertSame(ContentFallbackPolicy::Temporary503, $this->registry()->ownershipOf($explicit->id)->fallback);
    }

    public function test_core_theme_or_test_code_cannot_create_owned_content_and_nothing_is_written(): void
    {
        $before = DB::table('cms_contents')->count();

        $this->assertSame('caller_not_active_plugin', $this->refusal(fn () => $this->registry()->createOwned([
            'type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'X', 'slug' => 'x', 'content' => 'x',
        ])));

        $this->assertSame($before, DB::table('cms_contents')->count());
    }

    public function test_inactive_plugin_code_cannot_create_owned_content(): void
    {
        $plugin = $this->cxPlugin('cx-beta');
        app('cms.extension')->deactivatePlugin('cx-beta');
        app('cms.extension')->flushRegistry();

        $this->assertSame('caller_not_active_plugin', $this->refusal(fn () => $plugin->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'X', 'slug' => 'x', 'content' => 'x',
        ])));
    }

    public function test_only_the_owner_can_change_state_or_release(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Owned By Alpha');
        $beta = $this->cxPlugin('cx-beta');

        $this->assertSame('not_owner', $this->refusal(fn () => $beta->availability($post->id, ContentAvailability::Unavailable)));
        $this->assertSame('not_owner', $this->refusal(fn () => $beta->fallback($post->id, ContentFallbackPolicy::CoreRender)));
        $this->assertSame('not_owner', $this->refusal(fn () => $beta->release($post->id)));
        $this->assertSame('caller_not_active_plugin', $this->refusal(fn () => $this->registry()->setAvailability($post->id, ContentAvailability::Unavailable)));

        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($post->id)->owner);
        $this->assertSame(ContentAvailability::Available, $this->registry()->ownershipOf($post->id)->availability);

        $alpha = $this->cxPlugin('cx-alpha');
        $alpha->availability($post->id, ContentAvailability::TemporarilyUnavailable, 5);
        $this->assertSame(60, $this->registry()->ownershipOf($post->id)->retryAfter, 'retry clamped to the minimum');
        $alpha->availability($post->id, ContentAvailability::TemporarilyUnavailable, 999999);
        $this->assertSame(86400, $this->registry()->ownershipOf($post->id)->retryAfter, 'retry clamped to the maximum');

        $alpha->release($post->id);
        $this->assertNull($this->registry()->ownershipOf($post->id));
    }

    public function test_core_content_cannot_be_claimed_through_any_plugin_api(): void
    {
        $core = $this->corePost('Core Owned Page');
        $alpha = $this->cxPlugin('cx-alpha');

        $this->assertSame('not_owner', $this->refusal(fn () => $alpha->availability($core->id, ContentAvailability::Unavailable)));
        $this->assertSame('operator_only', $this->refusal(fn () => $alpha->adminAssign($core->id, 'cx-alpha')));
        $this->assertNull($this->registry()->ownershipOf($core->id));
    }

    public function test_operator_transfer_requires_explicit_fallback_and_matching_current_owner(): void
    {
        $core = $this->corePost('Transfer Me');

        $op = ['--actor' => \Tests\Support\ContentFixtures::OWNER_ACTOR_EMAIL, '--reason' => 'test transfer'];
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $core->id, 'owner' => 'cx-alpha'] + $op)
            ->expectsOutputToContain('explicit --fallback')->assertFailed();
        $this->assertNull($this->registry()->ownershipOf($core->id));

        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $core->id, 'owner' => 'cx-alpha', '--fallback' => 'core_render'])
            ->expectsOutputToContain('--actor')->assertFailed(); // the operator must be named
        $this->assertNull($this->registry()->ownershipOf($core->id));

        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $core->id, 'owner' => 'cx-alpha', '--fallback' => 'core_render'] + $op)
            ->assertSuccessful();
        $owned = $this->registry()->ownershipOf($core->id);
        $this->assertSame('cx-alpha', $owned->owner);
        $this->assertSame(ContentFallbackPolicy::CoreRender, $owned->fallback);

        // Stale expectation (operator thinks it is still Core content) → refused, unchanged.
        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $core->id, 'owner' => 'cx-beta', '--fallback' => 'hide_404'] + $op)
            ->expectsOutputToContain('ownership_changed')->assertFailed();
        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($core->id)->owner);

        $this->artisan('cms:content-owner', ['action' => 'assign', 'content' => $core->id, 'owner' => 'cx-beta', '--fallback' => 'hide_404', '--from' => 'cx-alpha'] + $op)
            ->assertSuccessful();
        $this->assertSame('cx-beta', $this->registry()->ownershipOf($core->id)->owner);

        $this->artisan('cms:content-owner', ['action' => 'show', 'content' => $core->id])
            ->expectsOutputToContain('owner=cx-beta state=active fallback=hide_404')->assertSuccessful();

        $this->artisan('cms:content-owner', ['action' => 'release', 'content' => $core->id, '--from' => 'cx-alpha'] + $op)->assertFailed();
        $this->artisan('cms:content-owner', ['action' => 'release', 'content' => $core->id, '--from' => 'cx-beta'] + $op)->assertSuccessful();
        $this->assertSame(
            ['transferred', 'transferred', 'released'],
            DB::table('cms_content_authority_events')->where('content_id', $core->id)->orderBy('id')->pluck('action')->all(),
            'every operator change is audited',
        );
        $this->assertNull($this->registry()->ownershipOf($core->id));
    }

    public function test_concurrent_claims_cannot_overwrite_each_other(): void
    {
        $core = $this->corePost('Contested');
        $admin = app('cms.content_ownership_admin');
        $op = auth()->user();

        // Operator A and operator B both saw "Core content"; A commits first.
        $admin->assign($core->id, 'cx-alpha', ContentFallbackPolicy::Hide404, null, $op);
        $this->assertSame('ownership_changed', $this->refusal(fn () => $admin->assign($core->id, 'cx-beta', ContentFallbackPolicy::CoreRender, null, $op)));

        // Ownership set between B's read and B's write (compare-and-set on the locked row).
        $other = $this->corePost('Contested Two');
        DB::table('cms_contents')->where('id', $other->id)->update(['managed_by' => 'cx-beta']);
        $this->assertSame('ownership_changed', $this->refusal(fn () => $admin->assign($other->id, 'cx-alpha', ContentFallbackPolicy::CoreRender, null, $op)));

        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($core->id)->owner);
        $this->assertSame('cx-beta', $this->registry()->ownershipOf($other->id)->owner);
    }

    public function test_operator_refuses_unknown_plugin_unknown_content_and_the_front_page(): void
    {
        $admin = app('cms.content_ownership_admin');
        $op = auth()->user();
        $page = app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Front', 'slug' => 'front', 'content' => 'x'], \Tests\Support\ContentFixtures::context());

        $this->assertSame('unknown_plugin', $this->refusal(fn () => $admin->assign($page->id, 'no-such-plugin', ContentFallbackPolicy::CoreRender, null, $op)));
        $this->assertSame('unknown_plugin', $this->refusal(fn () => $admin->assign($page->id, '../cx-alpha', ContentFallbackPolicy::CoreRender, null, $op)));
        $this->assertSame('unknown_content', $this->refusal(fn () => $admin->assign(999999, 'cx-alpha', ContentFallbackPolicy::CoreRender, null, $op)));

        app('cms.settings')->set('reading.homepage_page_id', $page->id);
        $this->assertSame('front_page', $this->refusal(fn () => $admin->assign($page->id, 'cx-alpha', ContentFallbackPolicy::CoreRender, null, $op)));
        $this->assertNull($this->registry()->ownershipOf($page->id));
    }

    public function test_ownership_survives_deactivation_and_removal(): void
    {
        $this->writeCxPlugin('cx-gone');
        app('cms.extension')->flushRegistry();
        $this->assertTrue(app('cms.extension')->activatePlugin('cx-gone'));
        app('cms.extension')->flushRegistry();
        $this->cxPlugin('cx-gone')->registerGuard(); // owned content is governed by its owner's guard

        $alphaPost = $this->ownedPost('cx-alpha', 'Survives Deactivation');
        $gonePost = $this->ownedPost('cx-gone', 'Survives Removal');

        app('cms.extension')->deactivatePlugin('cx-alpha');
        app('cms.extension')->flushRegistry();
        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($alphaPost->id)->owner);
        $this->assertSame(OwnerState::Inactive, $this->registry()->markerFor($alphaPost->id)->state);

        app('cms.extension')->activatePlugin('cx-alpha');
        app('cms.extension')->flushRegistry();
        $this->assertSame(OwnerState::Active, $this->registry()->markerFor($alphaPost->id)->state);

        app('cms.extension')->deactivatePlugin('cx-gone');
        File::deleteDirectory($this->cxRoot().DIRECTORY_SEPARATOR.'cx-gone');
        app('cms.extension')->flushRegistry();
        $this->assertSame(OwnerState::Missing, $this->registry()->markerFor($gonePost->id)->state);
        $this->assertSame('cx-gone', $this->registry()->ownershipOf($gonePost->id)->owner);
    }

    public function test_soft_delete_keeps_ownership_and_force_delete_cascades(): void
    {
        $post = $this->ownedPost('cx-alpha', 'Trash Me');

        // Managed content changes only through the content service (Line B).
        $this->cxPlugin('cx-alpha')->delete($post);
        $this->assertNotNull($this->registry()->ownershipOf($post->id), 'soft delete keeps the owner');

        $post = \TheNguyen\CMS\Models\Content::withTrashed()->findOrFail($post->id);
        app('cms.content.gate')->sanction($post->id, fn () => $post->restore());
        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($post->id)->owner);

        app('cms.content.gate')->sanction($post->id, fn () => $post->forceDelete());
        $this->assertNull($this->registry()->ownershipOf($post->id));
        $this->assertSame(0, DB::table('cms_content_owner_policies')->where('content_id', $post->id)->count(), 'policy row cascades');
    }

    public function test_markers_are_batched_in_one_query(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->ownedPost('cx-alpha', 'Batch '.$i)->id;
        }
        $ids[] = $this->corePost('Batch Core')->id;

        $this->registry()->markersFor([]); // warm the table check
        DB::enableQueryLog();
        DB::flushQueryLog();
        $markers = $this->registry()->markersFor($ids);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'managed_by'));
        DB::disableQueryLog();

        $this->assertCount(5, $markers);
        $this->assertCount(1, $queries);
        $this->assertArrayNotHasKey(end($ids), $markers);
    }

    public function test_upgrade_window_and_migration_on_existing_content(): void
    {
        // CORE-UNIFIED-OWNERSHIP-R1: the upgrade window is now "policy table not
        // yet created" (managed_by exists since Line B's migration).
        $migration = require base_path('packages/thenguyen/cms-core/database/migrations/2026_10_30_000001_create_cms_content_owner_policies_table.php');
        $core = $this->corePost('Pre Upgrade Article');
        $before = DB::table('cms_contents')->orderBy('id')->get()->toJson();

        // New code, migration not yet applied: everything behaves as before.
        $migration->down();
        app()->forgetInstance('cms.content_ownership');
        app()->forgetInstance('cms.content_response');
        $this->get(parse_url(content_url($core, 'en'), PHP_URL_PATH))->assertOk();
        $this->assertSame(1, \TheNguyen\CMS\Models\Content::query()->published()->discoverable()->count());
        $this->assertSame([], app('cms.content_ownership')->markersFor([$core->id]));
        $early = $this->ownedPost('cx-alpha', 'Early');
        $this->assertSame('cx-alpha', app('cms.content_ownership')->ownershipOf($early->id)->owner);
        $this->assertSame(ContentFallbackPolicy::CoreRender, app('cms.content_ownership')->ownershipOf($early->id)->fallback, 'defaults without a policy row');
        $this->assertSame('unsupported', $this->refusal(fn () => $this->cxPlugin('cx-alpha')->availability($early->id, ContentAvailability::Unavailable)));

        // Applying it (twice) only adds the empty table; existing rows are untouched.
        $migration->up();
        $migration->up();
        app()->forgetInstance('cms.content_ownership');
        $this->assertSame(0, DB::table('cms_content_owner_policies')->count());
        $this->assertSame(
            json_decode($before, true),
            array_slice(json_decode(DB::table('cms_contents')->orderBy('id')->get()->toJson(), true), 0, count(json_decode($before, true))),
        );
        $this->assertNull(app('cms.content_ownership')->ownershipOf($core->id));
    }

    public function test_operator_fallback_is_locked_and_operator_service_refuses_web_requests(): void
    {
        $core = $this->corePost('Operator Locked');
        app('cms.content_ownership_admin')->assign($core->id, 'cx-alpha', ContentFallbackPolicy::Temporary503, null, auth()->user());

        $alpha = $this->cxPlugin('cx-alpha');
        $this->assertSame('operator_controlled', $this->refusal(fn () => $alpha->fallback($core->id, ContentFallbackPolicy::CoreRender)));
        $this->assertSame(ContentFallbackPolicy::Temporary503, $this->registry()->ownershipOf($core->id)->fallback);
        $alpha->availability($core->id, ContentAvailability::Unavailable); // availability stays the owner's

        // Plugin-created content keeps an owner-controlled fallback.
        $own = $this->ownedPost('cx-alpha', 'Plugin Fallback');
        $alpha->fallback($own->id, ContentFallbackPolicy::CoreRender);
        $this->assertSame(ContentFallbackPolicy::CoreRender, $this->registry()->ownershipOf($own->id)->fallback);

        $flag = new \ReflectionProperty(app(), 'isRunningInConsole');
        $flag->setValue(app(), false);
        try {
            $this->assertSame('operator_only', $this->refusal(fn () => app('cms.content_ownership_admin')->release($core->id, 'cx-alpha', auth()->user())));
        } finally {
            $flag->setValue(app(), true);
        }
        $this->assertSame('cx-alpha', $this->registry()->ownershipOf($core->id)->owner);
    }

    public function test_home_never_serves_an_owned_page(): void
    {
        // CORE-UNIFIED-OWNERSHIP-R1: the conventional home page can no longer
        // be created as owned content at all.
        $refused = null;
        try {
            $this->cxPlugin('cx-alpha')->create([
                'type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Owned Front Candidate', 'slug' => 'trang-chu', 'content' => '<p>owned front</p>',
            ]);
        } catch (\TheNguyen\CMS\ContentAuthority\ContentWriteDenied $e) {
            $refused = $e->errorCode();
        }
        $this->assertSame('front_page', $refused);

        // An owned page an operator later points the static front page at is still never served at /.
        $owned = $this->cxPlugin('cx-alpha')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Owned Front Candidate', 'slug' => 'owned-landing', 'content' => '<p>owned front</p>',
        ]);
        $this->cxPlugin('cx-alpha')->availability($owned->id, ContentAvailability::Unavailable);

        $this->get('/')->assertOk()->assertDontSee('owned front');

        app('cms.settings')->set('reading.homepage_display', 'static_page');
        app('cms.settings')->set('reading.homepage_page_id', $owned->id);
        $this->get('/')->assertOk()->assertDontSee('owned front');

        $core = app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Core Front', 'slug' => 'core-front', 'content' => '<p>core front body</p>'], \Tests\Support\ContentFixtures::context());
        app('cms.settings')->set('reading.homepage_page_id', $core->id);
        $this->get('/')->assertOk()->assertSee('core front body');
    }
}
