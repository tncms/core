<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-EXTENSION-TRUST S2 — invocation authority vs viewer authority (brief
 * §3.1, §3.4, matrix T13). Editing content never grants a component's viewer
 * permission; the context is server-derived and the permission check is strict.
 */
final class TrustedComponentAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    private const PERMISSION = 's2_fixture.view_private';

    private object $provider;

    protected function setUp(): void
    {
        parent::setUp();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
        $this->seed(CmsRolePermissionSeeder::class);
        app('cms.permission')->register(self::PERMISSION, 'S2 fixture private panel', 'Testing', 'Fixture-only permission.');
        app('cms.permission')->syncDefaults();

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-auth');
        $this->activateComponentPlugins('s2-auth');
        $code = $this->pluginCode('s2-auth');
        $this->provider = $this->provider('s2-auth', 'private', '<p class="s2-private">private panel</p>', ['public'], ['@host'], self::PERMISSION, [], 'context');
        $this->assertTrue($code->register($this->provider));
        $this->assertTrue($code->register($this->provider('s2-auth', 'open', '<p class="s2-open">open</p>', ['public'], ['@host'])));
        $code->shortcode('s2_private', 's2-auth/private');
        $code->shortcode('s2_open', 's2-auth/open');

        app('cms.content')->create(['type' => 'page', 'status' => 'published', 'locale' => 'en', 'title' => 'Panel', 'slug' => 'panel', 'content' => '[s2_open][s2_private]']);
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        Role::query()->where('slug', $slug)->firstOrFail()->users()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    private function panel(): string
    {
        return (string) $this->get('/panel')->assertOk()->getContent();
    }

    public function test_guest_sees_the_open_component_only(): void
    {
        $html = $this->panel();

        $this->assertStringContainsString('<p class="s2-open">open</p>', $html);
        $this->assertStringNotContainsString('class="ctx"', $html);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_content_editor_rights_do_not_grant_the_component_permission(): void
    {
        // Administrator can edit every page, but was never granted the fixture permission.
        $this->actingAs($this->userWithRole('admin'));

        $html = $this->panel();

        $this->assertStringNotContainsString('class="ctx"', $html);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_unrelated_authenticated_user_is_denied(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertStringNotContainsString('class="ctx"', $this->panel());
        $this->assertSame([], $this->provider->calls);
    }

    public function test_explicitly_granted_viewer_renders_with_a_server_derived_context(): void
    {
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([Permission::query()->where('slug', self::PERMISSION)->value('id')]);
        $user = $this->userWithRole('admin');
        $this->actingAs($user);

        $html = $this->get('/panel?mode=public&invoker=s2-auth&user=1')->assertOk()->getContent();

        // Invoker = the owner itself: its shortcode callback lives in the plugin's own file.
        $this->assertStringContainsString('<p class="ctx">'.json_encode(['public', 's2-auth', 'en', true, $user->id]).'</p>', (string) $html);
        $this->assertCount(1, $this->provider->calls);
        // Query parameters never reach the provider as props or context.
        $this->assertSame([], $this->provider->calls[0]['props']);
    }

    public function test_super_admin_passes_the_strict_check(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        $this->assertStringContainsString('class="ctx"', $this->panel());
    }

    public function test_slot_responses_for_authenticated_users_are_never_cacheable(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));

        $this->assertSame('no-store, private', $this->get('/panel')->headers->get('Cache-Control'));
    }
}
