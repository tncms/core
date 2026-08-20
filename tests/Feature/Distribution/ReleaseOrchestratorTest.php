<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Distribution\ReleaseOrchestrator;

/**
 * CORE-RELEASE-1 — focused tests for the unified release orchestrator.
 *
 * Every gate is exercised through the orchestrator's injected seams (a fake git
 * runner + a fake publisher that produces real, verify.php-passing profile ZIPs)
 * against synthetic fixture trees — never a test-only production bypass and never
 * a real multi-minute composer/npm build. The real manifest.php + verify.php are
 * used unchanged so the boundary/verifier authority under test is the shipping one.
 *
 * Covers the 15 required focus areas: (1) clean-tree gate, (2) dirty-tree
 * rejection, (3) version authority, (4) changelog/version mismatch, (5) three-
 * profile orchestration, (6) one-profile failure blocks release, (7) SHA256
 * sidecars, (8) release-manifest schema/content, (9) cross-profile version
 * consistency, (10) Core-only boundary, (11) failed attempt cannot overwrite a
 * certified release, (12) same-version conflict protection, (13) promotion only
 * after all gates, (14) non-zero failure exit, (15) dry-run never certifies.
 */
final class ReleaseOrchestratorTest extends TestCase
{
    private const VERSION = '9.9.9-test.1';
    private const COMMIT = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    /** @var list<string> */
    private array $temps = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('tools/distribution/ReleaseOrchestrator.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->temps as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    // ---- (3) version authority --------------------------------------------

    #[Test]
    public function it_reads_the_canonical_version_from_the_manifest_source(): void
    {
        $o = $this->orchestrator();
        $this->assertSame(self::VERSION, $o->readVersion());
    }

    // ---- (1)(2) clean-tree gate + dirty-tree rejection --------------------

    #[Test]
    public function clean_tree_gate_reflects_git_status(): void
    {
        $clean = $this->orchestrator(git: $this->fakeGit(clean: true));
        $this->assertTrue($clean->checkCleanTree()['ok']);

        $dirty = $this->orchestrator(git: $this->fakeGit(clean: false));
        $res = $dirty->checkCleanTree();
        $this->assertFalse($res['ok'], 'A dirty working tree must be rejected.');
        $this->assertNotEmpty($res['dirty']);
    }

    // ---- (4) changelog / version mismatch ---------------------------------

    #[Test]
    public function changelog_gate_requires_the_top_heading_to_match_version(): void
    {
        $ok = $this->orchestrator(changelogTop: self::VERSION);
        $this->assertTrue($ok->checkChangelog(self::VERSION)['ok']);

        $mismatch = $this->orchestrator(changelogTop: '0.0.0-other');
        $res = $mismatch->checkChangelog(self::VERSION);
        $this->assertFalse($res['ok'], 'A changelog whose top entry != target version must fail.');
        $this->assertSame('0.0.0-other', $res['top']);
    }

    // ---- prerequisites -----------------------------------------------------

    #[Test]
    public function prerequisites_gate_requires_node_and_npm(): void
    {
        $ok = $this->orchestrator(provenance: ['node' => 'v20', 'npm' => '10']);
        $this->assertTrue($ok->checkPrerequisites()['ok']);

        $missing = $this->orchestrator(provenance: ['node' => 'v20', 'npm' => '']);
        $this->assertFalse($missing->checkPrerequisites()['ok']);
    }

    // ---- (10) Core-only boundary ------------------------------------------

    #[Test]
    public function boundary_proof_detects_a_forbidden_plugin_and_passes_a_clean_tree(): void
    {
        $o = $this->orchestrator();

        $clean = $this->makeTempDir();
        $this->writeProfileTree($clean, 'install');
        $good = $o->boundaryProof($clean);
        $this->assertTrue($good['ok'], 'A hello-world + default-only tree is within boundary.');
        $this->assertContains('plugins/page-builder', $good['absent']);
        $this->assertContains('plugins/ecommerce', $good['absent']);
        $this->assertContains('plugins/knowledge-library', $good['absent']);

        // Smuggle a forbidden plugin in.
        @mkdir($clean.'/plugins/ecommerce', 0777, true);
        file_put_contents($clean.'/plugins/ecommerce/plugin.json', '{}');
        $bad = $o->boundaryProof($clean);
        $this->assertFalse($bad['ok'], 'A forbidden plugin must break the boundary.');
        $this->assertNotEmpty($bad['violations']);
    }

    // ---- (9) cross-artifact version consistency ---------------------------

    #[Test]
    public function cross_artifact_detects_a_version_mismatch(): void
    {
        $o = $this->orchestrator();
        $good = $o->crossArtifact($this->extractsFor(self::VERSION, self::VERSION, self::VERSION), self::VERSION, self::COMMIT);
        $this->assertTrue($good['ok']);

        $bad = $o->crossArtifact($this->extractsFor(self::VERSION, '1.2.3-other', self::VERSION), self::VERSION, self::COMMIT);
        $this->assertFalse($bad['ok'], 'Profiles at different versions are not one release.');
    }

    // ---- (5)(7)(8)(13) full orchestration + manifest + sidecars -----------

    #[Test]
    public function it_orchestrates_three_profiles_and_promotes_a_certified_release(): void
    {
        $outBase = $this->makeTempDir();
        $o = $this->orchestrator(outBase: $outBase, git: $this->fakeGit(clean: true), publisher: $this->fakePublisher());

        $this->assertSame(0, $o->run(), 'A clean, consistent build must certify.');

        $dir = $outBase.'/dist/releases/'.self::VERSION;
        $this->assertDirectoryExists($dir);

        // (5) three profiles present.
        foreach (['source', 'install', 'upgrade'] as $p) {
            $zip = $dir.'/tncms-'.self::VERSION.'-'.$p.'.zip';
            $this->assertFileExists($zip, "profile $p zip promoted");
            // (7) SHA256 sidecar present and matches the final bytes.
            $sidecar = $zip.'.sha256';
            $this->assertFileExists($sidecar);
            $sha = strtolower(trim(explode(' ', (string) file_get_contents($sidecar))[0]));
            $this->assertSame(hash_file('sha256', $zip), $sha, "sidecar matches bytes for $p");
        }

        // (8) release-manifest schema/content.
        $manifest = json_decode((string) file_get_contents($dir.'/release-manifest.json'), true);
        $this->assertSame(ReleaseOrchestrator::RELEASE_SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame('TNCMS Core', $manifest['product']);
        $this->assertSame(self::VERSION, $manifest['core_version']);
        $this->assertSame(self::COMMIT, $manifest['source_commit']);
        $this->assertSame('CERTIFIED', $manifest['certification_status']);
        $this->assertArrayHasKey('build_provenance', $manifest);
        foreach (['source', 'install', 'upgrade'] as $p) {
            $this->assertArrayHasKey($p, $manifest['profiles']);
            $this->assertSame(hash_file('sha256', $dir.'/'.$manifest['profiles'][$p]['filename']), $manifest['profiles'][$p]['sha256']);
            $this->assertGreaterThan(0, $manifest['profiles'][$p]['size_bytes']);
            $this->assertTrue($manifest['profiles'][$p]['verifier_pass']);
        }
        $this->assertSame('core-upgrade', $manifest['profiles']['upgrade']['package_type']);

        // (13) certification report written only alongside the promoted set.
        $this->assertFileExists($dir.'/RELEASE-CERTIFICATION.txt');
        $this->assertStringContainsString('RELEASE CERTIFIED', (string) file_get_contents($dir.'/RELEASE-CERTIFICATION.txt'));
        $this->assertFileExists($dir.'/RELEASE-CERTIFICATION.json');

        // No attempt directory left behind.
        $this->assertEmpty(glob($outBase.'/_release-attempt-*') ?: []);
    }

    // ---- (6)(13)(14) one-profile failure blocks release -------------------

    #[Test]
    public function a_single_profile_build_failure_blocks_the_whole_release(): void
    {
        $outBase = $this->makeTempDir();
        $o = $this->orchestrator(outBase: $outBase, publisher: $this->fakePublisher(failOn: 'upgrade'));

        $this->assertSame(1, $o->run(), 'Failure must exit non-zero.');
        $this->assertDirectoryDoesNotExist($outBase.'/dist/releases/'.self::VERSION, 'No release promoted on failure.');
        $this->assertEmpty(glob($outBase.'/_release-attempt-*') ?: [], 'Failed attempt dir cleaned up.');
    }

    // ---- (12) same-version conflict protection ----------------------------

    #[Test]
    public function an_already_certified_version_is_not_overwritten_by_default(): void
    {
        $outBase = $this->makeTempDir();
        $first = $this->orchestrator(outBase: $outBase, publisher: $this->fakePublisher());
        $this->assertSame(0, $first->run());

        $dir = $outBase.'/dist/releases/'.self::VERSION;
        $marker = $dir.'/release-manifest.json';
        $before = (string) file_get_contents($marker);

        // Second run WITHOUT --rebuild must be refused at the collision gate.
        $second = $this->orchestrator(outBase: $outBase, publisher: $this->fakePublisher());
        $this->assertFalse($second->checkCollision(self::VERSION)['ok']);
        $this->assertSame(1, $second->run(), 'Re-certifying an existing version is refused.');
        $this->assertSame($before, (string) file_get_contents($marker), 'Existing certified set untouched.');
    }

    // ---- (11) failed rebuild cannot destroy the certified set -------------

    #[Test]
    public function a_failed_rebuild_attempt_cannot_overwrite_the_certified_release(): void
    {
        $outBase = $this->makeTempDir();
        $first = $this->orchestrator(outBase: $outBase, publisher: $this->fakePublisher());
        $this->assertSame(0, $first->run());

        $dir = $outBase.'/dist/releases/'.self::VERSION;
        $before = (string) file_get_contents($dir.'/release-manifest.json');

        // --rebuild passes the collision gate, but the build fails mid-way.
        $rebuild = $this->orchestrator(
            outBase: $outBase,
            publisher: $this->fakePublisher(failOn: 'install'),
            options: ['rebuild' => true],
        );
        $this->assertSame(1, $rebuild->run());
        $this->assertDirectoryExists($dir, 'Prior certified set survives a failed rebuild.');
        $this->assertSame($before, (string) file_get_contents($dir.'/release-manifest.json'), 'Prior bytes untouched.');
    }

    // ---- (15) dry-run never claims certification --------------------------

    #[Test]
    public function dry_run_validates_without_building_or_certifying(): void
    {
        $outBase = $this->makeTempDir();
        $o = $this->orchestrator(outBase: $outBase, options: ['dry_run' => true]);

        $this->assertSame(0, $o->run(), 'Dry-run of a clean tree returns success.');
        $this->assertDirectoryDoesNotExist($outBase.'/dist/releases/'.self::VERSION, 'Dry-run promotes nothing.');
        $this->assertEmpty(glob($outBase.'/_release-attempt-*') ?: [], 'Dry-run builds nothing.');
    }

    // =======================================================================
    // Fixtures / seams
    // =======================================================================

    /**
     * @param callable(string):array{0:int,1:list<string>}|null $git
     * @param callable(string,string):array{code:int,output:string}|null $publisher
     * @param array<string,string> $provenance
     * @param array{dry_run?:bool,rebuild?:bool} $options
     */
    private function orchestrator(
        ?string $outBase = null,
        ?callable $git = null,
        ?callable $publisher = null,
        array $provenance = ['node' => 'v20', 'npm' => '10'],
        ?string $changelogTop = self::VERSION,
        array $options = [],
    ): ReleaseOrchestrator {
        $repoRoot = $this->makeRepoRoot($changelogTop);

        return new ReleaseOrchestrator([
            'repo_root' => $repoRoot,
            'tools_dir' => base_path('tools/distribution'),
            'manifest' => require base_path('tools/distribution/manifest.php'),
            'out_base' => $outBase ?? $this->makeTempDir(),
            'git' => $git ?? $this->fakeGit(clean: true),
            'publisher' => $publisher ?? $this->fakePublisher(),
            'clock' => static fn (): string => '2026-08-20T00:00:00+00:00',
            'provenance' => $provenance,
            'options' => $options,
            'log' => static function (string $s): void { /* silent in tests */ },
        ]);
    }

    /** @return callable(string):array{0:int,1:list<string>} */
    private function fakeGit(bool $clean): callable
    {
        return static function (string $args) use ($clean): array {
            if (str_contains($args, 'rev-parse HEAD')) {
                return [0, [self::COMMIT]];
            }
            if (str_contains($args, 'status --porcelain')) {
                return [0, $clean ? [] : [' M some/file.php']];
            }

            return [0, []];
        };
    }

    /**
     * Fake publisher: writes a real, verify.php-passing ZIP for the profile at the
     * exact path the orchestrator expects, plus a .sha256 sidecar — mirroring
     * publish.php's output contract without a composer/npm build.
     *
     * @return callable(string,string):array{code:int,output:string}
     */
    private function fakePublisher(?string $failOn = null): callable
    {
        $self = $this;

        return static function (string $profile, string $attemptDir) use ($self, $failOn): array {
            if ($profile === $failOn) {
                return ['code' => 1, 'output' => 'simulated build failure'];
            }
            $tree = $self->makeTempDir();
            $self->writeProfileTree($tree, $profile);

            @mkdir($attemptDir.'/dist', 0777, true);
            $zip = $attemptDir.'/dist/tncms-'.self::VERSION.'-'.$profile.'.zip';
            $self->zipDir($tree, $zip);
            file_put_contents($zip.'.sha256', hash_file('sha256', $zip).'  '.basename($zip)."\n");

            return ['code' => 0, 'output' => ''];
        };
    }

    /** Build a minimal, verify.php-passing export tree for the profile. */
    public function writeProfileTree(string $root, string $profile): void
    {
        $put = static function (string $rel, string $body = '') use ($root): void {
            $path = $root.'/'.$rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $body);
        };

        // Base identity (all profiles).
        $put('LICENSE', "MIT License\n");
        $put('composer.json', '{}');
        $put('artisan', '<?php');
        $put('bootstrap/providers.php', '<?php return [];');
        $put('packages/thenguyen/cms-core/src/Support/CmsInfo.php', "<?php const VERSION = '".self::VERSION."';");
        $put('plugins/hello-world/plugin.json', '{}');
        $put('themes/default/theme.json', '{}');
        $put('tncms-core.dist.json', (string) json_encode([
            'product' => 'TNCMS Core', 'profile' => $profile, 'version' => self::VERSION,
            'source_commit' => self::COMMIT, 'plugin_count' => 1, 'theme_count' => 1,
            'demo_plugin' => 'plugins/hello-world', 'default_theme' => 'themes/default',
            'excluded_plugin_count' => 8, 'excluded_theme_count' => 1, 'sanitized' => [],
        ]));

        if ($profile === 'source') {
            return; // no vendor/, no node_modules/, no built assets.
        }

        // install + upgrade runtime payload.
        $put('bootstrap/app.php', '<?php');
        $put('.env.example', 'APP_URL=http://localhost');
        $put('composer.lock', '{}');
        $put('vendor/autoload.php', '<?php');
        $put('public/build/manifest.json', '{}');
        foreach ([
            'public/themes/default/css/tokens.css', 'public/themes/default/css/app.css',
            'public/themes/default/css/sections.css', 'public/themes/default/js/app.js',
            'public/themes/default/js/accordion.js',
        ] as $asset) {
            $put($asset, '/* built */');
        }

        if ($profile !== 'upgrade') {
            return;
        }

        // upgrade package manifests.
        $core = [
            'product' => 'TNCMS', 'version' => self::VERSION, 'generated_at' => '2026-08-20T00:00:00+00:00',
            'ownership' => [
                'replace_dirs' => ['app', 'vendor', 'public/build', 'public/themes/default', 'plugins/hello-world', 'themes/default'],
                'preserve_prefixes' => ['.env', 'storage/', 'public/uploads/'],
                'preserve_siblings' => ['plugins' => ['hello-world'], 'themes' => ['default']],
            ],
            'files' => [
                'vendor/autoload.php' => 'x', 'public/build/manifest.json' => 'x', 'artisan' => 'x',
            ],
            'file_count' => 3,
        ];
        $put('tncms-core.manifest.json', (string) json_encode($core, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $coreSha = hash_file('sha256', $root.'/tncms-core.manifest.json');
        $put('tncms-upgrade.json', (string) json_encode([
            'product' => 'TNCMS', 'package_type' => 'core-upgrade', 'target_version' => self::VERSION,
            'minimum_supported_source_version' => '1.0.0-beta.7.0.0', 'php_requirement' => '8.3.0',
            'source_commit' => self::COMMIT, 'generated_at' => '2026-08-20T00:00:00+00:00',
            'has_migrations' => false, 'default_theme_core_owned' => true, 'hello_world_core_owned' => true,
            'core_manifest_file' => 'tncms-core.manifest.json', 'core_manifest_sha256' => $coreSha,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Synthetic cross-artifact extract inputs (dist.json per profile).
     *
     * @return array<string,array{dist:array<string,mixed>,upgrade:?array<string,mixed>}>
     */
    private function extractsFor(string $vSource, string $vInstall, string $vUpgrade): array
    {
        $dist = static fn (string $p, string $v): array => [
            'product' => 'TNCMS Core', 'profile' => $p, 'version' => $v,
            'source_commit' => self::COMMIT, 'plugin_count' => 1, 'theme_count' => 1,
        ];

        return [
            'source' => ['dist' => $dist('source', $vSource), 'upgrade' => null],
            'install' => ['dist' => $dist('install', $vInstall), 'upgrade' => null],
            'upgrade' => [
                'dist' => $dist('upgrade', $vUpgrade),
                'upgrade' => ['target_version' => $vUpgrade, 'package_type' => 'core-upgrade'],
            ],
        ];
    }

    private function makeRepoRoot(?string $changelogTop): string
    {
        $root = $this->makeTempDir();
        @mkdir($root.'/packages/thenguyen/cms-core/src/Support', 0777, true);
        file_put_contents(
            $root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php',
            "<?php const VERSION = '".self::VERSION."';",
        );
        $top = $changelogTop ?? self::VERSION;
        file_put_contents(
            $root.'/CMS_CHANGELOG.md',
            "# Changelog\n\n## [{$top}] — test\n\nBody.\n",
        );

        return $root;
    }

    // ---- tiny fs helpers ---------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/tncms-rel-'.uniqid('', true);
        @mkdir($dir, 0777, true);
        $this->temps[] = $dir;

        return str_replace('\\', '/', $dir);
    }

    public function zipDir(string $dir, string $zipPath): void
    {
        $za = new \ZipArchive();
        $za->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $len = strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $za->addFile($f->getPathname(), substr(str_replace('\\', '/', $f->getPathname()), $len));
            }
        }
        $za->close();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir.'/'.$e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
