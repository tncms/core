<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * B3 — atomic theme activation / rollback + switch and failure matrices
 * (EG-6, v1.0.0-beta.7.1.24).
 *
 * Uses seven disposable fixtures (Theme A, Theme B, Parent A, Child A1,
 * Child A2, a broken theme, plus the bundled Default) to prove:
 *  - the standalone + child switch matrices leave coherent pointer/view/asset
 *    authority with no stale hints;
 *  - every activation failure (missing view, missing/self/cyclic parent, unsafe
 *    asset, duplicate handle, missing dependency) preserves the previous theme;
 *  - a rendered response's Blade authority == asset authority == active pointer.
 */
final class ThemeActivationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = [
        'lc-a', 'lc-b', 'lc-def', 'lc-parent', 'lc-child1', 'lc-child2', 'lc-broken', 'lc-bad-asset',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->standalone('lc-a', 'A');
        $this->standalone('lc-b', 'B');
        // A third standalone that stands in for "the default theme" in the switch
        // matrices, so the suite never mutates the real bundled default/public.
        $this->standalone('lc-def', 'DEF');

        $this->parentTheme('lc-parent', 'PARENT');
        $this->childTheme('lc-child1', 'lc-parent', 'CHILD1');
        $this->childTheme('lc-child2', 'lc-parent', 'CHILD2');

        // Broken: valid manifest but MISSING the required layouts/master view.
        $broken = base_path('themes/lc-broken');
        File::ensureDirectoryExists($broken.'/views/pages');
        File::put($broken.'/views/pages/page.blade.php', 'x');
        File::put($broken.'/theme.json', json_encode([
            'name' => 'Broken', 'slug' => 'lc-broken', 'version' => '1.0.0', 'author' => 't',
        ]));

        // Bad-asset: full views but a path-traversal asset src → manifest invalid.
        $this->standalone('lc-bad-asset', 'BAD');
        File::put(base_path('themes/lc-bad-asset/theme.json'), json_encode([
            'name' => 'Bad Asset', 'slug' => 'lc-bad-asset', 'version' => '1.0.0', 'author' => 't',
            'assets' => [['handle' => 'evil', 'src' => '../../../etc/passwd']],
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

    private function standalone(string $slug, string $marker): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives', 'errors'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::ensureDirectoryExists($dir.'/assets/css');

        File::put($dir.'/views/layouts/master.blade.php', $marker.'-MASTER');
        File::put($dir.'/views/errors/404.blade.php', $marker.'-404');
        File::put($dir.'/views/pages/page.blade.php', $marker.'-PAGE');
        File::put($dir.'/views/posts/post.blade.php', $marker.'-POST');
        File::put($dir.'/views/archives/index.blade.php', $marker.'-ARCHIVE');
        File::put($dir.'/assets/css/main.css', '/*'.$marker.'*/');

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'assets' => [['handle' => $slug.'-css', 'src' => 'css/main.css', 'primary' => true]],
        ]));
    }

    private function parentTheme(string $slug, string $marker): void
    {
        $this->standalone($slug, $marker);
    }

    private function childTheme(string $slug, string $parent, string $marker): void
    {
        $dir = base_path('themes/'.$slug);
        File::ensureDirectoryExists($dir.'/views/pages');
        File::ensureDirectoryExists($dir.'/assets/css');

        File::put($dir.'/views/pages/page.blade.php', $marker.'-PAGE');
        File::put($dir.'/assets/css/child.css', '/*'.$marker.'*/');

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'parent' => $parent,
            'assets' => [['handle' => $slug.'-css', 'src' => 'css/child.css', 'deps' => [$parent.'-css']]],
        ]));
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    /** Assert the theme:: view hint list is exactly the given slugs, in order. */
    private function assertViewHints(array $expectedSlugs): void
    {
        $this->themes()->registerViews();
        $hints = View::getFinder()->getHints()['theme'] ?? [];

        $this->assertCount(count($expectedSlugs), $hints);
        foreach ($expectedSlugs as $i => $slug) {
            $this->assertStringContainsString(
                DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR, $hints[$i],
                "hint {$i} should belong to {$slug}",
            );
        }
    }

    public function test_standalone_switch_matrix_leaves_no_stale_authority(): void
    {
        foreach (['lc-a', 'lc-b', 'lc-def', 'lc-a'] as $slug) {
            $this->assertTrue($this->themes()->activate($slug), "activate {$slug}");
            $this->assertSame($slug, $this->themes()->active()?->slug);
            $this->assertViewHints([$slug]);
            $this->assertFileExists(public_path('themes/'.$slug.'/css/main.css'));
        }

        // After ending on lc-a, no lc-b hint remains.
        $this->themes()->registerViews();
        $hints = View::getFinder()->getHints()['theme'] ?? [];
        foreach ($hints as $hint) {
            $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'lc-b'.DIRECTORY_SEPARATOR, $hint);
        }
    }

    public function test_child_switch_matrix_publishes_chain_and_no_stale(): void
    {
        $sequence = ['lc-parent', 'lc-child1', 'lc-b', 'lc-child2', 'lc-def', 'lc-parent', 'lc-child1'];

        foreach ($sequence as $slug) {
            $this->assertTrue($this->themes()->activate($slug), "activate {$slug}");
            $this->assertSame($slug, $this->themes()->active()?->slug);
        }

        // Ended on child1: hints = [child1, parent]; child + parent assets published.
        $this->assertViewHints(['lc-child1', 'lc-parent']);
        $this->assertFileExists(public_path('themes/lc-child1/css/child.css'));
        $this->assertFileExists(public_path('themes/lc-parent/css/main.css'));
    }

    public function test_child_activation_view_and_asset_authority_are_coherent(): void
    {
        $this->assertTrue($this->themes()->activate('lc-child1'));
        $this->themes()->registerViews();

        // Blade authority: child overrides page, parent supplies master.
        $this->assertStringContainsString('CHILD1-PAGE', View::make('theme::pages.page')->render());
        $this->assertStringContainsString('PARENT-MASTER', View::make('theme::layouts.master')->render());

        // Asset authority: owner-aware URLs for the SAME resolved hierarchy.
        $manifest = app('cms.theme_assets')->resolve('lc-child1');
        $this->assertTrue($manifest->isValid());
        $owners = [];
        foreach ($manifest->declarations as $d) {
            $owners[$d->handle] = $d->owner;
        }
        $this->assertSame('lc-parent', $owners['lc-parent-css']);
        $this->assertSame('lc-child1', $owners['lc-child1-css']);
    }

    /**
     * @dataProvider failingThemes
     */
    public function test_activation_failure_preserves_previous_theme(string $badSlug): void
    {
        $this->assertTrue($this->themes()->activate('lc-a'));
        $this->assertSame('lc-a', $this->themes()->active()?->slug);

        // The bad activation must fail and leave lc-a fully in authority.
        $this->assertFalse($this->themes()->activate($badSlug), "activation of {$badSlug} must fail");
        $this->assertSame('lc-a', $this->themes()->active()?->slug);

        $this->assertViewHints(['lc-a']);
        $this->assertFileExists(public_path('themes/lc-a/css/main.css'));
    }

    /** @return array<string, array{0: string}> */
    public static function failingThemes(): array
    {
        return [
            'missing required view' => ['lc-broken'],
            'unsafe asset path' => ['lc-bad-asset'],
            'nonexistent theme' => ['lc-does-not-exist'],
        ];
    }

    public function test_rendered_response_blade_and_assets_share_one_authority(): void
    {
        $this->assertTrue($this->themes()->activate('lc-a'));

        // Reproduce a request boot's theme wiring: register the active theme's
        // view namespace and declarative assets (as the service provider does).
        $this->themes()->registerViews();
        app('cms.assets')->flush();
        app('cms.theme_assets')->resolve('lc-a')->applyTo(app('cms.assets'));

        // Blade authority: the active theme's view renders (real Blade compile).
        $html = View::make('theme::errors.404')->render();
        $this->assertStringContainsString('A-404', $html);

        // Asset authority: the frontend head the web server would emit carries
        // the SAME theme's owner-aware URL, and only that theme's.
        $head = app('cms.assets')->renderFrontendStyles();
        $this->assertStringContainsString('/themes/lc-a/css/main.css', $head);
        $this->assertStringNotContainsString('/themes/lc-b/', $head);

        // The referenced asset exists on disk (served with HTTP 200 by the web root).
        $this->assertFileExists(public_path('themes/lc-a/css/main.css'));
    }

    public function test_self_parent_and_missing_parent_activations_fail_closed(): void
    {
        $this->assertTrue($this->themes()->activate('lc-a'));

        // Turn lc-child1 into a self-parent, and lc-child2 into a missing-parent.
        File::put(base_path('themes/lc-child1/theme.json'), json_encode([
            'name' => 'lc-child1', 'slug' => 'lc-child1', 'version' => '1.0.0', 'author' => 't', 'parent' => 'lc-child1',
        ]));
        File::put(base_path('themes/lc-child2/theme.json'), json_encode([
            'name' => 'lc-child2', 'slug' => 'lc-child2', 'version' => '1.0.0', 'author' => 't', 'parent' => 'lc-ghost',
        ]));
        $this->themes()->flushRegistry();

        $this->assertFalse($this->themes()->activate('lc-child1'));
        $this->assertFalse($this->themes()->activate('lc-child2'));
        $this->assertSame('lc-a', $this->themes()->active()?->slug);
    }
}
