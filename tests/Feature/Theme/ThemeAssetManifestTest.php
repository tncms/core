<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Services\AssetRegistry;
use TheNguyen\CMS\Services\ThemeAssetManifestResolver;
use TheNguyen\CMS\Services\ThemeAssetPublisher;

/**
 * B1 — declarative theme.json asset manifest (EG-6, v1.0.0-beta.7.1.24).
 *
 * Standalone-theme coverage: schema + owner-aware URLs, type/position inference,
 * defer/media attributes, single-primary rule, path-traversal/scheme/absolute
 * security rejection, missing file, duplicate handle, missing dependency,
 * dependency cycle, impossible head→footer ordering, deterministic order,
 * registry rendering, and atomic staged publication.
 */
final class ThemeAssetManifestTest extends TestCase
{
    private string $slug = 'b1-assets-test';

    private string $themeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeDir = base_path('themes/'.$this->slug);
        File::ensureDirectoryExists($this->themeDir.'/assets/css');
        File::ensureDirectoryExists($this->themeDir.'/assets/js');
        File::ensureDirectoryExists($this->themeDir.'/views/layouts');
        File::ensureDirectoryExists($this->themeDir.'/views/pages');
        File::ensureDirectoryExists($this->themeDir.'/views/posts');
        File::ensureDirectoryExists($this->themeDir.'/views/archives');

        // Required views so the theme is otherwise valid/activatable.
        File::put($this->themeDir.'/views/layouts/master.blade.php', '<html>master</html>');
        File::put($this->themeDir.'/views/pages/page.blade.php', 'page');
        File::put($this->themeDir.'/views/posts/post.blade.php', 'post');
        File::put($this->themeDir.'/views/archives/index.blade.php', 'archive');

