<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\Feature\Account\Concerns\AccountRegistryFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Support\Account\AccountNavItem;

/**
 * CORE-P12-2/3 — Account Extension Registry (`cms.account.extensions`).
 *
 * A plugin registers only entries in its own namespace (`{slug}.{name}`); the
 * owner is derived from the registering call site, never from the descriptor.
 * Entries are validated, resolved per request (locale, permission, active
 * state) and isolated: a bad entry is dropped, healthy entries remain.
 */
final class AccountExtensionRegistryTest extends TestCase
{
    use AccountRegistryFixtures;
    use RefreshDatabase;

    private const CORE_KEYS = ['dashboard', 'profile', 'security', 'sessions', 'preferences'];

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useFixturePlugins();
        $this->activate('acc-alpha', 'acc-beta');
        $this->accountRoutes('acc-alpha.articles', 'account/alpha-articles');
        $this->accountRoutes('acc-beta.orders', 'account/beta-orders');
    }

    /** @param  array<string, mixed>  $overrides
     *  @return array<string, mixed> */
    private function alpha(array $overrides = []): array
    {
        return array_replace([
            'id' => 'acc-alpha.articles',
            'label' => static fn (): string => current_locale() === 'en' ? 'My articles' : 'Bài viết của tôi',
            'route' => 'acc-alpha.articles',
            'icon' => 'file',
            'priority' => 60,
        ], $overrides);
    }

    /** @return list<AccountNavItem> */
    private function items(?User $user = null, string $url = 'http://localhost/account'): array
    {
        $request = Request::create($url);
        $this->app->instance('request', $request);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        app('cms.language')->setCurrent(str_contains($url, '/en/') || str_ends_with($url, '/en') ? 'en' : 'vi');

        return app('cms.account')->navigationItems($user ?? User::factory()->create(), $request);
    }

    /** @param  list<AccountNavItem>  $items
     *  @return list<string> */
    private function keys(array $items): array
    {
        return array_map(static fn (AccountNavItem $i): string => $i->key, $items);
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return array_column(app('cms.account.extensions')->diagnostics(), 'reason');
    }

    // --- contract ---------------------------------------------------------

    public function test_binding_exists(): void
    {
        $this->assertInstanceOf(\TheNguyen\CMS\Account\AccountExtensionRegistry::class, app('cms.account.extensions'));
    }

    public function test_an_owner_entry_resolves_with_a_localized_label_url_and_active_state(): void
    {
        $this->assertTrue($this->nav('acc-alpha')->register($this->alpha()));

        $vi = collect($this->items(null, 'http://localhost/account/alpha-articles'))->firstWhere('key', 'acc-alpha.articles');
        $this->assertSame('Bài viết của tôi', $vi->label);
        $this->assertSame('/account/alpha-articles', $vi->url);
        $this->assertSame('file', $vi->icon);
        $this->assertTrue($vi->active);

        $en = collect($this->items(null, 'http://localhost/en/account/alpha-articles'))->firstWhere('key', 'acc-alpha.articles');
        $this->assertSame('My articles', $en->label);
        $this->assertSame('/en/account/alpha-articles', $en->url);
        $this->assertTrue($en->active);

        $elsewhere = collect($this->items(null, 'http://localhost/en/account/profile'))->firstWhere('key', 'acc-alpha.articles');
        $this->assertFalse($elsewhere->active);
    }

    public function test_active_patterns_cover_nested_pages_in_every_locale(): void
    {
        $this->nav('acc-alpha')->register($this->alpha(['active' => ['acc-alpha.articles', 'acc-alpha.articles.*']]));

        $this->assertTrue(collect($this->items(null, 'http://localhost/account/alpha-articles/5/edit'))->firstWhere('key', 'acc-alpha.articles')->active);
        $this->assertTrue(collect($this->items(null, 'http://localhost/en/account/alpha-articles/5/edit'))->firstWhere('key', 'acc-alpha.articles')->active);
        $this->assertFalse(collect($this->items(null, 'http://localhost/account/beta-orders'))->firstWhere('key', 'acc-alpha.articles')->active);
    }

    public function test_entries_render_in_the_core_shell_and_the_theme_shell(): void
    {
        $this->nav('acc-alpha')->register($this->alpha());
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account')->assertOk()
            ->assertSee('class="tn-acc-shell"', false)
            ->assertSee('Bài viết của tôi')
            ->assertSee('href="/account/alpha-articles"', false);

        View::replaceNamespace('theme', [base_path('tests/Fixtures/account-shell-theme')]);
        $this->actingAs($user)->get('/en/account/alpha-articles')->assertOk()
            ->assertSee('data-marker="theme-account-shell"', false)
            ->assertSee('My articles')
            ->assertSee('probe-plugin-content', false);
    }

    // --- ownership ----------------------------------------------------------

    public function test_a_plugin_cannot_register_in_another_plugins_namespace(): void
    {
        $this->assertFalse($this->nav('acc-beta')->register($this->alpha()));
        $this->assertFalse($this->nav('acc-beta')->registerViaContainer($this->alpha(['id' => 'acc-alpha.other'])));

        $this->assertNotContains('acc-alpha.articles', $this->keys($this->items()));
        $this->assertContains('namespace not owned by the registering plugin', $this->reasons());
    }

    public function test_a_plugin_cannot_register_core_or_unnamespaced_ids(): void
    {
        foreach (['profile', 'dashboard', 'core.profile', 'cms.account', 'acc-alpha', 'acc-alpha.', 'acc-alpha.Bad Id', 'acc-alpha.x/y'] as $id) {
            $this->assertFalse($this->nav('acc-alpha')->register($this->alpha(['id' => $id])), $id);
        }

        $this->assertSame(self::CORE_KEYS, $this->keys($this->items()));
    }

    public function test_code_outside_any_plugin_cannot_register(): void
    {
        $this->assertFalse(app('cms.account.extensions')->registerNavigation($this->alpha()));
        $this->assertContains('caller is not an active plugin', $this->reasons());
        $this->assertNotContains('acc-alpha.articles', $this->keys($this->items()));
    }

    public function test_non_plugin_code_running_inside_a_plugins_call_cannot_register_for_it(): void
    {
        // The closure is defined here (host/test code), merely invoked by acc-alpha's code.
        $registered = $this->nav('acc-alpha')->run(fn () => app('cms.account.extensions')->registerNavigation($this->alpha()));

        $this->assertFalse($registered);
        $this->assertContains('caller is not an active plugin', $this->reasons());
        $this->assertNotContains('acc-alpha.articles', $this->keys($this->items()));
    }

    public function test_an_entry_can_only_link_to_the_owners_own_routes(): void
    {
        foreach (['cms.account.profile', 'cms.account.sessions.logout-others', 'acc-beta.orders'] as $route) {
            $this->assertFalse($this->nav('acc-alpha')->register($this->alpha(['id' => 'acc-alpha.r'.crc32($route), 'route' => $route])), $route);
        }

        $this->assertContains('route not owned by the registering plugin', $this->reasons());
        $this->assertSame(self::CORE_KEYS, $this->keys($this->items()));
    }

    public function test_the_first_registration_of_an_id_wins_and_squatting_is_impossible(): void
    {
        $this->assertFalse($this->nav('acc-beta')->register($this->alpha(['label' => 'Squatter'])));
        $this->assertTrue($this->nav('acc-alpha')->register($this->alpha()));
        $this->assertFalse($this->nav('acc-alpha')->register($this->alpha(['label' => 'Second', 'route' => 'acc-alpha.articles.edit'])));

        $entry = collect($this->items())->firstWhere('key', 'acc-alpha.articles');
        $this->assertSame('Bài viết của tôi', $entry->label);
        $this->assertContains('duplicate id', $this->reasons());
    }

    public function test_a_legacy_filter_cannot_remove_or_replace_registry_entries(): void
    {
        $this->nav('acc-alpha')->register($this->alpha());
        $this->nav('acc-beta')->register(['id' => 'acc-beta.orders', 'label' => 'Orders', 'route' => 'acc-beta.orders', 'priority' => 70]);
        $this->nav('acc-beta')->filter(static function (array $items): array {
            $items = array_values(array_filter($items, static fn ($i) => ! str_starts_with($i->key, 'acc-alpha.')));
            $items[] = new AccountNavItem('acc-alpha.articles', 'Hijacked', 'https://evil.test', null, 1);

            return $items;
        });

        $items = $this->items();
        $this->assertSame([...self::CORE_KEYS, 'acc-alpha.articles', 'acc-beta.orders'], $this->keys($items));
        $this->assertSame('Bài viết của tôi', collect($items)->firstWhere('key', 'acc-alpha.articles')->label);
    }

    public function test_an_entry_of_a_deactivated_plugin_disappears(): void
    {
        $this->nav('acc-alpha')->register($this->alpha());
        $this->assertContains('acc-alpha.articles', $this->keys($this->items()));

        $this->deactivate('acc-alpha');

        $this->assertNotContains('acc-alpha.articles', $this->keys($this->items()));
        $this->actingAs(User::factory()->create())->get('/account')->assertOk()->assertDontSee('Bài viết của tôi');
    }

    public function test_the_registry_exposes_no_mutation_of_other_entries(): void
    {
        $registry = new \ReflectionClass(\TheNguyen\CMS\Account\AccountExtensionRegistry::class);
        $public = array_map(static fn ($m) => $m->getName(), $registry->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertEqualsCanonicalizing(['__construct', 'registerNavigation', 'resolveNavigation', 'reservedKeys', 'diagnostics'], $public);
    }

    // --- validation and fault isolation -------------------------------------

    public function test_invalid_descriptors_are_rejected_and_healthy_entries_remain(): void
    {
        $nav = $this->nav('acc-alpha');
        $bad = [
            'unknown field' => $this->alpha(['id' => 'acc-alpha.a1', 'html' => '<b>x</b>']),
            'missing label' => array_diff_key($this->alpha(['id' => 'acc-alpha.a2']), ['label' => 1]),
            'empty label' => $this->alpha(['id' => 'acc-alpha.a3', 'label' => '  ']),
            'label not text' => $this->alpha(['id' => 'acc-alpha.a4', 'label' => ['vi' => 'x']]),
            'missing route' => array_diff_key($this->alpha(['id' => 'acc-alpha.a5']), ['route' => 1]),
            'route parameters not scalar' => $this->alpha(['id' => 'acc-alpha.a6', 'parameters' => ['x' => ['y']]]),
            'icon' => $this->alpha(['id' => 'acc-alpha.a7', 'icon' => '<svg onload=x>']),
            'priority' => $this->alpha(['id' => 'acc-alpha.a8', 'priority' => '10; drop']),
            'active pattern' => $this->alpha(['id' => 'acc-alpha.a9', 'active' => ['acc-alpha/(.*)']]),
            'permission' => $this->alpha(['id' => 'acc-alpha.a10', 'permission' => ['posts.create']]),
            'visible not callable' => $this->alpha(['id' => 'acc-alpha.a11', 'visible' => 'yes']),
            'url field is not supported' => $this->alpha(['id' => 'acc-alpha.a12', 'url' => 'javascript:alert(1)']),
        ];

        foreach ($bad as $case => $descriptor) {
            $this->assertFalse($nav->register($descriptor), $case);
        }
        $this->assertTrue($nav->register($this->alpha()));

        $this->assertSame([...self::CORE_KEYS, 'acc-alpha.articles'], $this->keys($this->items()));
    }

    public function test_route_problems_drop_only_the_affected_entry(): void
    {
        $nav = $this->nav('acc-alpha');
        $nav->register($this->alpha(['id' => 'acc-alpha.missing', 'route' => 'acc-alpha.not-a-route']));
        $nav->register($this->alpha(['id' => 'acc-alpha.public', 'route' => 'acc-alpha.articles.open']));
        $nav->register($this->alpha(['id' => 'acc-alpha.needs-param', 'route' => 'acc-alpha.articles.edit']));
        $nav->register($this->alpha());

        $this->assertSame([...self::CORE_KEYS, 'acc-alpha.articles'], $this->keys($this->items()));
        $reasons = $this->reasons();
        $this->assertContains('route not found', $reasons);
        $this->assertContains('route is not behind account authentication', $reasons);
        $this->assertContains('route url could not be generated', $reasons);
    }

    public function test_throwing_callbacks_are_isolated_and_fail_closed(): void
    {
        $nav = $this->nav('acc-alpha');
        $nav->register($this->alpha(['id' => 'acc-alpha.label-throws', 'label' => static fn () => throw new \RuntimeException('label boom')]));
        $nav->register($this->alpha(['id' => 'acc-alpha.visible-throws', 'visible' => static fn () => throw new \RuntimeException('visible boom')]));
        $nav->register($this->alpha(['id' => 'acc-alpha.badge-throws', 'badge' => static fn () => throw new \RuntimeException('badge boom')]));
        $nav->register($this->alpha(['id' => 'acc-alpha.badge-bad', 'badge' => static fn () => ['x']]));
        $nav->register($this->alpha(['id' => 'acc-alpha.label-not-string', 'label' => static fn () => ['x']]));
        $nav->register($this->alpha(['badge' => static fn (?User $u) => 7]));

        $items = $this->items();
        $this->assertSame([...self::CORE_KEYS, 'acc-alpha.articles', 'acc-alpha.badge-bad', 'acc-alpha.badge-throws'], $this->keys($items));
        $this->assertSame('7', collect($items)->firstWhere('key', 'acc-alpha.articles')->badge);
        $this->assertNull(collect($items)->firstWhere('key', 'acc-alpha.badge-throws')->badge);
        $this->assertNull(collect($items)->firstWhere('key', 'acc-alpha.badge-bad')->badge);

        $this->actingAs(User::factory()->create())->get('/account')->assertOk();
    }

    public function test_diagnostics_carry_no_user_data_or_exception_text(): void
    {
        $this->nav('acc-alpha')->register($this->alpha(['visible' => static fn () => throw new \RuntimeException('secret-token-123 user@example.test')]));
        $this->items(User::factory()->create(['email' => 'private@example.test']));

        $dump = json_encode(app('cms.account.extensions')->diagnostics());
        $this->assertStringNotContainsString('secret-token-123', $dump);
        $this->assertStringNotContainsString('private@example.test', $dump);
        $this->assertStringContainsString('visible callback failed', $dump);
    }

    public function test_labels_and_badges_are_escaped_in_the_shell(): void
    {
        $this->nav('acc-alpha')->register($this->alpha(['label' => '<script>alert(1)</script>', 'badge' => static fn () => '<i>9</i>']));

        $html = $this->actingAs(User::factory()->create())->get('/account')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<i>9</i>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    // --- authorization ------------------------------------------------------

    public function test_permission_and_visibility_hide_entries_without_granting_access(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $this->nav('acc-alpha')->register($this->alpha(['id' => 'acc-alpha.restricted', 'route' => 'acc-alpha.articles.restricted', 'permission' => 'posts.create']));
        $this->nav('acc-alpha')->register($this->alpha(['visible' => static fn (?User $u): bool => $u !== null && str_ends_with($u->email, '@editor.test')]));

        $subscriber = User::factory()->create(['email' => 'sub@example.test']);
        $subscriber->roles()->sync([Role::query()->where('slug', 'subscriber')->value('id')]);
        $editor = User::factory()->create(['email' => 'ed@editor.test']);
        $editor->roles()->sync([Role::query()->where('slug', 'editor')->value('id')]);

        $this->assertSame(self::CORE_KEYS, $this->keys($this->items($subscriber)));
        $this->assertSame([...self::CORE_KEYS, 'acc-alpha.articles', 'acc-alpha.restricted'], $this->keys($this->items($editor)));

        // Visibility is not authorization: the destination routes decide on their own.
        $this->actingAs($subscriber)->get('/account/alpha-articles/restricted')->assertForbidden();
        $this->actingAs($editor)->get('/account/alpha-articles/restricted')->assertOk();
        auth()->logout();
        $this->get('/account/alpha-articles')->assertRedirect();
    }

    public function test_a_revoked_permission_hides_the_entry_on_the_next_resolution(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $this->nav('acc-alpha')->register($this->alpha(['permission' => 'posts.create']));
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', 'editor')->value('id')]);
        $this->assertContains('acc-alpha.articles', $this->keys($this->items($user)));

        $user->roles()->sync([Role::query()->where('slug', 'subscriber')->value('id')]);
        $user = $user->fresh();
        app('cms.permission')->flushResolutionCache();

        $this->assertNotContains('acc-alpha.articles', $this->keys($this->items($user)));
    }

    public function test_the_guest_is_redirected_and_sees_no_navigation(): void
    {
        $this->nav('acc-alpha')->register($this->alpha());

        $this->get('/account')->assertRedirect();
        $this->get('/en/account/alpha-articles')->assertRedirect();
    }

    // --- ordering and performance -------------------------------------------

    public function test_ordering_is_deterministic(): void
    {
        $this->nav('acc-beta')->register(['id' => 'acc-beta.orders', 'label' => 'Orders', 'route' => 'acc-beta.orders', 'priority' => 20]);
        $this->nav('acc-alpha')->register($this->alpha(['id' => 'acc-alpha.zeta', 'priority' => 20]));
        $this->nav('acc-alpha')->register($this->alpha(['priority' => 20]));
        $this->nav('acc-alpha')->filter(static function (array $items): array {
            $items[] = new AccountNavItem('legacy-early', 'Legacy', '/account/legacy', null, 20);

            return $items;
        });

        // Equal priority: Core first, then registry entries by id, then legacy items.
        $this->assertSame(
            ['dashboard', 'profile', 'acc-alpha.articles', 'acc-alpha.zeta', 'acc-beta.orders', 'legacy-early', 'security', 'sessions', 'preferences'],
            $this->keys($this->items()),
        );
    }

    public function test_resolution_queries_do_not_grow_with_the_number_of_entries(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', 'editor')->value('id')]);
        $this->nav('acc-alpha')->register($this->alpha(['permission' => 'posts.create']));

        $count = function () use ($user): int {
            $this->items($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->items($user);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();
        for ($i = 0; $i < 40; $i++) {
            $this->nav('acc-alpha')->register($this->alpha(['id' => 'acc-alpha.n'.$i, 'permission' => 'posts.create']));
        }

        $this->assertSame($one, $count());
    }
}
