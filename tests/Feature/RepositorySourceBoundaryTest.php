<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PB-DIST-HYGIENE-2 — permanent guard for the repository source-tracking boundary.
 *
 * Root .gitignore had drifted into hiding platform/plugin SOURCE: bare-filename ignores of core
 * classes (e.g. ExtensionInstaller.php) and broad `tests/*` / `tests/Feature/*` hides. This guard
 * proves the boundary invariant — source code and tests are trackable, build output and local
 * artifacts stay ignored — so it can never silently regress. It asks Git directly (check-ignore /
 * ls-files); it never edits anything.
 */
final class RepositorySourceBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = base_path();
        if (! is_dir($this->root.'/.git') || $this->git('rev-parse', ['--is-inside-work-tree']) === null) {
            $this->markTestSkipped('Not a git work tree.');
        }
    }

    // ---- (1) core source is not ignored -----------------------------------------------------------

    /** @return list<array{0:string}> */
    public static function coreSource(): array
    {
        return [
            ['packages/thenguyen/cms-core/src/Services/ExtensionInstaller.php'],
            ['packages/thenguyen/cms-core/src/Services/ExtensionManager.php'],
            ['packages/thenguyen/cms-core/src/Services/ThemeManager.php'],
            ['packages/thenguyen/cms-core/src/Support/InstallResult.php'],
            ['packages/thenguyen/cms-core/src/Support/ExtensionDiscovery.php'],
            ['app/Filament/Admin/Pages/InstallPluginPage.php'],
        ];
    }

    #[DataProvider('coreSource')]
    public function test_core_source_is_not_ignored(string $path): void
    {
        $this->assertFalse($this->isIgnored($path), "Core source must be trackable: {$path}");
    }

    // ---- (2) plugin source is not ignored ---------------------------------------------------------

    public function test_plugin_source_is_not_ignored(): void
    {
        foreach ([
            'plugins/page-builder/src/PageBuilderServiceProvider.php',
            'plugins/page-builder/plugin.json',
            'plugins/page-builder/routes/web.php',
        ] as $path) {
            $this->assertFalse($this->isIgnored($path), "Plugin source must be trackable: {$path}");
        }
    }

    // ---- (3) feature/unit tests are not ignored ---------------------------------------------------

    public function test_tests_are_not_ignored(): void
    {
        foreach ([
            'tests/Feature/PluginAssetPublishingTest.php',
            'tests/Feature/PageBuilder/PageBuilderInstallablePackageContractTest.php',
            'tests/Unit/ExtensionDiscoveryTest.php',
            'tests/Feature/RepositorySourceBoundaryTest.php',
        ] as $path) {
            $this->assertFalse($this->isIgnored($path), "Test source must be trackable: {$path}");
        }
    }

    // ---- (4),(5),(6) artifacts remain ignored -----------------------------------------------------

    public function test_generated_and_dependency_artifacts_remain_ignored(): void
    {
        foreach ([
            'public/vendor/page-builder/editor/editor.js', // built PB bundles
            'public/themes/example/app.css',               // published theme assets
            'node_modules/foo/index.js',                   // (5) node_modules
            'vendor/autoload.php',                         // (6) composer vendor
            '.env',
        ] as $path) {
            $this->assertTrue($this->isIgnored($path), "Artifact must stay ignored: {$path}");
        }
    }

    // ---- (7) no broad tests/* source hiding -------------------------------------------------------

    public function test_no_broad_tests_hiding(): void
    {
        $lines = $this->gitignoreRules();
        $this->assertNotContains('tests/*', $lines, 'Broad "tests/*" hide must not exist.');
        $this->assertNotContains('tests/Feature/*', $lines, 'Broad "tests/Feature/*" hide must not exist.');
        // And a previously-hidden location is now trackable.
        $this->assertFalse($this->isIgnored('tests/Unit/ExtensionDiscoveryTest.php'));
    }

    // ---- (8) no filename-only ignore hiding classes -----------------------------------------------

    public function test_no_bare_filename_source_ignores(): void
    {
        $offenders = [];
        foreach ($this->gitignoreRules() as $rule) {
            // A bare "SomeClass.php" (no slash, not a glob) silently hides that file everywhere.
            if (preg_match('#^[A-Za-z0-9_]+\.php$#', $rule) === 1) {
                $offenders[] = $rule;
            }
        }
        $this->assertSame([], $offenders, 'Bare-filename .php ignores hide source classes: '.implode(', ', $offenders));
    }

    // ---- (9) existing tracked files remain tracked ------------------------------------------------

    public function test_existing_tracked_files_remain_tracked(): void
    {
        foreach ([
            'packages/thenguyen/cms-core/src/Services/ExtensionManager.php',
            'packages/thenguyen/cms-core/src/Services/ThemeManager.php',
            'plugins/page-builder/plugin.json',
            'tests/Feature/PageBuilder/PageBuilderInstallablePackageContractTest.php',
        ] as $path) {
            $this->assertTrue($this->isTracked($path), "Previously-tracked file must remain tracked: {$path}");
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function isIgnored(string $path): bool
    {
        // Exit 0 => ignored. check-ignore reports the EFFECTIVE status (tracked files are never ignored).
        return $this->git('check-ignore', ['-q', $path], allowFailure: true) !== null
            ? $this->lastExit === 0
            : false;
    }

    private function isTracked(string $path): bool
    {
        $this->git('ls-files', ['--error-unmatch', $path], allowFailure: true);

        return $this->lastExit === 0;
    }

    /** @return list<string> non-comment, non-blank .gitignore rules */
    private function gitignoreRules(): array
    {
        $raw = (array) file($this->root.'/.gitignore', FILE_IGNORE_NEW_LINES);
        $rules = [];
        foreach ($raw as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '' && ! str_starts_with($trimmed, '#')) {
                $rules[] = $trimmed;
            }
        }

        return $rules;
    }

    private int $lastExit = -1;

    /**
     * Run a git subcommand from the repo root. Returns trimmed stdout, or null on failure unless
     * $allowFailure. Records the exit code in $this->lastExit.
     *
     * @param  list<string>  $args
     */
    private function git(string $subcommand, array $args = [], bool $allowFailure = false): ?string
    {
        $cmd = 'git -C '.escapeshellarg($this->root).' '.escapeshellarg($subcommand);
        foreach ($args as $a) {
            $cmd .= ' '.escapeshellarg($a);
        }
        $null = \DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';
        $out = [];
        exec($cmd.' '.$null, $out, $code);
        $this->lastExit = $code;
        if ($code !== 0 && ! $allowFailure) {
            return null;
        }

        return implode("\n", $out);
    }
}
