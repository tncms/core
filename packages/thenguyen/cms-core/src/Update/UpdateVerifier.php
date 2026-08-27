<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

use TheNguyen\CMS\Upgrade\UpgradePackage;

/**
 * Downloaded-package verification (CORE-UPGRADE-2, §security).
 *
 * The gate between "downloaded" and "trusted". It runs the ordered validation
 * pipeline on the file that landed in private staging and, only when every step
 * passes, mints the single {@see VerifiedUpdatePackage} that may be handed to
 * CORE-UPGRADE-1:
 *
 *   1. file present & readable
 *   2. size equals the metadata size
 *   3. SHA-256 equals the metadata checksum (download-integrity)
 *   4. archive verification via {@see UpgradePackage::verify()} — the reused
 *      apply-authority for product / package-type / target-newer /
 *      core-manifest-checksum / ownership (§reuse)
 *   5. the archive's target_version agrees with the metadata version
 *
 * Any failure throws {@see UpdateException} with the first failing reason; there
 * is no partial or "mostly verified" result.
 */
final class UpdateVerifier
{
    /** @var callable():string ISO-8601 UTC clock, injectable for tests. */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
    }

    public function verify(string $packagePath, UpdateManifest $m, string $currentVersion): VerifiedUpdatePackage
    {
        if (! is_file($packagePath) || ! is_readable($packagePath)) {
            throw UpdateException::of('package_unreadable', 'The downloaded package could not be read.');
        }

        $size = (int) filesize($packagePath);
        if ($size !== $m->size) {
            throw UpdateException::of('size_mismatch', "The downloaded package size ({$size}) does not match the expected {$m->size}.");
        }

        $sha = (string) hash_file('sha256', $packagePath);
        if (! hash_equals($m->sha256, $sha)) {
            throw UpdateException::of('checksum_mismatch', 'The downloaded package failed its SHA-256 checksum.');
        }

        // Reuse the CORE-UPGRADE-1 archive-safety authority. It re-proves
        // product/type/target/core-manifest-checksum/ownership and the downgrade
        // guard against the ARCHIVE (independent of the feed metadata).
        $pkg = new UpgradePackage($packagePath);
        $archive = $pkg->verify($currentVersion);
        if (! $archive['ok']) {
            throw UpdateException::of('archive_invalid', $archive['reason']);
        }

        // Metadata ↔ archive agreement: a feed cannot advertise a version the
        // package does not actually deliver.
        $archiveTarget = (string) ($archive['target_version'] ?? '');
        if ($archiveTarget !== $m->version) {
            throw UpdateException::of('version_disagreement', "The package targets {$archiveTarget} but the metadata advertised {$m->version}.");
        }

        return new VerifiedUpdatePackage(
            packagePath: $packagePath,
            sha256: $sha,
            manifest: $pkg->upgradeManifest() ?? [],
            sourceVersion: $currentVersion,
            targetVersion: $archiveTarget,
            verifiedAt: ($this->clock)(),
        );
    }
}
