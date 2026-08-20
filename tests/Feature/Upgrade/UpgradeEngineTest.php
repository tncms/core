<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use TheNguyen\CMS\Upgrade\CoreBackup;
use TheNguyen\CMS\Upgrade\CoreOwnership;
use TheNguyen\CMS\Upgrade\UpgradeApplyEngine;
use TheNguyen\CMS\Upgrade\UpgradeId;
use TheNguyen\CMS\Upgrade\UpgradeLock;
use TheNguyen\CMS\Upgrade\UpgradeManager;
use TheNguyen\CMS\Upgrade\UpgradePackage;
use TheNguyen\CMS\Upgrade\UpgradeState;
use TheNguyen\CMS\Upgrade\UpgradeStateStore;
use ZipArchive;

/**
 * CORE-UPGRADE-1 — hermetic engine certification (Steps 4–6).
 *
 * Exercises the durable state machine, lock, package verification, safe staging,
 * file/.env backup + hard verification gate, replace-not-merge apply, stale
 * removal and persistent-state preservation on a temp fixture tree — no MySQL,
 * no HTTP. The real ownership model (tools/distribution/manifest.php) drives the
 * classification so the test proves the shipped model, not a stub.
 */
final class UpgradeEngineTest extends TestCase
{
    use BuildsUpgradeFixtures;

    private string $tmp;

