<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Support\Account\AccountNavItem;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — theme-first account shell.
 *
 * Every account page extends the stable target cms::account.layout, which renders
 * theme::account.layout when the active theme ships it and Core's cms::account.shell
 * otherwise. Plugin pages (`@extends('cms::account.layout')`, e.g. Toplist) get the
 * theme shell with no plugin change.
 */
final class AccountShellThemeTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'tests/Fixtures/account-shell-theme';

    private function useTheme(string $path): void
    {
        View::replaceNamespace('theme', [base_path($path)]);
    }

    private function user(): User
    {
        return User::factory()->create(['name' => 'Shell Owner', 'email' => 'shell-owner@example.test']);
    }

    /** Render the fixture plugin page exactly as a plugin controller would (view() response). */
    private function pluginPage(): string
    {
        View::addNamespace('acctprobe', base_path('tests/Fixtures/account-plugin-probe'));
        // What the web group's ShareErrorsFromSession provides on a real plugin route.
        View::share('errors', new \Illuminate\Support\ViewErrorBag);

        return view('acctprobe::page')->render();
    }

    public function test_layout_keys_are_frozen(): void
    {
        $r = app('cms.system_view');
        $this->assertSame('theme::account.layout', $r->accountLayoutThemeView());
        $this->assertSame('cms::account.shell', $r->accountLayoutFallbackView());
        $this->assertSame('cms::account.shell', $r->accountLayout());
        $this->assertSame('cms::account.shell', $r->accountLayout(nested: true));
    }

    public function test_no_override_renders_core_shell_with_the_full_contract(): void
    {
        $this->actingAs($this->user());

        $this->get('/account/profile')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('class="tn-acc-shell"', false)
            ->assertSee('<meta name="robots" content="noindex,nofollow">', false)
            ->assertSee('shell-owner@example.test')
            ->assertSee('aria-current="page"', false)
            ->assertSee('action="'.route('cms.auth.logout').'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_valid_theme_layout_frames_core_pages_with_contract_intact(): void
    {
        $this->useTheme(self::THEME);
        add_action('cms.account.before', static fn () => print ('<i data-hook="before">hb</i>'));
        add_action('cms.account.sidebar.after', static fn () => print ('<i data-hook="sidebar-after">hs</i>'));
        $this->actingAs($this->user());

        $html = $this->withSession(['status' => 'Flash works'])->get('/account/security')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('data-marker="theme-account-shell"', false)
            ->assertDontSee('class="tn-acc-shell"', false)
            ->assertSee('Flash works')
            ->assertSee('data-hook="before"', false)
            ->assertSee('data-hook="sidebar-after"', false)
            ->assertSee('action="'.route('cms.auth.logout').'"', false)
            ->assertSee(route('cms.account.security.password'), false)
            ->getContent();

        // Navigation (Core items) with the active state on the current page.
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('cms.account.security'), '#').'"\s+aria-current="page"#', $html);
        $this->assertStringContainsString(route('cms.account.profile'), $html);
    }

    public function test_plugin_page_extending_the_stable_target_gets_the_theme_shell_unchanged(): void
    {
        $this->actingAs($this->user());
        $core = $this->pluginPage();
        $this->assertStringContainsString('probe-plugin-content', $core);
        $this->assertStringContainsString('class="tn-acc-shell"', $core);

        $this->useTheme(self::THEME);
        $themed = $this->pluginPage();
        $this->assertStringContainsString('probe-plugin-content', $themed);
        $this->assertStringContainsString('data-marker="theme-account-shell"', $themed);
        $this->assertStringContainsString(route('cms.account.profile'), $themed, 'Core nav present in plugin page');
        $this->assertStringContainsString('<title>Probe articles', $themed);
    }

    public function test_plugin_navigation_items_and_permission_filter_apply_in_the_theme_shell(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $this->useTheme(self::THEME);
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('probe', 'Probe articles', url('/account/articles'), 'doc', 60);
            $items[] = new AccountNavItem('editor-only', 'Editor tools', url('/account/editor'), 'doc', 70, permission: 'posts.create');

            return $items;
        });

        $subscriber = $this->user();
        $subscriber->roles()->sync([Role::query()->where('slug', 'subscriber')->value('id')]);
        $this->actingAs($subscriber);

        $this->get('/account')->assertOk()
            ->assertSee('Probe articles')
            ->assertDontSee('Editor tools');
    }

    public function test_a_root_relative_plugin_nav_url_is_marked_active_on_its_page(): void
    {
        // Plugins (e.g. Toplist) may build root-relative item URLs (/en/account/articles);
        // the active state must not depend on absolute vs root-relative form.
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('probe', 'Probe articles', '/account/profile', 'doc', 60);

            return $items;
        });
        $this->actingAs($this->user());

        $this->get('/account/profile')->assertOk();
        $active = array_values(array_filter(app('cms.account')->navigationItems(auth()->user(), request()), static fn ($i) => $i->active));
        $this->assertSame(['profile', 'probe'], array_map(static fn ($i) => $i->key, $active));
    }

    public function test_broken_theme_layout_falls_back_to_the_core_shell_for_core_pages(): void
    {
        $this->useTheme('tests/Fixtures/account-shell-broken');
        $this->actingAs($this->user());

        $this->get('/account/profile')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('class="tn-acc-shell"', false)
            ->assertDontSee('should-never-render-broken-shell', false);
    }

    public function test_theme_layout_with_a_missing_partial_falls_back_to_the_core_shell(): void
    {
        $this->useTheme('tests/Fixtures/account-shell-missing-partial');
        $this->actingAs($this->user());

        $this->get('/account/preferences')->assertOk()
            ->assertSee('class="tn-acc-shell"', false)
            ->assertDontSee('should-never-render-missing-partial-shell', false);
    }

    public function test_a_theme_layout_extending_the_stable_target_does_not_recurse(): void
    {
        $this->useTheme('tests/Fixtures/account-shell-nested');
        $this->actingAs($this->user());

        $this->get('/account')->assertOk()->assertSee('class="tn-acc-shell"', false);
    }

    public function test_theme_switch_changes_the_shell_on_the_next_render(): void
    {
        $this->actingAs($this->user());

        $this->useTheme(self::THEME);
        $this->get('/account/sessions')->assertSee('data-marker="theme-account-shell"', false);

        $this->useTheme('tests/Fixtures/system-view-theme'); // ships page overrides, no account layout
        $this->get('/account/security')->assertOk()->assertDontSee('data-marker="theme-account-shell"', false);
    }

    public function test_verify_email_is_theme_resolvable_and_noindex(): void
    {
        $this->app['config']->set('cms.auth.email_verification', true);
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $this->get('/email/verify')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee(route('verification.send'), false);

        $this->useTheme('tests/Fixtures/system-view-theme');
        $this->get('/email/verify')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('theme-verify-email-override', false);
    }
}
