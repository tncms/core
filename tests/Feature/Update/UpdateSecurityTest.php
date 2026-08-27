<?php

declare(strict_types=1);

namespace Tests\Feature\Update;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Update\UpdateChannel;
use TheNguyen\CMS\Update\UpdateClient;
use TheNguyen\CMS\Update\UpdateException;
use TheNguyen\CMS\Update\UpdateManifest;
use TheNguyen\CMS\Update\UpdateVerifier;

/**
 * CORE-UPGRADE-2 — security certification (§security).
 *
 * Proves the download/verification pipeline fails closed on every hostile input:
 * TLS/transport failure, non-HTTPS URLs, checksum mismatch, size mismatch,
 * a corrupted archive, a lying (tampered) manifest, and an over-ceiling download.
 */
final class UpdateSecurityTest extends TestCase
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

    private string $target = '1.0.0-beta.99.0.0';

    /** @param array<string,mixed> $overrides */
    private function manifest(string $zip, array $overrides = []): UpdateManifest
    {
        $feed = $this->feedJson($this->releaseFor($zip, $this->target, $overrides));

        return UpdateManifest::fromJson($feed, new UpdateChannel(UpdateChannel::STABLE));
    }

    // ---- transport / TLS -------------------------------------------------

    #[Test]
    public function non_https_urls_are_rejected_before_any_io(): void
    {
        $client = new UpdateClient; // default transports; must never be reached

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('HTTPS');
        $client->fetch('http://updates.example.test/feed.json');
    }

    #[Test]
    public function transport_failure_is_reported_not_swallowed(): void
    {
        $client = $this->failingClient('SSL certificate problem: self signed certificate');

        try {
            $client->fetch(self::FEED_URL);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('fetch_transport', $e->reason);
        }
    }

    #[Test]
    public function metadata_with_a_non_https_download_url_is_rejected(): void
    {
        $zip = $this->makePackageZip($this->target);

        try {
            $this->manifest($zip, ['package' => ['download_url' => 'http://downloads.example.test/x.zip']]);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('download_url_insecure', $e->reason);
        }
    }

    // ---- integrity -------------------------------------------------------

    #[Test]
    public function checksum_mismatch_is_rejected(): void
    {
        $zip = $this->makePackageZip($this->target);
        $manifest = $this->manifest($zip, ['package' => ['sha256' => str_repeat('b', 64)]]);

        $this->expectException(UpdateException::class);
        try {
            (new UpdateVerifier)->verify($zip, $manifest, CmsInfo::VERSION);
        } catch (UpdateException $e) {
            $this->assertSame('checksum_mismatch', $e->reason);
            throw $e;
        }
    }

    #[Test]
    public function size_mismatch_is_rejected(): void
    {
        $zip = $this->makePackageZip($this->target);
        $manifest = $this->manifest($zip, ['package' => ['size' => (int) filesize($zip) + 1]]);

        try {
            (new UpdateVerifier)->verify($zip, $manifest, CmsInfo::VERSION);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('size_mismatch', $e->reason);
        }
    }

    #[Test]
    public function a_corrupted_archive_is_rejected(): void
    {
        // Checksum/size match the (tampered) file, so the failure is caught at the
        // reused UpgradePackage archive-verification layer, not before it.
        $zip = $this->makePackageZip($this->target, '1.0.0-beta.7.1.0', tamperCore: true);
        $manifest = $this->manifest($zip);

        try {
            (new UpdateVerifier)->verify($zip, $manifest, CmsInfo::VERSION);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('archive_invalid', $e->reason);
        }
    }

    #[Test]
    public function a_manifest_lying_about_the_version_is_rejected(): void
    {
        // The feed advertises a different version than the archive actually ships.
        $zip = $this->makePackageZip($this->target);
        $manifest = $this->manifest($zip, ['version' => '1.0.0-beta.99.9.9']);

        try {
            (new UpdateVerifier)->verify($zip, $manifest, CmsInfo::VERSION);
            $this->fail('expected UpdateException');
        } catch (UpdateException $e) {
            $this->assertSame('version_disagreement', $e->reason);
        }
    }

    #[Test]
    public function an_over_ceiling_download_is_aborted(): void
    {
        $zip = $this->makePackageZip($this->target);
        $client = $this->fakeClient($this->feedJson($this->releaseFor($zip, $this->target)), $zip, downloadOversize: true);

        $this->expectException(UpdateException::class);
        $client->download(self::DOWNLOAD_URL, $this->tmp.'/dl.zip', 1_000_000);
    }
}
