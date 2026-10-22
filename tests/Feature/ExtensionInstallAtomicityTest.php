<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use TheNguyen\CMS\Services\ExtensionInstallFilesystem;
use TheNguyen\CMS\Services\ExtensionInstallTransaction;
use TheNguyen\CMS\Support\InstallResult;
use ZipArchive;

/**
 * CORE-PLUGIN-UPGRADE-ATOMIC-ROLLBACK-R1 — a failed install/overwrite can never destroy the previous
 * extension directory or its owned public asset namespace.
 *
 * Every phase of the staged transaction is failure-injected through the container-bound
 * `cms.extension_install_fs` collaborator; byte manifests of the plugin tree and of
 * `<assets>/<slug>` are compared before and after. Roots are isolated temp directories — the real
 * plugins/ and public/ are never touched.
 */
final class ExtensionInstallAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'atomplug';

    /** @var list<string> */
    private array $tempDirs = [];

    private string $plugins = '';

    private string $assets = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('The PHP zip extension is not available.');
        }

        $root = $this->newTempDir();
        $this->plugins = $root.'/plugins';
        $this->assets = $root.'/public-vendor';
        @mkdir($this->plugins, 0o755, true);
        @mkdir($this->assets, 0o755, true);
        config(['cms.paths.plugins' => $this->plugins, 'cms.paths.plugin_assets' => $this->assets]);
        $this->useFilesystem(new ExtensionInstallFilesystem);
    }

    protected function tearDown(): void
    {
        foreach ([self::SLUG, 'neighbor', 'otherplug'] as $slug) {
            @unlink(ExtensionInstallTransaction::lockPathFor($this->plugins, $slug));
        }
        @unlink(ExtensionInstallTransaction::lockPathFor(dirname($this->plugins).'/themes', 'calm'));

        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];

        parent::tearDown();
    }

    // ---- fresh install ---------------------------------------------------------------------------

    public function test_fresh_install_succeeds_and_leaves_no_transaction_residue(): void
    {
        $result = $this->install($this->v1());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('one', file_get_contents($this->plugins.'/'.self::SLUG.'/src/one.php'));
        $this->assertSame('app-v1', file_get_contents($this->assets.'/'.self::SLUG.'/app.js'));
        $this->assertNoResidue();
    }

    public function test_fresh_install_rejects_invalid_asset_source_before_any_mutation(): void
    {
        $result = $this->install($this->zip(['assets' => ['source' => 'missing']], ['src/one.php' => 'x']));

        $this->assertFalse($result->success);
        $this->assertStringContainsString('missing', $result->message);
        $this->assertDirectoryDoesNotExist($this->plugins.'/'.self::SLUG);
        $this->assertDirectoryDoesNotExist($this->assets.'/'.self::SLUG);
        $this->assertNoResidue();
    }

    public function test_fresh_install_asset_failure_after_promotion_leaves_nothing(): void
    {
        $this->failOn('copy', '#/public$#');

        $result = $this->install($this->v1());

        $this->assertFalse($result->success);
        $this->assertDirectoryDoesNotExist($this->plugins.'/'.self::SLUG);
        $this->assertDirectoryDoesNotExist($this->assets.'/'.self::SLUG);
        $this->assertNoResidue();
    }

    public function test_fresh_install_rejects_invalid_manifest_before_mutation(): void
    {
        $result = $this->install($this->zip(['slug' => 'Bad Slug'], ['src/one.php' => 'x']));

        $this->assertFalse($result->success);
        $this->assertSame([], $this->listDir($this->plugins));
        $this->assertNoResidue();
    }

    // ---- overwrite: success ------------------------------------------------------------------------

    public function test_overwrite_success_replaces_unit_and_reconciles_stale_owned_assets(): void
    {
        $this->assertTrue($this->install($this->v1())->success);

        $result = $this->install($this->v2(), true);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['app.js', 'new.css'], $this->listDir($this->assets.'/'.self::SLUG));
        $this->assertSame('app-v2', file_get_contents($this->assets.'/'.self::SLUG.'/app.js'));
        $this->assertFileDoesNotExist($this->plugins.'/'.self::SLUG.'/src/one.php', 'stale plugin file removed');
        $this->assertSame('two', file_get_contents($this->plugins.'/'.self::SLUG.'/src/two.php'));
        $this->assertNoResidue();
    }

    public function test_active_plugin_overwrite_is_refused_before_mutation(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $this->assertTrue(app('cms.extension')->activatePlugin(self::SLUG));
        $before = $this->unitHashes();

        $result = $this->install($this->v2(), true);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('currently active', $result->message);
        $this->assertSame($before, $this->unitHashes());
        $this->assertNoResidue();
    }

    // ---- overwrite: rollback per phase ----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}> op, path regex (normalised to /)
     */
    public static function failurePhases(): array
    {
        return [
            'staging copy' => ['copy', '#/\.tncms-txn/atomplug\.[a-z0-9]+/candidate$#'],
            'backup previous dir' => ['move', '#/plugins/atomplug$#'],
            'promote candidate dir' => ['move', '#/plugins/\.tncms-txn/atomplug\.[a-z0-9]+/candidate$#'],
            'stage assets' => ['copy', '#/plugins/atomplug/public$#'],
            'backup previous assets' => ['move', '#/public-vendor/atomplug$#'],
            'promote assets' => ['move', '#/public-vendor/\.tncms-txn/atomplug\.[a-z0-9]+/candidate$#'],
        ];
    }

    /** @dataProvider failurePhases */
    public function test_overwrite_failure_restores_previous_unit_byte_for_byte(string $op, string $pattern): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $before = $this->unitHashes();
        $db = $this->dbFingerprint();

        $this->failOn($op, $pattern);
        $result = $this->install($this->v2(), true);

        $this->assertFalse($result->success, 'the injected failure must be reported');
        $this->assertSame($before, $this->unitHashes(), 'previous plugin + assets restored byte-for-byte');
        $this->assertSame($db, $this->dbFingerprint(), 'database / activation state unchanged');
        $this->assertFalse(app('cms.extension')->isPluginActive(self::SLUG));
        $this->assertNoResidue();
        $this->assertNotContainsAbsolutePath($result);

        // Retry with the same valid candidate succeeds once the fault is gone.
        $this->useFilesystem(new ExtensionInstallFilesystem);
        $retry = $this->install($this->v2(), true);
        $this->assertTrue($retry->success, $retry->message);
        $this->assertSame('app-v2', file_get_contents($this->assets.'/'.self::SLUG.'/app.js'));
        $this->assertNoResidue();
    }

    public function test_overwrite_with_invalid_asset_source_preserves_previous_without_staging(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $before = $this->unitHashes();

        $result = $this->install($this->zip(['assets' => ['source' => 'missing-public']], ['src/x.php' => 'x']), true);

        $this->assertFalse($result->success);
        $this->assertSame($before, $this->unitHashes());
        $this->assertNoResidue();
    }

    public function test_overwrite_with_invalid_manifest_preserves_previous(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $before = $this->unitHashes();

        $this->assertFalse($this->install($this->zip(['version' => ''], ['src/x.php' => 'x']), true)->success);
        $this->assertSame($before, $this->unitHashes());
        $this->assertNoResidue();
    }

    public function test_candidate_without_assets_keeps_existing_namespace(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $assets = $this->treeHash($this->assets.'/'.self::SLUG);

        $result = $this->install($this->zip(['assets' => null], ['src/plain.php' => 'p']), true);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame($assets, $this->treeHash($this->assets.'/'.self::SLUG));
        $this->assertNoResidue();
    }

    // ---- cleanup + restoration failure -------------------------------------------------------------

    public function test_cleanup_failure_after_commit_keeps_valid_install_and_reports_residue(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $this->failOn('delete', '#/\.tncms-txn/atomplug\.[a-z0-9]+$#');

        $result = $this->install($this->v2(), true);

        $this->assertTrue($result->success, 'a committed install is not rolled back by a cleanup failure');
        $this->assertSame('app-v2', file_get_contents($this->assets.'/'.self::SLUG.'/app.js'));
        $this->assertNotSame([], $result->warnings);
        $this->assertStringContainsString('.tncms-txn', implode(' ', $result->warnings));
        $this->assertNotContainsAbsolutePath($result);
        $this->assertNotSame([], $this->listDir($this->plugins.'/.tncms-txn', true), 'identifiable Core-owned residue kept');
    }

    public function test_restoration_failure_is_reported_distinctly_and_keeps_the_previous_copy(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $before = $this->treeHash($this->plugins.'/'.self::SLUG);
        // Promotion fails, then putting the backup back fails as well.
        $this->failOn('move', '#/plugins/\.tncms-txn/atomplug\.[a-z0-9]+/(candidate|previous)$#');

        $result = $this->install($this->v2(), true);

        $this->assertFalse($result->success);
        $this->assertContains('rollback_incomplete', $result->errors);
        $this->assertNotContainsAbsolutePath($result);
        $kept = glob($this->plugins.'/.tncms-txn/atomplug.*/previous') ?: [];
        $this->assertCount(1, $kept, 'the untouched previous copy is preserved for recovery');
        $this->assertSame($before, $this->treeHash($kept[0]));
    }

    // ---- isolation, discovery, concurrency ----------------------------------------------------------

    public function test_failed_overwrite_never_touches_a_foreign_plugin_or_namespace(): void
    {
        $this->assertTrue($this->install($this->zip(['slug' => 'neighbor', 'assets' => ['source' => 'public']], ['src/n.php' => 'n', 'public/n.js' => 'n'], 'neighbor'))->success);
        $this->assertTrue($this->install($this->v1())->success);
        $foreign = [$this->treeHash($this->plugins.'/neighbor'), $this->treeHash($this->assets.'/neighbor')];

        $this->failOn('move', '#/public-vendor/\.tncms-txn/atomplug\.[a-z0-9]+/candidate$#');
        $this->assertFalse($this->install($this->v2(), true)->success);

        $this->assertSame($foreign, [$this->treeHash($this->plugins.'/neighbor'), $this->treeHash($this->assets.'/neighbor')]);
    }

    public function test_transaction_directories_are_never_discovered_or_deletable_as_extensions(): void
    {
        @mkdir($this->plugins.'/.tncms-txn/atomplug.abc/previous', 0o755, true);
        file_put_contents($this->plugins.'/.tncms-txn/atomplug.abc/previous/plugin.json', json_encode(['name' => 'X', 'slug' => 'atomplug', 'version' => '1', 'author' => 'x']));
        app('cms.extension')->flushRegistry();

        $this->assertArrayNotHasKey('atomplug', app('cms.extension')->plugins());
        $this->assertStringNotContainsString('tncms-txn', json_encode(app('cms.extension')->invalidPlugins()));
        $this->assertFalse(app('cms.extension_installer')->deletePlugin('.tncms-txn')->success);
        $this->assertDirectoryExists($this->plugins.'/.tncms-txn/atomplug.abc/previous');
    }

    public function test_concurrent_install_of_the_same_slug_is_refused_while_locked(): void
    {
        $this->assertTrue($this->install($this->v1())->success);
        $before = $this->unitHashes();

        $lockFile = ExtensionInstallTransaction::lockPathFor($this->plugins, self::SLUG);
        $script = $this->newTempDir().'/hold.php';
        $ready = dirname($script).'/ready';
        file_put_contents($script, '<?php $h = fopen($argv[1], "c"); flock($h, LOCK_EX); touch($argv[2]); sleep(20);');
        $proc = proc_open([PHP_BINARY, $script, $lockFile, $ready], [], $pipes);
        try {
            for ($i = 0; $i < 100 && ! is_file($ready); $i++) {
                usleep(100_000);
            }
            $this->assertFileExists($ready, 'child process holds the lock');

            $result = $this->install($this->v2(), true);
            $this->assertFalse($result->success);
            $this->assertStringContainsString('already in progress', $result->message);
            $this->assertSame($before, $this->unitHashes());

            // A different slug is never blocked by this lock.
            $other = $this->install($this->zip(['slug' => 'otherplug', 'assets' => null], ['src/o.php' => 'o'], 'otherplug'));
            $this->assertTrue($other->success, $other->message);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }

        $this->assertTrue($this->install($this->v2(), true)->success, 'lock released when the holder exits');
    }

    // ---- archive hardening ---------------------------------------------------------------------------

    public function test_case_colliding_and_colon_entries_are_rejected_before_extraction(): void
    {
        foreach ([['atomplug/src/A.php', 'atomplug/src/a.php'], ['atomplug/src/x.php:stream'], ['atomplug/phar:x.php']] as $extra) {
            $zip = $this->rawZip(array_merge(['atomplug/plugin.json' => json_encode($this->manifest(['assets' => null]))], array_fill_keys($extra, 'x')));
            $result = app('cms.extension_installer')->installPluginFromZip($zip, false);
            $this->assertFalse($result->success, implode(',', $extra));
            $this->assertMatchesRegularExpression('/invalid entry name|case-colliding/', $result->message);
        }
        $this->assertSame([], $this->listDir($this->plugins));
    }

    // ---- themes share the staged swap -----------------------------------------------------------------

    public function test_failed_theme_overwrite_restores_the_previous_theme(): void
    {
        $themes = dirname($this->plugins).'/themes';
        @mkdir($themes, 0o755, true);
        config(['cms.paths.themes' => $themes]);
        $theme = fn (string $v): string => $this->rawZip([
            'calm/theme.json' => json_encode(['name' => 'Calm', 'slug' => 'calm', 'version' => $v, 'author' => 'x']),
            'calm/views/layouts/master.blade.php' => 'master '.$v,
        ]);
        $this->assertTrue(app('cms.extension_installer')->installThemeFromZip($theme('1.0.0'), false)->success);
        $before = $this->treeHash($themes.'/calm');

        $this->failOn('move', '#/themes/\.tncms-txn/calm\.[a-z0-9]+/candidate$#');
        $result = app('cms.extension_installer')->installThemeFromZip($theme('2.0.0'), true);

        $this->assertFalse($result->success);
        $this->assertSame($before, $this->treeHash($themes.'/calm'));
        $this->assertDirectoryDoesNotExist($themes.'/.tncms-txn', 'no transaction residue beside the themes');
    }

    // ---- helpers ------------------------------------------------------------------------------------

    private function install(string $zip, bool $overwrite = false): InstallResult
    {
        return app('cms.extension_installer')->installPluginFromZip($zip, $overwrite);
    }

    private function useFilesystem(ExtensionInstallFilesystem $fs): void
    {
        $this->app->instance('cms.extension_install_fs', $fs);
        $this->app->forgetInstance('cms.extension_installer');
    }

    /** Fail every `$op` whose source path (normalised to "/") matches `$pattern`. */
    private function failOn(string $op, string $pattern): void
    {
        $this->useFilesystem(new class($op, $pattern) extends ExtensionInstallFilesystem
        {
            public function __construct(private readonly string $op, private readonly string $pattern) {}

            public function copyDirectory(string $from, string $to): void
            {
                $this->maybeFail('copy', $from, $to);
                parent::copyDirectory($from, $to);
            }

            public function move(string $from, string $to): void
            {
                $this->maybeFail('move', $from, $to);
                parent::move($from, $to);
            }

            public function deleteDirectory(string $path): void
            {
                $this->maybeFail('delete', $path, null);
                parent::deleteDirectory($path);
            }

            private function maybeFail(string $op, string $from, ?string $to): void
            {
                // Moves/deletes match their source; a copy matches its source OR its staging target.
                foreach ($op === 'copy' ? [$from, (string) $to] : [$from] as $path) {
                    if ($op === $this->op && preg_match($this->pattern, str_replace('\\', '/', $path)) === 1) {
                        throw new \RuntimeException("Injected {$op} failure at {$path}");
                    }
                }
            }
        });
    }

    private function v1(): string
    {
        return $this->zip(['version' => '1.0.0'], ['src/one.php' => 'one', 'public/app.js' => 'app-v1', 'public/old.css' => 'old']);
    }

    private function v2(): string
    {
        return $this->zip(['version' => '2.0.0'], ['src/two.php' => 'two', 'public/app.js' => 'app-v2', 'public/new.css' => 'new']);
    }

    /** @return array<string, mixed> */
    private function manifest(array $overrides, string $slug = self::SLUG): array
    {
        return array_filter(array_merge(
            ['name' => 'Atom', 'slug' => $slug, 'version' => '1.0.0', 'author' => 'ACME', 'assets' => ['source' => 'public']],
            $overrides,
        ), static fn ($v): bool => $v !== null);
    }

    /** @param  array<string, string>  $files */
    private function zip(array $manifest, array $files, string $slug = self::SLUG): string
    {
        $entries = [$slug.'/plugin.json' => json_encode($this->manifest($manifest, $slug))];
        foreach ($files as $rel => $content) {
            $entries[$slug.'/'.$rel] = $content;
        }

        return $this->rawZip($entries);
    }

    /** @param  array<string, string>  $entries */
    private function rawZip(array $entries): string
    {
        $path = $this->newTempDir().'/pkg.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return array{plugin: string, assets: string} */
    private function unitHashes(): array
    {
        return [
            'plugin' => $this->treeHash($this->plugins.'/'.self::SLUG),
            'assets' => $this->treeHash($this->assets.'/'.self::SLUG),
        ];
    }

    private function treeHash(string $dir): string
    {
        if (! is_dir($dir)) {
            return 'ABSENT';
        }
        $lines = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $lines[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir))).':'.hash_file('sha256', $f->getPathname());
            }
        }
        sort($lines);

        return count($lines).':'.hash('sha256', implode("\n", $lines));
    }

    /** @return array<string, mixed> */
    private function dbFingerprint(): array
    {
        return ['settings' => DB::table('cms_settings')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
    }

    private function assertNoResidue(): void
    {
        foreach ([$this->plugins, $this->assets] as $root) {
            $this->assertDirectoryDoesNotExist($root.'/.tncms-txn', "transaction residue under {$root}");
        }
        $this->assertSame([], glob(storage_path('app/tncms-installer/*'), GLOB_ONLYDIR) ?: [], 'extraction temp residue');
    }

    private function assertNotContainsAbsolutePath(InstallResult $result): void
    {
        $text = str_replace('\\', '/', $result->message.' '.implode(' ', $result->errors).' '.implode(' ', $result->warnings));
        foreach ([dirname($this->plugins), base_path(), sys_get_temp_dir()] as $root) {
            $this->assertStringNotContainsString(str_replace('\\', '/', $root), $text, 'user-facing result leaks a server path');
        }
    }

    /** @return list<string> */
    private function listDir(string $dir, bool $hidden = false): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $names = array_values(array_filter(scandir($dir) ?: [], fn (string $n): bool => $n !== '.' && $n !== '..' && ($hidden || $n[0] !== '.')));
        sort($names);

        return $names;
    }

    private function newTempDir(): string
    {
        $dir = sys_get_temp_dir().'/cpuar1-'.Str::random(12);
        @mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
