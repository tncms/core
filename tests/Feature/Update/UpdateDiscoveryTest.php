<?php

declare(strict_types=1);

namespace Tests\Feature\Update;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Update\UpdateAvailability;
use TheNguyen\CMS\Update\UpdateChannel;
use TheNguyen\CMS\Update\UpdateChecker;

/**
 * CORE-UPGRADE-2 — update discovery certification (§discovery).
 *
 * Proves version resolution against the installed CmsInfo::VERSION and that a
 * broken/foreign feed resolves to a definite "unavailable" state rather than an
 * exception. No download happens during discovery.
 */
final class UpdateDiscoveryTest extends TestCase
{
    use BuildsUpdateFixtures;

    private string $tmp;

    /** @var list<string> */
    public array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/').'/tncms-upd-'.bin2hex(random_bytes(5));
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
    private function fakeRelease(string $version, array $overrides = []): array
    {
        return array_replace_recursive([
            'version' => $version,
            'channel' => 'stable',
            'minimum_supported_version' => '1.0.0-beta.7.1.0',
            'php_requirement' => '8.3.0',
            'package' => [
                'package_type' => 'core-upgrade',
                'download_url' => self::DOWNLOAD_URL,
                'sha256' => str_repeat('a', 64),
                'size' => 1024,
            ],
        ], $overrides);
    }

    private function checkWith(string $feedBody): UpdateAvailability
    {
        $client = $this->fakeClient($feedBody, __FILE__); // download unused in discovery
        $checker = new UpdateChecker($client);

        return $checker->check(self::FEED_URL, new UpdateChannel(UpdateChannel::STABLE), CmsInfo::VERSION);
    }

    #[Test]
    public function latest_equal_to_current_reports_up_to_date(): void
    {
        $feed = $this->feedJson($this->fakeRelease(CmsInfo::VERSION));
        $a = $this->checkWith($feed);

        $this->assertSame(UpdateAvailability::UP_TO_DATE, $a->state);
        $this->assertFalse($a->updateAvailable);
        $this->assertSame(CmsInfo::VERSION, $a->latestVersion);
    }

    #[Test]
    public function newer_release_is_detected_as_available(): void
    {
        $feed = $this->feedJson($this->fakeRelease($this->bump(CmsInfo::VERSION)));
        $a = $this->checkWith($feed);

        $this->assertSame(UpdateAvailability::UPDATE_AVAILABLE, $a->state);
        $this->assertTrue($a->updateAvailable);
        $this->assertTrue(version_compare($a->latestVersion, CmsInfo::VERSION, '>'));
    }

    #[Test]
    public function invalid_metadata_is_rejected_as_unavailable(): void
    {
        $a = $this->checkWith('{"not":"a valid feed"}');

        $this->assertSame(UpdateAvailability::UNAVAILABLE, $a->state);
        $this->assertFalse($a->updateAvailable);
    }

    #[Test]
    public function foreign_product_feed_is_rejected(): void
    {
        $feed = (string) json_encode([
            'schema' => \TheNguyen\CMS\Update\UpdateManifest::SCHEMA,
            'product' => 'Some Other CMS',
            'channels' => ['stable' => $this->fakeRelease($this->bump(CmsInfo::VERSION))],
        ]);

        $this->assertSame(UpdateAvailability::UNAVAILABLE, $this->checkWith($feed)->state);
    }

    #[Test]
    public function unconfigured_feed_is_unavailable(): void
    {
        $client = $this->fakeClient('{}', __FILE__);
        $checker = new UpdateChecker($client);
        $a = $checker->check('', new UpdateChannel(UpdateChannel::STABLE), CmsInfo::VERSION);

        $this->assertSame(UpdateAvailability::UNAVAILABLE, $a->state);
    }

    /** Produce a version strictly greater than $v by bumping its last numeric run. */
    private function bump(string $v): string
    {
        return preg_replace_callback('/(\d+)(?!.*\d)/', static fn ($m) => (string) ($m[1] + 1), $v) ?? $v.'.1';
    }
}