    /** @var list<string> */
    public array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/tncms-upg-'.bin2hex(random_bytes(5));
        @mkdir($this->tmp, 0775, true);
        $this->cleanup[] = $this->tmp;
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            $this->rrmdir($dir);
        }
        parent::tearDown();
    }

    // ================================================================
    // State machine + id + lock
    // ================================================================

    #[Test]
    public function state_machine_allows_legal_and_rejects_illegal_transitions(): void
    {
        $this->assertTrue(UpgradeState::canTransition(UpgradeState::UPLOADED, UpgradeState::VERIFIED));
        $this->assertTrue(UpgradeState::canTransition(UpgradeState::HEALTH_CHECK, UpgradeState::COMPLETED));
        $this->assertFalse(UpgradeState::canTransition(UpgradeState::UPLOADED, UpgradeState::COMPLETED));
        $this->assertFalse(UpgradeState::canTransition(UpgradeState::COMPLETED, UpgradeState::REPLACING));

        $this->assertTrue(UpgradeState::isTerminal(UpgradeState::COMPLETED));
        $this->assertTrue(UpgradeState::isTerminal(UpgradeState::FAILED));
        $this->assertFalse(UpgradeState::isTerminal(UpgradeState::REPLACING));

        $this->assertFalse(UpgradeState::hasVerifiedBackup(UpgradeState::PREFLIGHT_PASSED));
        $this->assertTrue(UpgradeState::hasVerifiedBackup(UpgradeState::BACKUP_VERIFIED));
        $this->assertFalse(UpgradeState::hasMutatedLiveState(UpgradeState::MAINTENANCE));
        $this->assertTrue(UpgradeState::hasMutatedLiveState(UpgradeState::REPLACING));

        $this->expectException(\InvalidArgumentException::class);
        UpgradeState::assertTransition(UpgradeState::UPLOADED, UpgradeState::COMPLETED);
    }

    #[Test]
    public function upgrade_id_is_valid_unique_and_not_derived_from_filename(): void
    {
        $a = UpgradeId::generate();
        $b = UpgradeId::generate();
        $this->assertTrue(UpgradeId::isValid($a));
        $this->assertNotSame($a, $b);
        $this->assertFalse(UpgradeId::isValid('../evil'));
        $this->assertFalse(UpgradeId::isValid('package.zip'));
    }

    #[Test]
    public function state_store_persists_history_and_rejects_illegal_transition(): void
    {
        $store = new UpgradeStateStore($this->tmp.'/upgrades');
        $id = UpgradeId::generate();
        $store->create($id, ['target_version' => '1.0.0-beta.7.2.0']);

        $store->transition($id, UpgradeState::VERIFIED, 'verified');
        $loaded = $store->load($id);
        $this->assertSame(UpgradeState::VERIFIED, $loaded['status']);
        $this->assertCount(2, $loaded['history']);
        $this->assertSame($id, $store->active()['upgrade_id']);

        $this->expectException(\InvalidArgumentException::class);
        $store->transition($id, UpgradeState::COMPLETED, 'illegal');
    }

    #[Test]
    public function state_store_records_failure_stage(): void
    {
        $store = new UpgradeStateStore($this->tmp.'/upgrades');
        $id = UpgradeId::generate();
        $store->create($id);
        $store->transition($id, UpgradeState::FAILED, 'boom');
        $s = $store->load($id);
        $this->assertSame(UpgradeState::UPLOADED, $s['failure_stage']);
        $this->assertSame('boom', $s['failure_reason']);
        $this->assertNull($store->active(), 'a terminal state is not active');
    }

    #[Test]
    public function lock_is_single_writer_and_reclaims_when_stale(): void
    {
        $lock = new UpgradeLock($this->tmp.'/upgrades');
        $a = UpgradeId::generate();
        $b = UpgradeId::generate();

        $this->assertTrue($lock->acquire($a));
        $this->assertTrue($lock->acquire($a), 're-acquire own lock is idempotent');
        $this->assertFalse($lock->acquire($b), 'second upgrade is blocked');
        $this->assertFalse($lock->release($b), 'non-owner cannot release');
        $this->assertTrue($lock->release($a));
        $this->assertTrue($lock->acquire($b), 'released lock is available');

        // Stale reclaim.
        @file_put_contents($lock->path(), json_encode([
            'upgrade_id' => $a, 'pid' => 1, 'acquired_at' => gmdate('c', time() - 7200),
        ]));
        $this->assertTrue($lock->isStale());
        $this->assertTrue($lock->acquire($b), 'stale lock is reclaimable');
    }

    // ================================================================
    // Ownership classification
    // ================================================================

    #[Test]
    public function ownership_classifies_core_owned_vs_preserved(): void
    {
        $m = $this->model();
        $this->assertTrue(CoreOwnership::isCoreOwned('packages/thenguyen/cms-core/src/Support/CmsInfo.php', $m));
        $this->assertTrue(CoreOwnership::isCoreOwned('vendor/autoload.php', $m));
        $this->assertTrue(CoreOwnership::isCoreOwned('plugins/hello-world/plugin.json', $m));
        $this->assertTrue(CoreOwnership::isCoreOwned('artisan', $m));

        $this->assertTrue(CoreOwnership::isPreserved('.env', $m));
        $this->assertTrue(CoreOwnership::isPreserved('storage/app/media/x.jpg', $m));
        $this->assertTrue(CoreOwnership::isPreserved('public/uploads/pic.png', $m));
        $this->assertTrue(CoreOwnership::isPreserved('plugins/ecommerce/plugin.json', $m));
        $this->assertTrue(CoreOwnership::isPreserved('themes/company/theme.json', $m));
        $this->assertFalse(CoreOwnership::isCoreOwned('.env', $m));
        $this->assertFalse(CoreOwnership::isCoreOwned('plugins/ecommerce/plugin.json', $m));
    }

    #[Test]
    public function derive_obsolete_only_returns_provably_core_owned_dropped_files(): void
    {
        $m = $this->model();
        $old = ['app/Old.php' => 'h1', 'config/cms.php' => 'h2', '.env' => 'h3', 'plugins/ecommerce/x.php' => 'h4'];
        $new = ['config/cms.php' => 'h2b'];
        $obsolete = CoreOwnership::deriveObsolete($old, $new, $m);
        $this->assertContains('app/Old.php', $obsolete);
        $this->assertNotContains('config/cms.php', $obsolete, 'still shipped');
        $this->assertNotContains('.env', $obsolete, 'never delete preserved');
        $this->assertNotContains('plugins/ecommerce/x.php', $obsolete, 'never delete non-core');
    }

    // ================================================================
    // Package verify + safe staging
    // ================================================================

    #[Test]
    public function package_verify_passes_for_a_wellformed_upgrade_and_stages_with_checksums(): void
    {
        $zip = $this->tmp.'/pkg.zip';
        $this->buildPackage($zip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');

        $pkg = new UpgradePackage($zip);
        $res = $pkg->verify('1.0.0-beta.7.1.15');
        $this->assertTrue($res['ok'], json_encode($res['checks']));
        $this->assertSame('1.0.0-beta.7.2.0', $res['target_version']);

        $staging = $this->tmp.'/staging';
        $staged = $pkg->stageTo($staging);
        $this->assertTrue($staged['ok'], $staged['reason']);
        $this->assertGreaterThan(0, $staged['verified']);
        $this->assertFileExists($staging.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php');
    }

    #[Test]
    public function package_verify_rejects_downgrade_wrong_product_and_tamper(): void
    {
        // Downgrade.
        $zip = $this->tmp.'/down.zip';
        $this->buildPackage($zip, '1.0.0-beta.7.1.0', '1.0.0-beta.7.0.0');
        $res = (new UpgradePackage($zip))->verify('1.0.0-beta.7.1.15');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('newer', $res['reason']);

        // Wrong product.
        $zip2 = $this->tmp.'/wrong.zip';
        $this->buildPackage($zip2, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0', product: 'NOTTNCMS');
        $res2 = (new UpgradePackage($zip2))->verify('1.0.0-beta.7.1.15');
        $this->assertFalse($res2['ok']);

        // Tampered core manifest (checksum mismatch).
        $zip3 = $this->tmp.'/tamper.zip';
        $this->buildPackage($zip3, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0', tamperCore: true);
        $res3 = (new UpgradePackage($zip3))->verify('1.0.0-beta.7.1.15');
        $this->assertFalse($res3['ok']);
        $this->assertStringContainsStringIgnoringCase('checksum', $res3['reason']);
    }

    #[Test]
    public function package_verify_rejects_path_traversal_entry(): void
    {
        $zip = $this->tmp.'/evil.zip';
        $this->buildPackage($zip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');
        // Append a traversal entry.
        $za = new ZipArchive();
        $za->open($zip);
        $za->addFromString('../evil.txt', 'x');
        $za->close();

        $res = (new UpgradePackage($zip))->verify('1.0.0-beta.7.1.15');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsStringIgnoringCase('traversal', $res['reason']);
    }

    // ================================================================
    // File backup + hard verification gate
    // ================================================================

    #[Test]
    public function core_backup_snapshots_owned_files_only_and_verify_detects_tamper(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15');
        $backup = new CoreBackup($root);
        $refs = $backup->backup($root.'/storage/app/upgrades/backup', $this->model());

        $this->assertGreaterThan(0, $refs['files_count']);
        $this->assertNotNull($refs['env_file']);
        $this->assertTrue($backup->verify($refs)['ok']);

        // Backed-up set must NOT include preserved paths.
        $za = new ZipArchive();
        $za->open($refs['files_zip']);
        $names = [];
        for ($i = 0; $i < $za->numFiles; $i++) {
            $names[] = $za->getNameIndex($i);
        }
        $za->close();
        $this->assertNotContains('.env', $names);
        $this->assertNotContains('storage/app/media/photo.txt', $names);
        $this->assertContains('packages/thenguyen/cms-core/src/Support/CmsInfo.php', $names);

        // Tamper → hard verify fails.
        file_put_contents($refs['files_zip'], 'corrupt');
        $this->assertFalse($backup->verify($refs)['ok']);
    }

    // ================================================================
    // Apply engine — replace, preserve, stale removal
    // ================================================================

    #[Test]
    public function apply_engine_replaces_core_preserves_site_and_removes_stale(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15', withObsolete: true);
        $staging = $this->tmp.'/staging';
        $this->buildStagingTree($staging, '1.0.0-beta.7.2.0');

        $m = $this->model();
        $old = CoreOwnership::ownedFiles($root, $m);
        $new = CoreOwnership::ownedFiles($staging, $m);
        $this->assertArrayHasKey('app/Obsolete.php', $old);
        $this->assertArrayNotHasKey('app/Obsolete.php', $new);

        $result = (new UpgradeApplyEngine($root))->apply($staging, $m, $old, $new);

        // Core replaced.
        $this->assertStringContainsString('1.0.0-beta.7.2.0', file_get_contents($root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php'));
        $this->assertStringContainsString('v2', file_get_contents($root.'/plugins/hello-world/plugin.json'));
        // Stale Core file gone.
        $this->assertFileDoesNotExist($root.'/app/Obsolete.php');
        // Preserved site state intact.
        $this->assertStringContainsString('keepme', file_get_contents($root.'/.env'));
        $this->assertFileExists($root.'/storage/app/media/photo.txt');
        $this->assertFileExists($root.'/public/uploads/pic.txt');
        $this->assertFileExists($root.'/plugins/acme/plugin.json');
        $this->assertFileExists($root.'/themes/company/theme.json');
        $this->assertFileExists($root.'/public/themes/company/style.css');
        $this->assertContains('app/Obsolete.php', $result['removed_stale']);
    }

    #[Test]
    public function apply_engine_refuses_to_touch_a_preserved_path(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15');
        // Ownership model whose replace_files illegally names a preserved file.
        $m = $this->model();
        $m['replace_files'][] = '.env';
        $staging = $this->tmp.'/staging';
        $this->buildStagingTree($staging, '1.0.0-beta.7.2.0');
        @mkdir($staging, 0775, true);
        file_put_contents($staging.'/.env', 'MALICIOUS=1');

        $this->expectException(RuntimeException::class);
        (new UpgradeApplyEngine($root))->apply($staging, $m, [], []);
    }

    // ================================================================
    // Manager end-to-end (filesystem-only) + hard backup gate + idempotency
    // ================================================================

    #[Test]
    public function manager_runs_full_upgrade_and_preserves_site_state(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15', withObsolete: true, withRecordedOwnership: true);
        $pkgZip = $this->tmp.'/pkg.zip';
        $this->buildPackage($pkgZip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');

        $mgr = $this->manager($root);
        $recv = $mgr->receivePackage($pkgZip, fn (string $dest) => copy($pkgZip, $dest));
        $id = $recv['id'];

        $this->assertTrue($mgr->verifyPackage($id)['ok']);
        // Simulate a passed preflight (system-check is asserted independently).
        $mgr->store()->transition($id, UpgradeState::PREFLIGHT_PASSED, 'preflight ok (test)');

        $backup = $mgr->runBackup($id);
        $this->assertTrue($backup['ok'], $backup['reason']);
        $this->assertSame(UpgradeState::BACKUP_VERIFIED, $mgr->store()->load($id)['status']);

        $apply = $mgr->apply($id);
        $this->assertTrue($apply['ok'], $apply['reason']);
        $this->assertSame(UpgradeState::COMPLETED, $mgr->store()->load($id)['status']);

        // Version bumped on disk; site state preserved; stale removed.
        $this->assertStringContainsString('1.0.0-beta.7.2.0', file_get_contents($root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php'));
        $this->assertStringContainsString('keepme', file_get_contents($root.'/.env'));
        $this->assertFileExists($root.'/storage/app/media/photo.txt');
        $this->assertFileExists($root.'/plugins/acme/plugin.json');
        $this->assertFileExists($root.'/themes/company/theme.json');
        $this->assertFileDoesNotExist($root.'/app/Obsolete.php');

        // Idempotency: a second apply is refused.
        $again = $mgr->apply($id);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsStringIgnoringCase('already', $again['reason']);
    }

    #[Test]
    public function backup_hard_gate_blocks_apply_when_backup_is_invalid(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15');
        $pkgZip = $this->tmp.'/pkg.zip';
        $this->buildPackage($pkgZip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');

        $mgr = $this->manager($root);
        $id = $mgr->receivePackage($pkgZip, fn (string $dest) => copy($pkgZip, $dest))['id'];
        $mgr->verifyPackage($id);
        $mgr->store()->transition($id, UpgradeState::PREFLIGHT_PASSED, 'preflight ok (test)');

        // A backup whose file archive is corrupt must fail the hard gate.
        $badRefs = [
            'files_zip' => $this->tmp.'/nope.zip',
            'files_sha256' => 'deadbeef',
            'files_manifest' => $this->tmp.'/nope.json',
            'files_count' => 5,
            'env_file' => null, 'env_sha256' => null,
        ];
        $this->assertFalse($mgr->verifyBackup($badRefs)['ok']);

        // With no verified backup, apply is refused outright.
        $apply = $mgr->apply($id);
        $this->assertFalse($apply['ok']);
        $this->assertStringContainsStringIgnoringCase('verified backup', $apply['reason']);
    }

    #[Test]
    public function post_mutation_failure_triggers_automatic_file_rollback(): void
    {
        $root = $this->tmp.'/site';
        $this->buildLiveTree($root, '1.0.0-beta.7.1.15', withObsolete: true, withRecordedOwnership: true);
        $pkgZip = $this->tmp.'/pkg.zip';
        $this->buildPackage($pkgZip, '1.0.0-beta.7.2.0', '1.0.0-beta.7.0.0');

        // A manager whose migration step throws AFTER files are replaced.
        $mgr = new class($root) extends UpgradeManager {
            public function __construct(string $root)
            {
                parent::__construct($root, null, null, static function () {
                    throw new RuntimeException('no db');
                });
            }

            protected function enterMaintenance(): void {}

            protected function exitMaintenance(): void {}

            protected function runMigrations(): void
            {
                throw new RuntimeException('migration blew up mid-upgrade');
            }

            protected function refreshCaches(array $model): void {}
        };

        $id = $mgr->receivePackage($pkgZip, fn (string $dest) => copy($pkgZip, $dest))['id'];
        $mgr->verifyPackage($id);
        $mgr->store()->transition($id, UpgradeState::PREFLIGHT_PASSED, 'preflight ok (test)');
        $mgr->runBackup($id);

        $res = $mgr->apply($id);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['recovered'], 'files must be restored automatically');

        $state = $mgr->store()->load($id);
        $this->assertSame(UpgradeState::FAILED, $state['status']);
        $this->assertSame(UpgradeState::MIGRATING, $state['failure_stage']);

        // Rolled back: obsolete file is back, version reverted, site state intact.
        $this->assertFileExists($root.'/app/Obsolete.php');
        $this->assertStringContainsString('1.0.0-beta.7.1.15', file_get_contents($root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php'));
        $this->assertStringContainsString('keepme', file_get_contents($root.'/.env'));
    }

    // ================================================================
    // Fixture builders
    // ================================================================

    private function manager(string $root): UpgradeManager
    {
        // A throwing PDO resolver keeps the manager DB-free: no dump, health skips DB.
        return new class($root) extends UpgradeManager {
            public function __construct(string $root)
            {
                parent::__construct($root, null, null, static function () {
                    throw new RuntimeException('no db in engine test');
                });
            }

            protected function enterMaintenance(): void {}

            protected function exitMaintenance(): void {}

            protected function runMigrations(): void {}

            protected function refreshCaches(array $model): void {}
        };
    }
}
