<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * A fully verified, downloaded Core-upgrade package (CORE-UPGRADE-2, §handoff).
 *
 * The ONLY object CORE-UPGRADE-2 may hand to the CORE-UPGRADE-1 upgrade engine.
 * It can be constructed exclusively by {@see UpdateVerifier} after every gate
 * (HTTPS, size, SHA-256, archive manifest/product/type/target/checksum/ownership
 * and compatibility) has passed. Its presence is itself the proof of
 * verification — {@see UpdateHandoff} accepts nothing else.
 *
 * Immutable. `packagePath` points into PRIVATE staging (never public/).
 */
final class VerifiedUpdatePackage
{
    /**
     * @param  array<string,mixed>  $manifest  the archive's tncms-upgrade.json
     */
    public function __construct(
        public readonly string $packagePath,
        public readonly string $sha256,
        public readonly array $manifest,
        public readonly string $sourceVersion,
        public readonly string $targetVersion,
        public readonly string $verifiedAt,
    ) {}

    /** @return array<string,mixed> a redacted, log-safe summary (no local paths). */
    public function summary(): array
    {
        return [
            'sha256' => $this->sha256,
            'source_version' => $this->sourceVersion,
            'target_version' => $this->targetVersion,
            'verified_at' => $this->verifiedAt,
            'package_file' => basename($this->packagePath),
        ];
    }
}
