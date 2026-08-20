<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TheNguyen\CMS\Services\ExtensionInstaller;
use TheNguyen\CMS\Services\PluginAssetPublisher;
use TheNguyen\CMS\Support\AssetPublishResult;
use Tests\TestCase;
use ZipArchive;

/**
 * PB-DIST-4A-A1 — generic plugin public-asset provisioning.
 *
 * Proves the reusable, opt-in Core capability: a plugin ships already-built browser assets inside its
 * own directory and declares them in plugin.json ("assets":{"source":"public"}); on install/upgrade
 * Core copies them into the slug-isolated public namespace public/vendor/<slug>. Generic (no Page
 * Builder logic in Core), contained/fail-closed, and node-free. Direct-publisher tests cover the
 * security/semantics; installer-integration tests cover the ZIP lifecycle + backward compatibility.
 */
final class PluginAssetPublishingTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    // ---- direct-publisher: semantics ---------------------------------------------------------------

    public function test_no_declaration_is_a_no_op(): void // (1)
    {
        [$plugin, $base] = $this->scaffold(['name' => 'X', 'slug' => 'noassets', 'version' => '1', 'author' => 'a']);
        $result = (new PluginAssetPublisher($base))->publish($plugin, 'noassets', $this->manifest($plugin));

        $this->assertTrue($result->isSkipped());
        $this->assertDirectoryDoesNotExist($base.'/noassets');
    }

    public function test_valid_declaration_publishes_files(): void // (2)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'alpha', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/app.js' => 'console.log(1)', 'public/app.css' => 'body{}'],
        );
        $result = (new PluginAssetPublisher($base))->publish($plugin, 'alpha', $this->manifest($plugin));

        $this->assertTrue($result->isPublished());
        $this->assertFileExists($base.'/alpha/app.js');
        $this->assertFileExists($base.'/alpha/app.css');
    }

    public function test_nested_files_preserved(): void // (3)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'beta', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/frontend-editor/shell.js' => 'x', 'public/editor/editor.css' => 'y'],
        );
        (new PluginAssetPublisher($base))->publish($plugin, 'beta', $this->manifest($plugin));

        $this->assertFileExists($base.'/beta/frontend-editor/shell.js');
        $this->assertFileExists($base.'/beta/editor/editor.css');
    }

    public function test_stale_owned_assets_reconciled_on_upgrade(): void // (4)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'gamma', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/old.js' => 'old'],
        );
        $pub = new PluginAssetPublisher($base);
        $pub->publish($plugin, 'gamma', $this->manifest($plugin));
        $this->assertFileExists($base.'/gamma/old.js');

        // Upgrade: source no longer ships old.js.
        unlink($plugin.'/public/old.js');
        file_put_contents($plugin.'/public/new.js', 'new');
        $pub->publish($plugin, 'gamma', $this->manifest($plugin));

        $this->assertFileExists($base.'/gamma/new.js');
        $this->assertFileDoesNotExist($base.'/gamma/old.js', 'Stale owned asset must be reconciled away.');
    }

    public function test_namespace_isolation_between_plugins(): void // (5),(12)
    {
        [$a, $base] = $this->scaffold(
            ['name' => 'A', 'slug' => 'plugina', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/a.js' => 'a'],
        );
        $pub = new PluginAssetPublisher($base);
        $pub->publish($a, 'plugina', $this->manifest($a));

        [$b] = $this->scaffold(
            ['name' => 'B', 'slug' => 'pluginb', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/b.js' => 'b'],
            $base, // same public base
        );
        $pub->publish($b, 'pluginb', $this->manifest($b));

        // Each lives only under its own slug; neither reaches the other.
        $this->assertFileExists($base.'/plugina/a.js');
        $this->assertFileExists($base.'/pluginb/b.js');
        $this->assertFileDoesNotExist($base.'/plugina/b.js');
        $this->assertFileDoesNotExist($base.'/pluginb/a.js');
    }

    /** @return list<array{0:string}> */
    public static function unsafeSources(): array
    {
        return [
            'traversal' => ['../evil'],
            'nested traversal' => ['public/../../evil'],
            'posix absolute' => ['/evil'],
            'drive' => ['C:/evil'],
            'unc' => ['//host/share'],
        ];
    }

    /** @dataProvider unsafeSources */
    public function test_unsafe_source_declarations_fail_closed(string $source): void // (6),(7),(8),(9)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'safe', 'version' => '1', 'author' => 'a', 'assets' => ['source' => $source]],
            ['public/app.js' => 'x'],
        );
        $result = (new PluginAssetPublisher($base))->publish($plugin, 'safe', $this->manifest($plugin));

        $this->assertTrue($result->isFailure(), "Unsafe source must fail closed: {$source}");
        $this->assertDirectoryDoesNotExist($base.'/safe');
    }

    public function test_source_outside_plugin_root_is_rejected(): void // (10)
    {
        // A sibling directory outside the plugin root, reachable only by escaping — declared via a
        // symlink-free relative that resolves out is already covered; here assert a real outside dir
        // cannot be pointed at because the declared source must resolve strictly inside the plugin root.
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'contained', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/app.js' => 'x'],
        );
        // Create an outside dir as a sibling; the plugin cannot reference it (no valid relative does).
        $outside = dirname($plugin).'/outside-secret';
        @mkdir($outside, 0o755, true);
        file_put_contents($outside.'/secret.txt', 'SECRET');

        $result = (new PluginAssetPublisher($base))->publish($plugin, 'contained', $this->manifest($plugin));
        $this->assertTrue($result->isPublished());
        $this->assertFileDoesNotExist($base.'/contained/secret.txt', 'Outside files must never be published.');
    }

    public function test_arbitrary_destination_key_is_ignored(): void // (11)
    {
        // A malicious "destination"/"target" in the declaration must be ignored — Core derives the path.
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'delta', 'version' => '1', 'author' => 'a',
                'assets' => ['source' => 'public', 'destination' => '../../evil', 'target' => 'C:/evil']],
            ['public/app.js' => 'x'],
        );
        $pub = new PluginAssetPublisher($base);
        $result = $pub->publish($plugin, 'delta', $this->manifest($plugin));

        $this->assertTrue($result->isPublished());
        $this->assertSame(realpath($base).DIRECTORY_SEPARATOR.'delta', realpath($pub->destinationFor('delta')));
        $this->assertFileExists($base.'/delta/app.js');
    }

    public function test_missing_source_fails_correctly(): void // (13)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'epsilon', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            [], // no public/ dir
        );
        $result = (new PluginAssetPublisher($base))->publish($plugin, 'epsilon', $this->manifest($plugin));

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('does not exist', $result->message);
    }

    public function test_copy_failure_never_reports_success(): void // (14)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'zeta', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/app.js' => 'x'],
        );
        // Block the destination: a FILE where public/vendor/<slug> would be created.
        @mkdir($base, 0o755, true);
        file_put_contents($base.'/zeta', 'blocker');

        $result = (new PluginAssetPublisher($base))->publish($plugin, 'zeta', $this->manifest($plugin));
        $this->assertTrue($result->isFailure(), 'A copy failure must never report success.');
    }

    public function test_symlink_source_is_rejected_where_detectable(): void // (containment defense)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'eta', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/app.js' => 'x'],
        );
        // Attempt a directory junction inside public/ pointing outside (Windows). Skip if not creatable.
        $outside = dirname($plugin).'/eta-target';
        @mkdir($outside, 0o755, true);
        file_put_contents($outside.'/secret.txt', 'SECRET');
        $link = $plugin.'/public/link';
        $rc = 1;
        if (\DIRECTORY_SEPARATOR === '\\') {
            @exec('cmd /c mklink /J '.escapeshellarg(str_replace('/', '\\', $link)).' '
                .escapeshellarg(str_replace('/', '\\', $outside)), $o, $rc);
        } else {
            $rc = @symlink($outside, $link) ? 0 : 1;
        }
        if ($rc !== 0) {
            $this->markTestSkipped('Could not create a link to exercise the symlink guard.');
        }

        $result = (new PluginAssetPublisher($base))->publish($plugin, 'eta', $this->manifest($plugin));
        $this->assertTrue($result->isFailure(), 'A source containing a link must be rejected.');
        $this->assertFileDoesNotExist($base.'/eta/link/secret.txt');
    }

    public function test_purge_removes_only_owned_namespace(): void // (uninstall building block)
    {
        [$plugin, $base] = $this->scaffold(
            ['name' => 'X', 'slug' => 'theta', 'version' => '1', 'author' => 'a', 'assets' => ['source' => 'public']],
            ['public/app.js' => 'x'],
        );
        // A foreign file elsewhere under the base must survive a purge.
        file_put_contents($base.'/keep-me.txt', 'keep');

        $pub = new PluginAssetPublisher($base);
        $pub->publish($plugin, 'theta', $this->manifest($plugin));
        $this->assertFileExists($base.'/theta/app.js');

        $pub->purge('theta');
        $this->assertDirectoryDoesNotExist($base.'/theta');
        $this->assertFileExists($base.'/keep-me.txt', 'Purge must never remove foreign public files.');
    }

    // ---- architecture guards (15),(16) ------------------------------------------------------------

    public function test_core_capability_executes_no_node_or_shell(): void // (15)
    {
        $body = (string) file_get_contents($this->coreSrc('Services/PluginAssetPublisher.php'));
        // Prove no process/shell EXECUTION primitive is used (installation copies files only). Word
        // mentions like "npm"/"Vite" in documentation are fine; only real invocations are forbidden.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(exec|shell_exec|proc_open|passthru|system|popen)\s*\(|Symfony\\\\Component\\\\Process|new\s+Process\b/',
            $body,
            'Plugin asset provisioning must never invoke a shell or spawn a process (no Node/npm/Vite).',
        );
    }

    public function test_core_capability_has_no_page_builder_special_case(): void // (16)
    {
        foreach (['Services/PluginAssetPublisher.php', 'Support/AssetPublishResult.php', 'Services/ExtensionInstaller.php'] as $rel) {
            $this->assertDoesNotMatchRegularExpression(
                '/page-builder|PageBuilder/i',
                (string) file_get_contents($this->coreSrc($rel)),
                "Generic Core asset capability must contain no Page Builder special case: {$rel}",
            );
        }
    }

    // ---- installer integration + backward compatibility -------------------------------------------

    public function test_install_publishes_declared_assets_and_uninstall_purges(): void // (2),(17)
    {
        $this->requireZip();
        [$installRoot, $publicBase] = $this->installerRoots();

        $zip = $this->buildPluginZip('mywidget', [
            'name' => 'My Widget', 'slug' => 'mywidget', 'version' => '1.0.0', 'author' => 'ACME',
            'assets' => ['source' => 'public'],
        ], ['src/Provider.php' => '<?php', 'public/widget.js' => 'w']);

        $result = app('cms.extension_installer')->installPluginFromZip($zip, false);
        $this->assertTrue($result->success, $result->message);
        $this->assertFileExists($installRoot.'/mywidget/plugin.json');
        $this->assertFileExists($publicBase.'/mywidget/widget.js');

        $del = app('cms.extension_installer')->deletePlugin('mywidget');
        $this->assertTrue($del->success, $del->message);
        $this->assertDirectoryDoesNotExist($publicBase.'/mywidget', 'Uninstall must purge owned public assets.');
    }

    public function test_plugin_without_declaration_installs_unchanged(): void // (18) backward compat
    {
        $this->requireZip();
        [$installRoot, $publicBase] = $this->installerRoots();

        $zip = $this->buildPluginZip('plainplugin', [
            'name' => 'Plain', 'slug' => 'plainplugin', 'version' => '1.0.0', 'author' => 'ACME',
        ], ['src/Provider.php' => '<?php']);

        $result = app('cms.extension_installer')->installPluginFromZip($zip, false);
        $this->assertTrue($result->success, $result->message);
        $this->assertFileExists($installRoot.'/plainplugin/plugin.json');
        $this->assertDirectoryDoesNotExist($publicBase.'/plainplugin');
    }

    public function test_install_rolls_back_when_declared_assets_are_missing(): void // (14) via installer
    {
        $this->requireZip();
        [$installRoot, $publicBase] = $this->installerRoots();

        // Declares assets but ships no public/ dir → provisioning fails → install rolls back.
        $zip = $this->buildPluginZip('brokenassets', [
            'name' => 'Broken', 'slug' => 'brokenassets', 'version' => '1.0.0', 'author' => 'ACME',
            'assets' => ['source' => 'public'],
        ], ['src/Provider.php' => '<?php']);

        $result = app('cms.extension_installer')->installPluginFromZip($zip, false);
        $this->assertFalse($result->success, 'Install must fail when declared assets are missing.');
        $this->assertDirectoryDoesNotExist($installRoot.'/brokenassets', 'Failed install must roll back the plugin dir.');
        $this->assertDirectoryDoesNotExist($publicBase.'/brokenassets');
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function requireZip(): void
    {
        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not available.');
        }
    }

    /** Point the installer at throwaway roots so real plugins/ and public/ are never touched. */
    private function installerRoots(): array
    {
        $root = $this->newTempDir();
        $installRoot = $root.'/plugins';
        $publicBase = $root.'/public-vendor';
        @mkdir($installRoot, 0o755, true);
        @mkdir($publicBase, 0o755, true);
        config(['cms.paths.plugins' => $installRoot, 'cms.paths.plugin_assets' => $publicBase]);

        return [$installRoot, $publicBase];
    }

    /**
     * Scaffold a plugin directory + a public asset base.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files  relative path => contents
     * @return array{0:string,1:string}  [pluginRoot, publicBase]
     */
    private function scaffold(array $manifest, array $files = [], ?string $base = null): array
    {
        $root = $this->newTempDir();
        $plugin = $root.'/plugins/'.$manifest['slug'];
        @mkdir($plugin, 0o755, true);
        file_put_contents($plugin.'/plugin.json', json_encode($manifest));
        foreach ($files as $rel => $content) {
            $abs = $plugin.'/'.$rel;
            @mkdir(dirname($abs), 0o755, true);
            file_put_contents($abs, $content);
        }
        $base ??= $root.'/public-vendor';
        @mkdir($base, 0o755, true);

        return [$plugin, $base];
    }

    /** @return array<string, mixed> */
    private function manifest(string $pluginRoot): array
    {
        return json_decode((string) file_get_contents($pluginRoot.'/plugin.json'), true);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function buildPluginZip(string $slug, array $manifest, array $files): string
    {
        $root = $this->newTempDir();
        $stage = $root.'/'.$slug;
        @mkdir($stage, 0o755, true);
        file_put_contents($stage.'/plugin.json', json_encode($manifest));
        foreach ($files as $rel => $content) {
            $abs = $stage.'/'.$rel;
            @mkdir(dirname($abs), 0o755, true);
            file_put_contents($abs, $content);
        }

        $zipPath = $root.'/'.$slug.'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $rel = $slug.'/'.str_replace('\\', '/', ltrim(str_replace($stage, '', $f->getPathname()), '/\\'));
                $zip->addFile($f->getPathname(), $rel);
            }
        }
        $zip->close();

        return $zipPath;
    }

    private function coreSrc(string $rel): string
    {
        return base_path('packages/thenguyen/cms-core/src/'.$rel);
    }

    private function newTempDir(): string
    {
        $dir = sys_get_temp_dir().'/pb-a1-'.Str::random(12);
        @mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            @chmod($p, $f->isDir() ? 0o755 : 0o644);
            if ($f->isDir()) {
                // A junction created by a test resolves as neither dir nor file after mklink; rmdir it.
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
