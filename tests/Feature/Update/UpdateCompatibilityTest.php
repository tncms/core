<?php

declare(strict_types=1);

namespace Tests\Feature\Update;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Update\UpdateChannel;
use TheNguyen\CMS\Update\UpdateCompatibilityChecker;
use TheNguyen\CMS\Update\UpdateManifest;

/**
 * CORE-UPGRADE-2 — compatibility certification (§compatibility).
 *
 * Screens a discovered release against the running environment before download:
 * downgrade, unsupported source version and unmet PHP requirement are refused; a
 * compatible release is accepted.
 */
final class UpdateCompatibilityTest extends TestCase
{
    use BuildsUpdateFixtures;

    private string $tmp;

    /** @var list<string> */
    public array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/tncms-updc-'.bin2hex(random_bytes(5));
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

    /** @param array<string,mixed> $overrides */
    private function manifest(string $version, array $overrides = []): UpdateManifest
    {
        return UpdateManifest::fromRelease(array_replace_recursive([
            'version' => $version,
            'channel' => 'stable',
            'minimum_supported_version' => '1.0.0-beta.7.1.0',
            'php_requirement' => '8.3.0',
            'package' => [
                'package_type' => 'core-upgrade',
                'download_url' => self::DOWNLOAD_URL,
                'sha256' => str_repeat('c', 64),
                'size' => 2048,
            ],
        ], $overrides), new UpdateChannel(UpdateChannel::STABLE));
    }

    #[Test]
    public function a_compatible_release_is_accepted(): void
    {
        $m = $this->manifest('1.0.0-beta.99.0.0');
        $result = (new UpdateCompatibilityChecker)->check($m, CmsInfo::VERSION);

        $this->assertTrue($result['ok'], $result['detail']);
    }

    #[Test]
    public function a_downgrade_is_rejected(): void
    {
        $m = $this->manifest('1.0.0-beta.1.0.0');
        $result = (new UpdateCompatibilityChecker)->check($m, CmsInfo::VERSION);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_newer', $result['reason']);
    }

    #[Test]
    public function an_unsupported_source_version_is_rejected(): void
    {
        // Target is newer, but it refuses to upgrade from the installed version.
        $m = $this->manifest('1.0.0-beta.99.0.0', ['minimum_supported_version' => '1.0.0-beta.98.0.0']);
        $result = (new UpdateCompatibilityChecker)->check($m, CmsInfo::VERSION);

        $this->assertFalse($result['ok']);
        $this->assertSame('source_unsupported', $result['reason']);
    }

    #[Test]
    public function an_unmet_php_requirement_is_rejected(): void
    {
        $m = $this->manifest('1.0.0-beta.99.0.0', ['php_requirement' => '99.0.0']);
        $result = (new UpdateCompatibilityChecker)->check($m, CmsInfo::VERSION);

        $this->assertFalse($result['ok']);
        $this->assertSame('php_unsupported', $result['reason']);
    }

    #[Test]
    public function php_requirement_is_evaluated_against_the_running_runtime(): void
    {
        // With an injected low runtime the same release still passes the PHP gate.
        $m = $this->manifest('1.0.0-beta.99.0.0', ['php_requirement' => '8.3.0']);
        $checker = new UpdateCompatibilityChecker('8.3.0');

        $this->assertTrue($checker->check($m, CmsInfo::VERSION)['ok']);
    }
}
