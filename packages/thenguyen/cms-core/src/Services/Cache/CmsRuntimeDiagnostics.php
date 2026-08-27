<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Cache;

use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use Throwable;

/**
 * Read-only frontend/runtime optimization diagnostics aggregator (CORE-OPTIMIZE-3
 * §14). The smallest authority that composes the EXISTING optimization
 * authorities — {@see CmsOptimizationPolicy} (response policy) and
 * {@see CmsCacheDiagnostics} (cache health) — plus a bounded static-asset
 * capability probe, into one stable, machine-readable, secret-free snapshot for
 * the admin Optimize surface and the public health endpoint.
 *
 * Like {@see CmsCacheDiagnostics} it:
 *   - never becomes a policy authority (it only reads);
 *   - never mutates anything just because diagnostics are requested;
 *   - performs no unbounded scan, DB table scan or network call (§16) — the only
 *     filesystem touch is at most two `is_file()` probes for the Vite manifest;
 *   - exposes only safe values: booleans, a bounded TTL, status vocabulary and
 *     machine warning keys — never a path, DSN, credential, token or APP_KEY (§14).
 *
 * Status vocabulary matches the rest of Optimize: `healthy`, `disabled`,
 * `degraded`, `unavailable`, `bypassed`. Operator-disabled is `disabled`, not an
 * error.
 */
final class CmsRuntimeDiagnostics
{
    /** Relative public-path candidates for the Vite build manifest. */
    private const VITE_MANIFEST_PATHS = ['build/manifest.json', 'build/.vite/manifest.json'];

    public function __construct(
        private readonly CmsOptimizationPolicy $policy,
        private readonly CmsCacheDiagnostics $cacheDiagnostics,
    ) {}

    /**
     * Full admin-facing runtime snapshot. May include the safe cache driver name
     * (via the reused cache diagnostics) — this is consumed behind the
     * authenticated Optimize surface only, never the public endpoint.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'response' => $this->responseState(),
            'static_assets' => $this->staticAssetState(),
            'media' => $this->mediaState(),
            'cache' => $this->safe(fn (): array => $this->cacheDiagnostics->snapshot(), []),
            'environment' => $this->environmentState(),
        ];
    }

    /**
     * Path-, credential-, token- and driver-free subset for the PUBLIC
     * `/cms-health` endpoint (§14). Deliberately carries only booleans, a bounded
     * TTL and status strings — nothing that could leak server internals.
     *
     * @return array<string,mixed>
     */
    public function publicSnapshot(): array
    {
        $response = $this->responseState();
        $assets = $this->staticAssetState();

        return [
            'response_optimization' => $response['enabled'],
            'response_public_html_ttl' => $response['public_html_ttl'],
            'response_status' => $response['status'],
            'static_assets_fingerprinted' => $assets['fingerprinted'],
            'static_assets_status' => $assets['status'],
        ];
    }

    /**
     * @return array{enabled: bool, public_html_ttl: int, status: string}
     */
    private function responseState(): array
    {
        $enabled = $this->safe(fn (): bool => $this->policy->responseOptimizationEnabled(), false);

        return [
            'enabled' => $enabled,
            'public_html_ttl' => $this->safe(fn (): int => $this->policy->publicHtmlTtl(), 0),
            'status' => $enabled ? 'healthy' : 'disabled',
        ];
    }

    /**
     * Whether Core-owned assets are Vite-fingerprinted (immutable-cacheable). The
     * long-cache HEADER for those assets is owned by the web server / CDN (§8), so
     * Core only reports the capability, it does not fake Laravel header ownership.
     *
     * @return array{fingerprinted: bool, status: string, header_owner: string}
     */
    private function staticAssetState(): array
    {
        $fingerprinted = $this->safe(fn (): bool => $this->viteManifestPresent(), false);

        return [
            'fingerprinted' => $fingerprinted,
            'status' => $fingerprinted ? 'healthy' : 'unavailable',
            'header_owner' => 'infrastructure',
        ];
    }

    /**
     * Bounded probe (§16): at most two single-file existence checks, never a
     * directory scan.
     */
    private function viteManifestPresent(): bool
    {
        foreach (self::VITE_MANIFEST_PATHS as $relative) {
            if (is_file(public_path($relative))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Media response optimization (loading/decoding/fetchpriority) is theme
     * responsibility in this phase (§9/§10) — Core owns no central content-image
     * renderer and cannot semantically identify hero/LCP media. Reported as a
     * capability seam, not a Core-managed optimization.
     *
     * @return array{optimization_owner: string, status: string}
     */
    private function mediaState(): array
    {
        return [
            'optimization_owner' => 'theme',
            'status' => 'unavailable',
        ];
    }

    /**
     * Safe, bounded environment capability warnings as machine keys (the UI maps
     * them to localized strings). Secret-free; never a path or connection detail.
     *
     * @return array{warnings: list<string>}
     */
    private function environmentState(): array
    {
        $warnings = [];

        $driver = $this->safe(fn (): string => (string) config('cache.default'), '');

        // A non-persistent store makes CMS caching ineffective across requests.
        if ($driver === 'array') {
            $warnings[] = 'cache_store_non_persistent';
        }

        return ['warnings' => $warnings];
    }

    /**
     * @template T
     *
     * @param  callable():T  $fn
     * @param  T  $default
     * @return T
     */
    private function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            report($e);

            return $default;
        }
    }
}
