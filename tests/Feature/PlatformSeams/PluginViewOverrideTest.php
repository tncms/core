<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;

/**
 * CORE-PLATFORM-SEAMS-R1 Phase 1 — theme-first plugin views:
 * active theme → parent theme → plugin views → controlled failure, following
 * the theme authority and the plugin lifecycle.
 */
final class PluginViewOverrideTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useSeamPluginsRoot();
        $this->writeSeamPlugin('seam-views');
        $this->writeSeamPlugin('seam-other');
    }

    protected function tearDown(): void
    {
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    public function test_plugin_views_resolve_without_any_theme_override(): void
    {
        $this->makeSeamTheme('seam-plain');
        $this->bootSeamPlugins('seam-views');

        $this->assertSame('PLUGIN-PAGE|seam-views|'.app()->getLocale().'|PLUGIN-CARD|seam-views', view('seam-views::public.page')->render());
    }

    public function test_active_theme_overrides_per_view_and_falls_back_for_the_rest(): void
    {
        $this->makeSeamTheme('seam-override', [
            'plugins/seam-views/public/card.blade.php' => 'THEME-CARD|{{ app()->getLocale() }}',
        ]);
        $this->bootSeamPlugins('seam-views');

        app()->setLocale('en');
        $this->assertSame('PLUGIN-PAGE|seam-views|en|THEME-CARD|en', view('seam-views::public.page')->render());
        $this->assertSame('en', app()->getLocale(), 'locale preserved');
    }

    public function test_child_theme_then_parent_theme_then_plugin(): void
    {
        $this->makeSeamTheme('seam-parent', [
            'plugins/seam-views/public/card.blade.php' => 'PARENT-CARD',
            'plugins/seam-views/public/page.blade.php' => 'PARENT-PAGE|@include("seam-views::public.card")',
        ], activate: false);
        $this->makeSeamTheme('seam-child', [
            'plugins/seam-views/public/card.blade.php' => 'CHILD-CARD',
        ], parent: 'seam-parent');
        $this->bootSeamPlugins('seam-views');

        $this->assertSame('PARENT-PAGE|CHILD-CARD', view('seam-views::public.page')->render());
        $this->assertCount(3, app('cms.plugin_views')->paths('seam-views'));
    }

    public function test_overrides_follow_a_theme_switch_in_the_same_process(): void
    {
        $this->makeSeamTheme('seam-a', ['plugins/seam-views/public/card.blade.php' => 'A-CARD']);
        $this->bootSeamPlugins('seam-views');
        $this->assertStringContainsString('A-CARD', view('seam-views::public.page')->render());

        $this->makeSeamTheme('seam-b', []);
        $this->assertTrue(app('cms.theme')->activate('seam-b'));
        $this->assertStringContainsString('PLUGIN-CARD', view('seam-views::public.page')->render());
    }

    public function test_override_is_scoped_to_its_plugin_slug(): void
    {
        $this->makeSeamTheme('seam-scope', ['plugins/seam-views/public/card.blade.php' => 'SCOPED']);
        $this->bootSeamPlugins('seam-views', 'seam-other');

        $this->assertStringContainsString('SCOPED', view('seam-views::public.card')->render());
        $this->assertSame('PLUGIN-CARD|seam-other', view('seam-other::public.card')->render());
    }

    public function test_inactive_plugin_and_its_overrides_never_resolve(): void
    {
        $this->makeSeamTheme('seam-inactive', ['plugins/seam-views/public/card.blade.php' => 'ORPHAN-OVERRIDE']);

        // Never activated: no namespace at all, even though the theme ships an override.
        $this->assertFalse(View::exists('seam-views::public.card'));

        $this->bootSeamPlugins('seam-views');
        $this->assertTrue(View::exists('seam-views::public.card'));

        app('cms.extension')->deactivatePlugin('seam-views');
        $this->assertFalse(View::exists('seam-views::public.card'));
        $this->assertSame([], app('cms.plugin_views')->paths('seam-views'));
    }

    public function test_missing_view_is_a_controlled_failure(): void
    {
        $this->makeSeamTheme('seam-missing');
        $this->bootSeamPlugins('seam-views');

        $this->expectException(InvalidArgumentException::class);
        view('seam-views::public.does-not-exist')->render();
    }

    public function test_symlinked_override_escaping_the_theme_is_ignored(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-seam-escape-'.getmypid();
        File::ensureDirectoryExists($outside.'/public');
        File::put($outside.'/public/card.blade.php', 'ESCAPED');

        $dir = $this->makeSeamTheme('seam-escape');
        File::ensureDirectoryExists($dir.'/views/plugins');
        $link = $dir.'/views/plugins/seam-views';

        $linked = DIRECTORY_SEPARATOR === '\\'
            ? (bool) @exec('cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link)).' '.escapeshellarg(str_replace('/', '\\', $outside)).' 2>NUL')
            : @symlink($outside, $link);

        if (! $linked || ! is_dir($link)) {
            $this->markTestSkipped('cannot create a directory link on this host');
        }

        try {
            app('cms.theme')->registerViews();
            $this->bootSeamPlugins('seam-views');

            $this->assertSame('PLUGIN-CARD|seam-views', view('seam-views::public.card')->render());
        } finally {
            DIRECTORY_SEPARATOR === '\\' ? @exec('cmd /c rmdir '.escapeshellarg(str_replace('/', '\\', $link))) : @unlink($link);
            File::deleteDirectory($outside);
        }
    }

    public function test_a_plugin_slug_cannot_take_over_an_existing_namespace(): void
    {
        $this->makeSeamTheme('seam-ns');
        View::addNamespace('seam-views', base_path('themes/seam-ns/views'));

        $this->bootSeamPlugins('seam-views');

        // Foreign hint kept first, plugin views appended exactly as before R1, no overrides.
        $hints = View::getFinder()->getHints()['seam-views'];
        $this->assertSame(base_path('themes/seam-ns/views'), $hints[0]);
        $this->assertCount(2, $hints);
        $this->assertStringEndsWith('resources'.DIRECTORY_SEPARATOR.'views', $hints[1]);
        $this->assertSame([], app('cms.plugin_views')->paths('seam-views'));
    }

    public function test_provider_registered_own_namespace_still_gets_theme_overrides(): void
    {
        // Business Reviews shape: the plugin provider calls loadViewsFrom(own views, slug).
        $this->makeSeamTheme('seam-br', ['plugins/seam-views/public/card.blade.php' => 'BR-THEME-CARD']);
        View::addNamespace('seam-views', $this->seamRoot().DIRECTORY_SEPARATOR.'seam-views'.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views');

        $this->bootSeamPlugins('seam-views');

        $this->assertSame('PLUGIN-PAGE|seam-views|'.app()->getLocale().'|BR-THEME-CARD', view('seam-views::public.page')->render());
        $this->assertCount(2, app('cms.plugin_views')->paths('seam-views'), 'override + one (deduplicated) plugin hint');
    }

    public function test_compiled_view_cache_is_compatible(): void
    {
        $this->makeSeamTheme('seam-cache', ['plugins/seam-views/public/card.blade.php' => 'CACHED-THEME-CARD']);
        $this->bootSeamPlugins('seam-views');

        $first = view('seam-views::public.page')->render();
        Artisan::call('view:clear');
        $this->assertSame($first, view('seam-views::public.page')->render());
        $this->assertStringContainsString('CACHED-THEME-CARD', $first);
    }

    public function test_late_hints_survive_theme_refresh_and_reserved_slugs_get_no_overrides(): void
    {
        $this->makeSeamTheme('seam-late', ['plugins/seam-views/public/card.blade.php' => 'LATE-THEME-CARD']);
        $this->bootSeamPlugins('seam-views');

        $late = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-seam-late-'.getmypid();
        File::ensureDirectoryExists($late);
        File::put($late.'/extra.blade.php', 'LATE-EXTRA');
        View::addNamespace('seam-views', $late);

        app('cms.theme')->registerViews(); // refresh
        $this->assertSame('LATE-EXTRA', view('seam-views::extra')->render(), 'late hint kept');
        $this->assertStringContainsString('LATE-THEME-CARD', view('seam-views::public.card')->render());
        File::deleteDirectory($late);

        $this->writeSeamPlugin('mail');
        $this->makeSeamTheme('seam-reserved', ['plugins/mail/public/card.blade.php' => 'HIJACK']);
        $this->bootSeamPlugins('mail');
        $this->assertSame([], app('cms.plugin_views')->paths('mail'));
        $this->assertStringNotContainsString('HIJACK', implode('|', View::getFinder()->getHints()['mail'] ?? []));

        app('cms.extension')->deactivatePlugin('mail');
        $this->assertFalse(View::exists('mail::public.card'), 'legacy registration is forgotten on deactivation');
    }

    public function test_public_helper_resolves_theme_first_and_reports_its_source(): void
    {
        $this->makeSeamTheme('seam-helper', ['plugins/seam-views/public/card.blade.php' => 'HELPER-THEME-CARD|{{ $who }}']);
        $this->bootSeamPlugins('seam-views', 'seam-other');

        $this->assertSame('HELPER-THEME-CARD|ada', cms_plugin_view('seam-views', 'public.card', ['who' => 'ada'])->render());
        $this->assertSame('theme', app('cms.plugin_views')->source('seam-views', 'public.card'));
        $this->assertSame('plugin', app('cms.plugin_views')->source('seam-views', 'public.page'));
        $this->assertSame('plugin', app('cms.plugin_views')->source('seam-other', 'public.card'), 'same relative name, other plugin, isolated');
        $this->assertSame('plugins/seam-views/public/card.blade.php', cms_plugin_view_override_path('seam-views', 'public.card'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function maliciousViews(): array
    {
        return [
            'traversal' => ['seam-views', '../../.env', 'invalid_view'],
            'dotdot' => ['seam-views', 'public..card', 'invalid_view'],
            'absolute unix' => ['seam-views', '/etc/passwd', 'invalid_view'],
            'absolute windows' => ['seam-views', 'C:\\Windows\\win', 'invalid_view'],
            'stream wrapper' => ['seam-views', 'php://filter', 'invalid_view'],
            'null byte' => ['seam-views', "public.card\0x", 'invalid_view'],
            'namespace injection' => ['seam-views', 'other::public.card', 'invalid_view'],
            'slash path' => ['seam-views', 'public/card', 'invalid_view'],
            'uppercase' => ['seam-views', 'Public.Card', 'invalid_view'],
            'empty view' => ['seam-views', '', 'invalid_view'],
            'bad plugin id' => ['../seam-views', 'public.card', 'invalid_plugin'],
            'reserved plugin id' => ['theme', 'layouts.master', 'invalid_plugin'],
            'plugin with colon' => ['seam-views::x', 'public.card', 'invalid_plugin'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousViews')]
    public function test_malicious_and_malformed_input_is_refused(string $plugin, string $view, string $reason): void
    {
        $this->makeSeamTheme('seam-mal');
        $this->bootSeamPlugins('seam-views');

        foreach ([fn () => cms_plugin_view($plugin, $view), fn () => cms_plugin_view_override_path($plugin, $view)] as $call) {
            try {
                $call();
                $this->fail('accepted');
            } catch (\TheNguyen\CMS\Support\PluginViewException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString("\0", $e->getMessage());
            }
        }
    }

    public function test_inactive_plugin_and_missing_view_are_deterministic_refusals(): void
    {
        $this->makeSeamTheme('seam-refuse', ['plugins/seam-views/public/card.blade.php' => 'NEVER']);

        try {
            cms_plugin_view('seam-views', 'public.card');
            $this->fail('inactive plugin rendered');
        } catch (\TheNguyen\CMS\Support\PluginViewException $e) {
            $this->assertSame('inactive_plugin', $e->reason);
        }

        $this->bootSeamPlugins('seam-views');

        try {
            cms_plugin_view('seam-views', 'public.nope');
            $this->fail('missing view resolved');
        } catch (\TheNguyen\CMS\Support\PluginViewException $e) {
            $this->assertSame('not_found', $e->reason);
        }
    }

    public function test_default_and_non_default_locale_with_composers_and_shared_data(): void
    {
        $this->makeSeamTheme('seam-locale', ['plugins/seam-views/public/card.blade.php' => '{{ app()->getLocale() }}|{{ $shared }}|{{ $composed }}']);
        $this->bootSeamPlugins('seam-views');
        View::share('shared', 'S');
        View::composer('seam-views::public.card', fn ($view) => $view->with('composed', 'C'));

        app()->setLocale('vi');
        $this->assertSame('vi|S|C', cms_plugin_view('seam-views', 'public.card')->render());
        app()->setLocale('en');
        $this->assertSame('en|S|C', cms_plugin_view('seam-views', 'public.card')->render());
    }

    public function test_missing_active_theme_falls_back_to_the_plugin(): void
    {
        app('cms.settings')->set('theme.active', 'seam-does-not-exist');
        settings()->clearCache();
        app('cms.theme')->flushRegistry();
        app('cms.theme')->registerViews();
        $this->bootSeamPlugins('seam-views');

        $this->assertSame('PLUGIN-CARD|seam-views', cms_plugin_view('seam-views', 'public.card')->render());
        $this->assertSame('plugin', app('cms.plugin_views')->source('seam-views', 'public.card'));
    }

    public function test_deactivation_then_reactivation_restores_overrides(): void
    {
        $this->makeSeamTheme('seam-react', ['plugins/seam-views/public/card.blade.php' => 'REACT-CARD']);
        $this->bootSeamPlugins('seam-views');
        $this->assertSame('REACT-CARD', cms_plugin_view('seam-views', 'public.card')->render());

        app('cms.extension')->deactivatePlugin('seam-views');
        $this->assertFalse(app('cms.plugin_views')->isActive('seam-views'));

        $this->bootSeamPlugins('seam-views');
        $this->assertSame('REACT-CARD', cms_plugin_view('seam-views', 'public.card')->render());
    }

    public function test_non_plugin_theme_views_are_unchanged(): void
    {
        $this->makeSeamTheme('seam-core', ['plugins/seam-views/public/card.blade.php' => 'X']);
        $this->bootSeamPlugins('seam-views');

        $this->assertSame('SEAM-CORE pages/page', view('theme::pages.page')->render());
        $this->assertCount(1, View::getFinder()->getHints()['theme'], 'the theme namespace keeps its single authority');
    }
}