        // Real asset files the manifest can reference.
        File::put($this->themeDir.'/assets/css/tokens.css', ':root{--x:1}');
        File::put($this->themeDir.'/assets/css/app.css', 'body{}');
        File::put($this->themeDir.'/assets/css/theme.css', 'main{}');
        File::put($this->themeDir.'/assets/js/app.js', 'console.log(1)');
        File::put($this->themeDir.'/assets/js/boot.js', 'console.log(2)');
        File::put($this->themeDir.'/assets/js/module.mjs', 'export const a=1');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->themeDir);
        File::deleteDirectory(public_path('themes/'.$this->slug));

        foreach (File::glob(public_path('themes/.staging-'.$this->slug.'-*')) ?: [] as $d) {
            File::deleteDirectory($d);
        }
        foreach (File::glob(public_path('themes/.backup-'.$this->slug.'-*')) ?: [] as $d) {
            File::deleteDirectory($d);
        }

        parent::tearDown();
    }

    /** @param list<array<string,mixed>> $assets */
    private function writeManifest(array $assets): void
    {
        File::put($this->themeDir.'/theme.json', json_encode([
            'name' => 'B1 Assets Test',
            'slug' => $this->slug,
            'version' => '1.0.0',
            'author' => 'core-test',
            'assets' => $assets,
        ], JSON_PRETTY_PRINT));
    }

    private function resolver(): ThemeAssetManifestResolver
    {
        return app('cms.theme_assets');
    }

    public function test_valid_manifest_resolves_owner_aware_urls_and_order(): void
    {
        $this->writeManifest([
            ['handle' => 'tokens', 'src' => 'css/tokens.css'],
            ['handle' => 'app', 'src' => 'css/app.css', 'deps' => ['tokens'], 'primary' => true],
            ['handle' => 'app-js', 'src' => 'js/app.js', 'defer' => true],
        ]);

        $manifest = $this->resolver()->resolve($this->slug);

        $this->assertTrue($manifest->isValid(), implode(' | ', $manifest->errors));
        $this->assertSame('app', $manifest->primaryHandle());

        $handles = array_map(fn ($d) => $d->handle, $manifest->declarations);
        // tokens must precede app (dependency order).
        $this->assertLessThan(array_search('app', $handles, true), array_search('tokens', $handles, true));

        $tokens = $manifest->declarations[array_search('tokens', $handles, true)];
        $this->assertSame('/themes/'.$this->slug.'/css/tokens.css', $tokens->url);
        $this->assertSame('style', $tokens->type);
        $this->assertSame('head', $tokens->position);

        $js = $manifest->declarations[array_search('app-js', $handles, true)];
        $this->assertSame('script', $js->type);
        $this->assertSame('footer', $js->position);
        $this->assertTrue($js->attributes['defer'] ?? false);
    }

    public function test_type_and_position_inference(): void
    {
        $this->writeManifest([
            ['handle' => 'style', 'src' => 'css/app.css'],
            ['handle' => 'script', 'src' => 'js/app.js'],
            ['handle' => 'mod', 'src' => 'js/module.mjs'],
        ]);

        $manifest = $this->resolver()->resolve($this->slug);
        $this->assertTrue($manifest->isValid(), implode(' | ', $manifest->errors));

        $by = [];
        foreach ($manifest->declarations as $d) {
            $by[$d->handle] = $d;
        }

        $this->assertSame('style', $by['style']->type);
        $this->assertSame('head', $by['style']->position);
        $this->assertSame('script', $by['script']->type);
        $this->assertSame('footer', $by['script']->position);
        $this->assertSame('module', $by['mod']->type);
    }

    public function test_two_primary_stylesheets_is_invalid(): void
    {
        $this->writeManifest([
            ['handle' => 'a', 'src' => 'css/app.css', 'primary' => true],
            ['handle' => 'b', 'src' => 'css/theme.css', 'primary' => true],
        ]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    /**
     * @dataProvider unsafePaths
     */
    public function test_unsafe_src_is_rejected(string $src): void
    {
        $this->writeManifest([['handle' => 'x', 'src' => $src]]);

        $this->assertFalse(
            $this->resolver()->resolve($this->slug)->isValid(),
            'expected unsafe src to be rejected: '.$src,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['../secret.css'],
            'nested traversal' => ['css/../../secret.css'],
            'absolute unix' => ['/etc/passwd'],
            'windows drive' => ['c:/windows/win.ini'],
            'unc path' => ['\\\\server\\share\\x.css'],
            'http scheme' => ['http://evil.example/x.css'],
            'protocol relative' => ['//evil.example/x.css'],
            'data uri' => ['data:text/css,body{}'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    public function test_missing_source_file_is_invalid(): void
    {
        $this->writeManifest([['handle' => 'x', 'src' => 'css/does-not-exist.css']]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    public function test_duplicate_handle_is_invalid(): void
    {
        $this->writeManifest([
            ['handle' => 'dup', 'src' => 'css/app.css'],
            ['handle' => 'dup', 'src' => 'css/theme.css'],
        ]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    public function test_missing_dependency_is_invalid(): void
    {
        $this->writeManifest([
            ['handle' => 'app', 'src' => 'css/app.css', 'deps' => ['nope']],
        ]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    public function test_dependency_cycle_is_invalid(): void
    {
        $this->writeManifest([
            ['handle' => 'a', 'src' => 'css/app.css', 'deps' => ['b']],
            ['handle' => 'b', 'src' => 'css/theme.css', 'deps' => ['a']],
        ]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    public function test_head_depending_on_footer_is_invalid(): void
    {
        $this->writeManifest([
            ['handle' => 'foot', 'src' => 'js/app.js', 'position' => 'footer'],
            // A head style cannot depend on a footer script (impossible ordering).
            ['handle' => 'head', 'src' => 'css/app.css', 'position' => 'head', 'deps' => ['foot']],
        ]);

        $this->assertFalse($this->resolver()->resolve($this->slug)->isValid());
    }

    public function test_apply_to_registry_renders_owner_aware_tags_in_order(): void
    {
        $this->writeManifest([
            ['handle' => 'app', 'src' => 'css/app.css', 'deps' => ['tokens']],
            ['handle' => 'tokens', 'src' => 'css/tokens.css'],
            ['handle' => 'app-js', 'src' => 'js/app.js', 'defer' => true],
        ]);

        /** @var AssetRegistry $registry */
        $registry = app('cms.assets');
        $registry->flush();

        $this->resolver()->resolve($this->slug)->applyTo($registry);

        $head = $registry->renderFrontendStyles();
        $footer = $registry->renderFrontendScripts();

        $this->assertStringContainsString('/themes/'.$this->slug.'/css/tokens.css', $head);
        $this->assertStringContainsString('/themes/'.$this->slug.'/css/app.css', $head);
        // tokens before app in the rendered head.
        $this->assertLessThan(
            strpos($head, '/themes/'.$this->slug.'/css/app.css'),
            strpos($head, '/themes/'.$this->slug.'/css/tokens.css'),
        );

        $this->assertStringContainsString('/themes/'.$this->slug.'/js/app.js', $footer);
        $this->assertStringContainsString('defer', $footer);
    }

    public function test_atomic_publish_copies_allowlisted_only_and_leaves_no_temp(): void
    {
        // A stray .php must never reach the public web root.
        File::put($this->themeDir.'/assets/danger.php', '<?php echo "x";');
        $this->writeManifest([['handle' => 'app', 'src' => 'css/app.css']]);

        /** @var ThemeAssetPublisher $publisher */
        $publisher = app('cms.theme_publisher');
        $result = $publisher->publishAtomic($this->slug);

        $this->assertSame([], $result->errors);
        $this->assertFileExists(public_path('themes/'.$this->slug.'/css/app.css'));
        $this->assertFileDoesNotExist(public_path('themes/'.$this->slug.'/danger.php'));

        // No staging/backup residue.
        $this->assertSame([], File::glob(public_path('themes/.staging-'.$this->slug.'-*')) ?: []);
        $this->assertSame([], File::glob(public_path('themes/.backup-'.$this->slug.'-*')) ?: []);
    }

    public function test_atomic_publish_replaces_previous_tree(): void
    {
        $this->writeManifest([['handle' => 'app', 'src' => 'css/app.css']]);

        /** @var ThemeAssetPublisher $publisher */
        $publisher = app('cms.theme_publisher');

        // First publish an old file, then republish without it — the swap must
        // leave the live tree consistent with the current source.
        File::put($this->themeDir.'/assets/css/old.css', 'old{}');
        $publisher->publishAtomic($this->slug);
        $this->assertFileExists(public_path('themes/'.$this->slug.'/css/old.css'));

        File::delete($this->themeDir.'/assets/css/old.css');
        $result = $publisher->publishAtomic($this->slug);

        $this->assertSame([], $result->errors);
        $this->assertFileExists(public_path('themes/'.$this->slug.'/css/app.css'));
        $this->assertFileDoesNotExist(public_path('themes/'.$this->slug.'/css/old.css'));
    }
}
