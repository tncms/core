<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Immutable result of a generic plugin public-asset provisioning operation.
 *
 * Like {@see InstallResult}, this never lets a raw exception reach a caller: every outcome — a
 * declaration-less no-op, a successful publish, an owned purge, or a controlled failure — is one of
 * these. It carries no filesystem-absolute host paths in its message beyond what the caller passes.
 */
final class AssetPublishResult
{
    public const SKIPPED = 'skipped';

    public const PUBLISHED = 'published';

    public const PURGED = 'purged';

    public const FAILED = 'failed';

    /** @param array<int, string> $warnings */
    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly int $fileCount = 0,
        public readonly array $warnings = [],
    ) {}

    public static function skipped(string $message = 'No public assets declared.'): self
    {
        return new self(self::SKIPPED, $message);
    }

    public static function published(int $fileCount, string $message = 'Public assets published.'): self
    {
        return new self(self::PUBLISHED, $message, $fileCount);
    }

    public static function purged(int $fileCount = 0, string $message = 'Public assets purged.'): self
    {
        return new self(self::PURGED, $message, $fileCount);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }
}
