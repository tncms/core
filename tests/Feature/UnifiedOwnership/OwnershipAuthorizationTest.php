<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gates D and E: creating owned content and
 * adopting existing content are separate, hardened authorisations enforced
 * inside the Core service (not the UI).
 */
final class OwnershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
    }

    // ── Gate E: adoption ─────────────────────────────────────────────────────

    public function test_an_authorised_adopter_adopts_with_audit_and_core_render_default(): void
    {
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');
        $post = $this->coreArticle('Adopt Me');
        $this->actingAs($adopter = $this->adopter());

        $opened = $post->revision;
        $claimed = $owner->claim($post, $opened);

        $this->assertSame('uo-alpha', $claimed->managed_by);
        $this->assertSame($opened + 1, $claimed->revision);
        $this->assertSame(ContentFallbackPolicy::CoreRender, app('cms.content_ownership')->ownershipOf($post->id)->fallback);
        $event = $this->events($post->id)[0];
        $this->assertSame(['claimed', 'uo-alpha', null, 'uo-alpha', 'extension', $adopter->id], [$event['action'], $event['owner'], $event['previous_owner'], $event['new_owner'], $event['origin'], (int) $event['actor_id']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $event['correlation_id']);
    }

    public function test_every_required_negative_claim_fails_without_touching_the_content(): void
    {
        $this->guardedPlugin('uo-alpha');
        $this->guardedPlugin('uo-beta');
        $owner = $this->ownerCode('uo-alpha');
        $other = $this->ownerCode('uo-beta');
        $authorOfPost = $this->userWithRole('author');
        $cases = [];

        $post = $this->coreArticle('Target', $authorOfPost);
        $before = $this->persisted($post->id);

        // anonymous (no session, no actor)
        auth()->logout();
        $cases['anonymous'] = [$this->denial(fn () => $owner->claim($post, $post->revision)), 'unattributed_write'];

        // plugin-provided super-admin impersonation with no session (CLI/queue shape)
        $cases['plugin_passes_super_admin_no_session'] = [$this->denial(fn () => $owner->claim($post, $post->revision, $this->superAdmin())), 'ownership_requires_signed_in_user'];

        // forged acting user: signed in as a subscriber, plugin passes an adopter
        $this->actingAs($this->userWithRole('subscriber'));
        $cases['forged_actor'] = [$this->denial(fn () => $owner->claim($post, $post->revision, $this->adopter())), 'actor_not_signed_in'];

        // subscriber
        $cases['subscriber'] = [$this->denial(fn () => $owner->claim($post, $post->revision)), 'unauthorized_adopt'];

        // super-admin without the explicit grant (role name is not enough)
        $this->actingAs($this->superAdmin());
        $cases['super_admin_without_grant'] = [$this->denial(fn () => $owner->claim($post, $post->revision)), 'unauthorized_adopt'];

        // another author's content without edit-others (has the adopt grant)
        $this->actingAs($this->userWithRole('editor', ['content.ownership.adopt']));
        $cases['others_content_without_edit_others'] = [$this->denial(fn () => $owner->claim($post, $post->revision)), 'unauthorized_edit_others'];

        // stale revision
        $this->actingAs($this->adopter());
        $cases['stale_revision'] = [$this->denial(fn () => $owner->claim($post, $post->revision + 3)), 'revision_conflict'];

        $this->assertSame($before, $this->persisted($post->id), 'no partial change from any refused claim');

        // unsupported content type
        $faq = $this->makeContent(['title' => 'FAQ', 'type' => 'faq', 'status' => 'published']);
        $cases['unsupported_type'] = [$this->denial(fn () => $owner->claim($faq, $faq->revision)), 'unsupported_content_type'];

        // front page
        $front = $this->makeContent(['title' => 'Front', 'type' => 'page', 'status' => 'published']);
        settings()->set('reading.homepage_page_id', $front->id, 'integer');
        $cases['front_page'] = [$this->denial(fn () => $owner->claim($front, $front->revision)), 'front_page'];

        // already owned / cross-plugin
        $mine = $owner->claim($post, $post->revision);
        $cases['already_owned_same_plugin'] = [$this->denial(fn () => $owner->claim($mine, $mine->revision)), 'already_managed'];
        $cases['cross_plugin'] = [$this->denial(fn () => $other->claim($mine, $mine->revision)), 'already_managed'];
        $this->assertSame('uo-alpha', $mine->refresh()->managed_by);

        // missing guard (plugin never registered one)
        $this->activateAuthorityPlugins('uo-noguard');
        $loose = $this->coreArticle('Loose');
        $cases['plugin_without_guard'] = [$this->denial(fn () => $this->ownerCode('uo-noguard')->claim($loose, $loose->revision)), 'owner_inactive'];

        // deactivated plugin: its code can no longer mint an extension context
        app('cms.extension')->deactivatePlugin('uo-beta');
        app('cms.extension')->flushRegistry();
        $cases['deactivated_plugin'] = [$this->denial(fn () => $other->claim($loose, $loose->revision)), 'logic'];

        $actual = array_map(static fn ($c) => $c[0], $cases);
        $expected = array_map(static fn ($c) => $c[1], $cases);
        $this->assertSame($expected, $actual);
        $this->assertNull($loose->refresh()->managed_by);
    }

    public function test_the_adopt_permission_is_never_granted_by_default(): void
    {
        foreach (['super-admin', 'admin', 'editor', 'author', 'subscriber'] as $role) {
            $this->assertFalse(
                DB::table('cms_role_permissions')
                    ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_permissions.role_id')
                    ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_role_permissions.permission_id')
                    ->where('cms_roles.slug', $role)->where('cms_permissions.slug', 'content.ownership.adopt')->exists(),
                "{$role} must not hold content.ownership.adopt by default",
            );
        }

        $this->assertFalse(app('cms.permission')->userHasExplicitGrant($this->superAdmin(), 'content.ownership.adopt'));
        config(['cms.super_admin_emails' => ['root@example.test']]);
        $root = \App\Models\User::factory()->create(['email' => 'root@example.test']);
        $this->assertTrue(app('cms.permission')->isSuperAdmin($root));
        $this->assertFalse(app('cms.permission')->userHasExplicitGrant($root, 'content.ownership.adopt'), 'configured super admins do not imply it either');
    }

    public function test_operator_grant_procedure_enables_and_revokes_adoption(): void
    {
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');
        $editor = $this->userWithRole('editor', ['posts.edit_others']);
        $post = $this->coreArticle('Grant Flow');
        $this->actingAs($editor);
        $this->assertSame('unauthorized_adopt', $this->denial(fn () => $owner->claim($post, $post->revision)));

        $this->artisan('cms:content-ownership:adopters', ['action' => 'grant', 'user' => $editor->email])->assertSuccessful();
        app()->forgetInstance('cms.permission');
        $this->assertSame('uo-alpha', $owner->claim($post, $post->revision)->managed_by);

        $this->artisan('cms:content-ownership:adopters', ['action' => 'revoke', 'user' => (string) $editor->id])->assertSuccessful();
        app()->forgetInstance('cms.permission');
        $this->assertFalse(app('cms.permission')->userHasExplicitGrant($editor, 'content.ownership.adopt'));
    }

    // ── Gate D: creating owned content ──────────────────────────────────────

    public function test_owned_creation_needs_a_signed_in_actor_with_create_and_publish_rights(): void
    {
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');
        $published = ['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Owned', 'content' => '<p>x</p>'];
        $draft = ['status' => 'draft'] + $published;
        $count = fn () => DB::table('cms_contents')->count();
        $start = $count();

        auth()->logout();
        $this->assertSame('unattributed_write', $this->denial(fn () => $owner->own($published)), 'no session');
        $this->assertSame('ownership_requires_signed_in_user', $this->denial(fn () => $owner->createAs($published, $this->superAdmin())), 'plugin-chosen actor, no session');

        $this->actingAs($this->userWithRole('subscriber'));
        $this->assertSame('actor_not_signed_in', $this->denial(fn () => $owner->createAs($published, $this->superAdmin())), 'forged actor');
        $this->assertSame('unauthorized_create', $this->denial(fn () => $owner->own($published)), 'subscriber');

        $this->actingAs($this->userWithRole('author'));
        $this->assertSame('unauthorized_publish', $this->denial(fn () => $owner->own($published)), 'author cannot publish');
        $this->assertSame($start, $count(), 'nothing created by any refusal');

        $draftPost = $owner->own($draft);
        $this->assertSame('uo-alpha', $draftPost->managed_by, 'author may create a draft');

        $this->actingAs($editor = $this->userWithRole('editor'));
        $post = $owner->own($published, ContentFallbackPolicy::Temporary503);
        $this->assertSame('uo-alpha', $post->managed_by);
        $this->assertSame(ContentFallbackPolicy::Temporary503, app('cms.content_ownership')->ownershipOf($post->id)->fallback);
        $this->assertSame([['created', 'uo-alpha', null, 'uo-alpha', 'extension', $editor->id]], array_map(
            static fn ($e) => [$e['action'], $e['owner'], $e['previous_owner'], $e['new_owner'], $e['origin'], (int) $e['actor_id']],
            $this->events($post->id),
        ));

        $faq = ['type' => 'faq'] + $published;
        $this->assertSame('unsupported_content_type', $this->denial(fn () => $owner->own($faq)));
    }

    public function test_plugin_created_content_defaults_to_hide_404(): void
    {
        $this->guardedPlugin('uo-alpha');
        $this->actingAs($this->superAdmin());
        $post = $this->ownerCode('uo-alpha')->own(['type' => 'post', 'status' => 'draft', 'locale' => 'en', 'title' => 'Default', 'content' => 'x']);

        $this->assertSame(ContentFallbackPolicy::Hide404, app('cms.content_ownership')->ownershipOf($post->id)->fallback);
    }

    public function test_an_unauthenticated_public_request_cannot_create_or_publish_owned_content(): void
    {
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');
        $admin = $this->superAdmin();
        Route::middleware('web')->post('/__uo/submit', static function () use ($owner, $admin) {
            $results = [];
            foreach (['implicit' => null, 'impersonated' => $admin] as $label => $actor) {
                try {
                    $actor === null
                        ? $owner->own(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Spam', 'content' => 'x'])
                        : $owner->createAs(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Spam', 'content' => 'x'], $actor);
                    $results[$label] = 'ALLOWED';
                } catch (\TheNguyen\CMS\ContentAuthority\ContentWriteException $e) {
                    $results[$label] = $e->errorCode();
                }
            }

            return response()->json($results);
        })->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        app('router')->getRoutes()->refreshNameLookups();

        auth()->logout();
        $this->post('/__uo/submit')->assertOk()->assertExactJson([
            'implicit' => 'unattributed_write',
            'impersonated' => 'actor_not_signed_in',
        ]);
        $this->assertSame(0, DB::table('cms_contents')->where('managed_by', 'uo-alpha')->count());
    }

    public function test_an_extension_cannot_act_for_another_user_during_a_request_even_for_ordinary_writes(): void
    {
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');
        $post = $this->coreArticle('Ordinary');
        $admin = $this->superAdmin();
        Route::middleware('web')->post('/__uo/edit', static function () use ($owner, $post, $admin) {
            try {
                $owner->update($post, ['locale' => 'en', 'title' => 'Hijacked'], $post->revision, $admin);

                return 'ALLOWED';
            } catch (\TheNguyen\CMS\ContentAuthority\ContentWriteException $e) {
                return $e->errorCode();
            }
        })->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        app('router')->getRoutes()->refreshNameLookups();

        auth()->logout();
        $this->assertSame('actor_not_signed_in', $this->post('/__uo/edit')->assertOk()->getContent());
        $this->assertNotSame('Hijacked', $post->refresh()->translations()->where('locale', 'en')->value('title'));
    }
}
