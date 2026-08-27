<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Versioned remote update-metadata contract (CORE-UPGRADE-2, §metadata).
 *
 * The immutable, validated description of ONE published release for ONE channel,
 * parsed from the remote feed document a running site fetches over HTTPS. Remote
 * data is never trusted: {@see fromArray()} fail-closes on any missing/malformed
 * field, on a non-HTTPS download URL, or on a foreign product. It performs NO
 * network I/O and holds NO secret.
 *
 * Contract schema: "tncms.update/v1".
 *
 * A feed document is either a single release object (with a matching `channel`)
 * or a channel map:
 *   { "schema":"tncms.update/v1", "product":"TNCMS Core",
 *     "channels": { "stable": {release}, "beta": {release}, "development": {release} } }
 *
 * A `{release}` object:
 *   { "version":"1.0.0-beta.7.1.22",
 *     "channel":"stable",
 *     "minimum_supported_version":"1.0.0-beta.7.1.0",
 *     "php_requirement":"8.3.0",
 *     "release_notes":"…", "release_url":"https://…", "published_at":"…",
 *     "package": { "package_type":"core-upgrade",
 *                  "download_url":"https://…/…-upgrade.zip",
 *                  "sha256":"<64 hex>", "size": 14680064 } }
 */
final class UpdateManifest
{
    public const SCHEMA = 'tncms.update/v1';

    public const PRODUCT = 'TNCMS Core';

    public const PACKAGE_TYPE = 'core-upgrade';

    /**
     * @param  array<string,mixed>  $raw  the original release object (kept verbatim
     *                                    for the verified-handoff record)
     */
    private function __construct(
        public readonly string $version,
        public readonly string $channel,
        public readonly ?string $minimumSupportedVersion,
        public readonly ?string $phpRequirement,
        public readonly string $downloadUrl,
        public readonly string $sha256,
        public readonly int $size,
        public readonly string $packageType,
        public readonly ?string $releaseNotes,
        public readonly ?string $releaseUrl,
        public readonly array $raw,
    ) {}

    /** Parse a raw JSON feed body and select the requested channel. */
    public static function fromJson(string $json, UpdateChannel $channel): self
    {
        $data = json_decode($json, true);
        if (! \is_array($data)) {
            throw UpdateException::of('metadata_unparsable', 'The update metadata is not valid JSON.');
        }

        return self::fromFeed($data, $channel);
    }

    /**
     * Select the release for $channel from a decoded feed document (either a
     * channel map or a flat single-release document).
     *
     * @param  array<string,mixed>  $feed
     */
    public static function fromFeed(array $feed, UpdateChannel $channel): self
    {
        self::assert(($feed['schema'] ?? null) === self::SCHEMA, 'metadata_schema', 'Unsupported update metadata schema.');
        self::assert(($feed['product'] ?? null) === self::PRODUCT, 'metadata_product', 'The update metadata is for a different product.');

        if (\is_array($feed['channels'] ?? null)) {
            $release = $feed['channels'][$channel->name] ?? null;
            self::assert(\is_array($release), 'channel_absent', "No release is published on the {$channel->name} channel.");
        } else {
            // Flat single-release document: its own channel must match the request.
            self::assert(($feed['channel'] ?? null) === $channel->name, 'channel_mismatch', 'The update metadata does not match the requested channel.');
            $release = $feed;
        }

        /** @var array<string,mixed> $release */
        return self::fromRelease($release, $channel);
    }

    /**
     * @param  array<string,mixed>  $r  a single release object
     */
    public static function fromRelease(array $r, UpdateChannel $channel): self
    {
        $version = self::str($r['version'] ?? null);
        self::assert($version !== '', 'version_missing', 'The update metadata has no version.');

        // The release channel, when present, must not contradict the request.
        $declared = self::str($r['channel'] ?? $channel->name);
        self::assert($declared === $channel->name, 'channel_mismatch', 'The update metadata does not match the requested channel.');

        $pkg = \is_array($r['package'] ?? null) ? $r['package'] : [];
        $downloadUrl = self::str($pkg['download_url'] ?? null);
        self::assert($downloadUrl !== '', 'download_url_missing', 'The update metadata has no download URL.');
        self::assert(self::isHttps($downloadUrl), 'download_url_insecure', 'The update download URL is not HTTPS.');

        $sha256 = strtolower(self::str($pkg['sha256'] ?? null));
        self::assert(preg_match('/^[0-9a-f]{64}$/', $sha256) === 1, 'sha256_malformed', 'The update metadata has no valid SHA-256 checksum.');

        $size = (int) ($pkg['size'] ?? 0);
        self::assert($size > 0, 'size_missing', 'The update metadata has no package size.');

        $packageType = self::str($pkg['package_type'] ?? self::PACKAGE_TYPE);
        self::assert($packageType === self::PACKAGE_TYPE, 'package_type_invalid', 'The update metadata is not a Core upgrade package.');

        $min = self::nullableStr($r['minimum_supported_version'] ?? null);
        $php = self::nullableStr($r['php_requirement'] ?? null);

        return new self(
            version: $version,
            channel: $channel->name,
            minimumSupportedVersion: $min,
            phpRequirement: $php,
            downloadUrl: $downloadUrl,
            sha256: $sha256,
            size: $size,
            packageType: $packageType,
            releaseNotes: self::nullableStr($r['release_notes'] ?? null),
            releaseUrl: self::nullableStr($r['release_url'] ?? null),
            raw: $r,
        );
    }

    // ---- helpers --------------------------------------------------------

    /** @throws UpdateException */
    private static function assert(bool $ok, string $reason, string $message): void
    {
        if (! $ok) {
            throw UpdateException::of($reason, $message);
        }
    }

    private static function isHttps(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' && $host !== '';
    }

    private static function str(mixed $v): string
    {
        return \is_string($v) ? trim($v) : '';
    }

    private static function nullableStr(mixed $v): ?string
    {
        $s = self::str($v);

        return $s === '' ? null : $s;
    }
}
