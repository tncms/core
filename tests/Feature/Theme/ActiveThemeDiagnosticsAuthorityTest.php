<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Filament\Admin\Widgets\CmsInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;

/**
 * CORE-THEME-3 — active-theme authority unification.
 *
 * Regression guard for the split-brain defect where the Dashboard environment
 * summary reported the active theme from stale config('cms.theme.active')
 * (== env('CMS_ACTIVE_THEME','default')) while the frontend, Themes page and
 * every other consumer resolved the committed authority (cms_settings
 * "theme.active" via ThemeManager). The observed symptom was:
 *
 *   frontend  = ngohoangnguyen
 *   Dashboard = default
 *
 * All diagnostics must agree with the single committed active-theme authority.
 */
final class ActiveThemeDiagnosticsAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = ['ct3-diag', 'ct3-a', 'ct3-b', 'ct3-broken'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->standalone('ct3-diag', 'CT3 Diag Theme');
        $this->standalone('ct3-a', 'CT3 Theme A');
        $this->standalone('ct3-b', 'CT3 Theme B');

        // Valid manifest but MISSING the required layouts/master view → a theme
        // whose activation must fail closed and preserve the previous theme.
        $broken = base_path('themes/ct3-broken');
        File::ensureDirectoryExists($broken.'/views/pages');
        File::put($broken.'/views/pages/page.blade.php', 'x');
        File::put($broken.'/theme.json', json_encode([
            'name' => 'CT3 Broken', 'slug' => 'ct3-broken', 'version' => '1.0.0', 'author' => 't',
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    /**
     * A minimal, valid standalone theme with all required views + a stylesheet.
     */
    private function standalone(string $slug, string $name): void
    {
        $dir = base_path('themes/'.$slug);

        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::ensureDirectoryExists($dir.'/assets/css');

        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/assets/css/main.css', '/*ct3*/');

        File::put($dir.'/theme.json', json_encode([
            'name' => $name,
            'slug' => $slug,
            'version' => '1.0.0',
            'author' => 't',
            'assets' => [['handle' => $slug.'-css', 'src' => 'css/main.css', 'primary' => true]],
        ]));
    }

    public function test_cmsinfo_active_theme_follows_committed_authority_not_stale_config(): void
    {
        // Simulate the real deployment: config/env active theme is the install
        // default ("default"), which is NEVER updated when the admin switches
        // themes. This is the stale authority the Dashboard used to trust.
        config(['cms.theme.active' => 'default']);

        // Commit a non-default theme through the canonical activation path.
        $this->assertTrue(app('cms.theme')->activate('ct3-diag'));

        // Canonical committed authority.
        $this->assertSame('ct3-diag', app('cms.theme')->active()?->slug);

        // The diagnostics helper must report the committed theme — not "default".
        $this->assertSame(
            'ct3-diag',
            CmsInfo::activeTheme(),
            'CmsInfo::activeTheme() must follow the committed active-theme authority, not stale config.'
        );
    }

    public function test_dashboard_widget_reports_committed_active_theme_name_and_slug(): void
    {
        config(['cms.theme.active' => 'default']);

        $this->assertTrue(app('cms.theme')->activate('ct3-diag'));

        $widget = new CmsInfoWidget;
        $method = new \ReflectionMethod($widget, 'getViewData');
        $method->setAccessible(true);
        /** @var array<string, mixed> $data */
        $data = $method->invoke($widget);

        // The Dashboard must display the committed theme, identifiable by slug,
        // never the stale "default".
        $this->assertStringContainsString('ct3-diag', (string) $data['activeTheme']);
        $this->assertStringContainsString('CT3 Diag Theme', (string) $data['activeTheme']);
        $this->assertStringNotContainsString('default', (string) $data['activeTheme']);
    }

    public function test_diagnostics_fall_back_to_default_when_no_theme_committed(): void
    {
        // No committed selection and no non-default fixture active: the effective
        // authority resolves to the bundled default theme (legitimate fallback).
        config(['cms.theme.active' => 'default']);

        $this->assertSame('default', CmsInfo::activeTheme());
    }

    /**
     * Full lifecycle matrix (§24): at every committed state the diagnostics
     * authority (CmsInfo) must equal the frontend authority (ThemeManager),
     * regardless of the stale config value.
     */
    public function test_lifecycle_matrix_diagnostics_track_committed_authority(): void
    {
        $themes = app('cms.theme');

        // Stale config is frozen at the install default throughout (mirrors a
        // config:cache'd value that never changes on activation).
        config(['cms.theme.active' => 'default']);

        // fresh → default everywhere
        $this->assertSame($themes->active()?->slug, CmsInfo::activeTheme());

        // activate A → A everywhere
        $this->assertTrue($themes->activate('ct3-a'));
        $this->assertSame('ct3-a', $themes->active()?->slug);
        $this->assertSame('ct3-a', CmsInfo::activeTheme());

        // activate B → B everywhere
        $this->assertTrue($themes->activate('ct3-b'));
        $this->assertSame('ct3-b', $themes->active()?->slug);
        $this->assertSame('ct3-b', CmsInfo::activeTheme());

        // failed activation of an invalid theme → previous (B) preserved everywhere
        $this->assertFalse($themes->activate('ct3-broken'));
        $this->assertFalse($themes->activate('does-not-exist'));
        $this->assertSame('ct3-b', $themes->active()?->slug);
        $this->assertSame('ct3-b', CmsInfo::activeTheme());

        // back to A → exact committed theme reported again
        $this->assertTrue($themes->activate('ct3-a'));
        $this->assertSame('ct3-a', CmsInfo::activeTheme());
    }
}
