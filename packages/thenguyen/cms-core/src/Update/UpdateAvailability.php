<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Result of an update-discovery check (CORE-UPGRADE-2, §discovery).
 *
 * Immutable snapshot describing whether a newer release exists on the site's
 * channel. `verificationState` is intentionally coarse at discovery time —
 * metadata is only parsed/compatibility-screened here; the package itself is
 * proven later by {@see UpdateVerifier}. No download has happened yet.
 */
final class UpdateAvailability
{
    /** Discovery states. */
    public const UP_TO_DATE = 'up_to_date';

    public const UPDATE_AVAILABLE = 'update_available';

    public const INCOMPATIBLE = 'incompatible';

    public const UNAVAILABLE = 'unavailable'; // feed unreachable / unconfigured / malformed

    private function __construct(
        public readonly string $currentVersion,
        public readonly string $channel,
        public readonly ?string $latestVersion,
        public readonly bool $updateAvailable,
        public readonly string $state,
        public readonly ?string $detail,
        public readonly ?UpdateManifest $manifest,
    ) {}

    public static function upToDate(string $current, string $channel, UpdateManifest $m): self
    {
        return new self($current, $channel, $m->version, false, self::UP_TO_DATE, null, $m);
    }

    public static function available(string $current, string $channel, UpdateManifest $m): self
    {
        return new self($current, $channel, $m->version, true, self::UPDATE_AVAILABLE, null, $m);
    }

    public static function incompatible(string $current, string $channel, UpdateManifest $m, string $detail): self
    {
        return new self($current, $channel, $m->version, false, self::INCOMPATIBLE, $detail, $m);
    }

    public static function unavailable(string $current, string $channel, string $detail): self
    {
        return new self($current, $channel, null, false, self::UNAVAILABLE, $detail, null);
    }

    /** @return array<string,mixed> a UI/JSON-safe view (never a secret). */
    public function toArray(): array
    {
        return [
            'current_version' => $this->currentVersion,
            'channel' => $this->channel,
            'latest_version' => $this->latestVersion,
            'update_available' => $this->updateAvailable,
            'state' => $this->state,
            'detail' => $this->detail,
        ];
    }
}
