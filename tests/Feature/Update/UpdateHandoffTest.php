<?php

declare(strict_types=1);

namespace Tests\Feature\Update;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Update\UpdateException;
use TheNguyen\CMS\Update\UpdateHandoff;
use TheNguyen\CMS\Update\UpdatePackageDownloader;
use TheNguyen\CMS\Update\UpdateVerifier;
use TheNguyen\CMS\Update\VerifiedUpdatePackage;
use TheNguyen\CMS\Upgrade\UpgradeManager;

/**
 * CORE-UPGRADE-2 — verified handoff certification (§handoff).
 *
 * Proves that a fully verified package (download → verify) is accepted into the
 * CORE-UPGRADE-1 engine through its real receive+verify seam, leaving an attempt
 * at the engine's VERIFIED state, and that the boundary refuses a missing package
 * or a second concurrent handoff. CORE-UPGRADE-2 stops at engine VERIFIED — it
 * never applies the upgrade.
 */
final class UpdateHandoffTest extends TestCase
{
    use BuildsUpdateFixtures;

    private string $tmp;

    /** @var list<string> */
    public array $cleanup = [];

    private string $target = '1.0.0-beta.99.0.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/tncms-updh-'.bin2hex(random_bytes(5));
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

    private function prepareVerified(string $suffix = 'a'): VerifiedUpdatePackage
    {
        $zip = $this->makePackageZip($this->target);
        $feed = $this->feedJson($this->releaseFor($zip, $this->target));
        $manifest = \TheNguyen\CMS\Update\UpdateManifest::fromJson($feed, new \TheNguyen\CMS\Update\UpdateChannel('stable'));

        $downloader = new UpdatePackageDownloader($this->fakeClient($feed, $zip), $this->tmp.'/dl-'.$suffix);
        $dl = $downloader->download($manifest);

        return (new UpdateVerifier)->verify($dl['path'], $manifest, CmsInfo::VERSION);
    }

    private function engine(): UpgradeManager
    {
        return new UpgradeManager($this->tmp.'/site-'.bin2hex(random_bytes(3)));
    }

    #[Test]
    public function a_verified_package_is_accepted_into_the_engine_at_verified_state(): void
    {
        $verified = $this->prepareVerified();
        $engine = $this->engine();

        $result = (new UpdateHandoff($engine))->toEngine($verified);

        $this->assertTrue($result['verify']['ok'], (string) ($result['verify']['reason'] ?? ''));
        $this->assertSame('verified', $engine->latest()['status'] ?? null);
        $this->assertSame($this->target, $engine->latest()['target_version'] ?? null);
        // CORE-UPGRADE-2 must not have applied anything: no live mutation stage.
        $this->assertNotContains($engine->latest()['status'], ['replacing', 'migrating', 'completed']);
    }

    #[Test]
    public function a_missing_verified_package_is_refused(): void
    {
        $phantom = new VerifiedUpdatePackage(
            packagePath: $this->tmp.'/does-not-exist.zip',
            sha256: str_repeat('d', 64),
            manifest: [],
            sourceVersion: CmsInfo::VERSION,
            targetVersion: $this->target,
            verifiedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );

        try {
            (new UpdateHandoff($this->engine()))->toEngine($phantom);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('handoff_missing_package', $e->reason);
        }
    }

    #[Test]
    public function a_second_concurrent_handoff_is_refused(): void
    {
        $engine = $this->engine();
        (new UpdateHandoff($engine))->toEngine($this->prepareVerified('one'));

        try {
            (new UpdateHandoff($engine))->toEngine($this->prepareVerified('two'));
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('handoff_upgrade_active', $e->reason);
        }
    }
}
