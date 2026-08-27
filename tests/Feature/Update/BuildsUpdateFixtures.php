<?php

declare(strict_types=1);

namespace Tests\Feature\Update;

use Tests\Feature\Upgrade\BuildsUpgradeFixtures;
use TheNguyen\CMS\Update\UpdateClient;
use TheNguyen\CMS\Update\UpdateManifest;

/**
 * Shared fixtures for the CORE-UPGRADE-2 remote update suite.
 *
 * Builds a REAL Core-upgrade .zip via the CORE-UPGRADE-1 fixture builder (so the
 * archive genuinely passes UpgradePackage verification), then wraps it in a
 * versioned tncms.update/v1 feed document plus an in-memory {@see UpdateClient}
 * whose transports serve the feed body and copy the local .zip. No network.
 */
trait BuildsUpdateFixtures
{
    use BuildsUpgradeFixtures;

    protected const FEED_URL = 'https://updates.example.test/core/stable.json';

    protected const DOWNLOAD_URL = 'https://downloads.example.test/core/upgrade.zip';

    /** Build a real upgrade package and return its path. */
    protected function makePackageZip(
        string $version,
        string $minSource = '1.0.0-beta.7.1.0',
        bool $tamperCore = false,
    ): string {
        $dest = $this->tmp.'/pkg-'.bin2hex(random_bytes(4)).'.zip';
        $this->buildPackage($dest, $version, $minSource, 'TNCMS', $tamperCore);

        return $dest;
    }

    /**
     * A single release descriptor whose checksum/size match $zipPath.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function releaseFor(string $zipPath, string $version, array $overrides = []): array
    {
        $release = [
            'version' => $version,
            'channel' => 'stable',
            'minimum_supported_version' => '1.0.0-beta.7.1.0',
            'php_requirement' => '8.3.0',
            'release_notes' => "Release {$version}",
            'release_url' => "https://github.com/tncms/core/releases/tag/v{$version}",
            'published_at' => '2026-08-25T00:00:00Z',
            'package' => [
                'package_type' => 'core-upgrade',
                'download_url' => self::DOWNLOAD_URL,
                'sha256' => hash_file('sha256', $zipPath),
                'size' => (int) filesize($zipPath),
            ],
        ];

        return array_replace_recursive($release, $overrides);
    }

    /**
     * A full channel-map feed document.
     *
     * @param  array<string,mixed>  $release
     */
    protected function feedJson(array $release, string $channel = 'stable'): string
    {
        return (string) json_encode([
            'schema' => UpdateManifest::SCHEMA,
            'product' => UpdateManifest::PRODUCT,
            'channels' => [$channel => $release],
        ]);
    }

    /**
     * An UpdateClient whose GET returns $feedBody and whose download copies
     * $zipPath (honouring the byte ceiling). $downloadOversize forces the
     * ceiling-abort branch.
     */
    protected function fakeClient(string $feedBody, string $zipPath, bool $downloadOversize = false): UpdateClient
    {
        $get = static fn (string $url, array $headers): array => [
            'status' => 200, 'body' => $feedBody, 'error' => '',
        ];
        $download = static function (string $url, string $dest, array $headers, int $maxBytes) use ($zipPath, $downloadOversize): array {
            $size = (int) filesize($zipPath);
            if ($downloadOversize || $size > $maxBytes) {
                @unlink($dest);

                return ['status' => 200, 'bytes' => 0, 'error' => 'download exceeded the size ceiling'];
            }
            @mkdir(\dirname($dest), 0775, true);
            copy($zipPath, $dest);

            return ['status' => 200, 'bytes' => (int) filesize($dest), 'error' => ''];
        };

        return new UpdateClient($get, $download);
    }

    /** A client whose transports simulate a hard TLS/transport failure. */
    protected function failingClient(string $error = 'SSL certificate problem'): UpdateClient
    {
        $get = static fn (string $url, array $headers): array => ['status' => 0, 'body' => '', 'error' => $error];
        $download = static fn (string $url, string $dest, array $headers, int $maxBytes): array => ['status' => 0, 'bytes' => 0, 'error' => $error];

        return new UpdateClient($get, $download);
    }
}
