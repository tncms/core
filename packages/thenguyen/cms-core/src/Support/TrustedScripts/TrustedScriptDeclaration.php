<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedScripts;

/**
 * One validated, normalised `trusted_scripts` entry from an active plugin's
 * plugin.json (CORE-EXTENSION-TRUST S1). Immutable.
 *
 * The fingerprint is SHA-256 over a canonical JSON encoding of every
 * security-relevant field (owner, key, URL, position, attributes, deps, CSP
 * sources, purpose). Administrator approval is bound to it, so any material
 * change to the declaration invalidates an earlier approval.
 */
final class TrustedScriptDeclaration
{
    public const HANDLE_PREFIX = 'ext:';

    private const FINGERPRINT_VERSION = 1;

    public readonly string $fingerprint;

    /**
     * @param  array<string, bool|string>  $attributes  Sorted by name.
     * @param  list<string>  $deps  Ordinary asset handles, declared order.
     * @param  array<string, list<string>>  $csp  Directive => sorted unique origins; directives sorted.
     */
    public function __construct(
        public readonly string $owner,
        public readonly string $key,
        public readonly string $url,
        public readonly string $origin,
        public readonly string $position,
        public readonly array $attributes,
        public readonly array $deps,
        public readonly array $csp,
        public readonly string $purpose,
    ) {
        $this->fingerprint = hash('sha256', $this->canonicalJson());
    }

    /** The reserved AssetRegistry handle this declaration renders under. */
    public function handle(): string
    {
        return self::HANDLE_PREFIX.$this->owner.'/'.$this->key;
    }

    /** Canonical, deterministic encoding hashed into the fingerprint. */
    public function canonicalJson(): string
    {
        return (string) json_encode([
            'v' => self::FINGERPRINT_VERSION,
            'owner' => $this->owner,
            'key' => $this->key,
            'url' => $this->url,
            'position' => $this->position,
            'attributes' => $this->attributes,
            'deps' => $this->deps,
            'csp' => $this->csp,
            'purpose' => $this->purpose,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'key' => $this->key,
            'handle' => $this->handle(),
            'url' => $this->url,
            'origin' => $this->origin,
            'position' => $this->position,
            'attributes' => $this->attributes,
            'deps' => $this->deps,
            'csp' => $this->csp,
            'purpose' => $this->purpose,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
