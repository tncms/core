<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public-content resolution cache (v1.0.0-beta.6.3).
 *
 * The public frontend resolves every request against cms_slugs before loading
 * and rendering a page/post/term. This service caches the resolved REFERENCE
 * metadata (not HTML), so repeated hits to the same URL skip the slug lookup.
 *
 * Invalidation is versioned, not tag-based: every public cache key embeds a
 * monotonically increasing version read from {@see VERSION_KEY}. Bumping that
 * version (via {@see flush()}) orphans every previously written key in one
 * cheap write, which works on cache drivers that do not support tags (file,
 * database, array). Stale keys age out naturally on their TTL.
 *
 * Disabled entirely when CMS_PUBLIC_CACHE_TTL=0 (see config/cms.php cache.public_ttl).
 */
class PublicContentCacheManager
{
    private const VERSION_KEY = 'tncms.public.version';

    private const KEY_PREFIX = 'tncms.public';

    /**
     * Stable machine ID of the single Core-managed cache domain this manager
     * owns (CORE-OPTIMIZE-2 §11). Used by diagnostics, operations, logs and any
     * future API/MCP surface. Never a translated label or a PHP class name.
     */
    public const DOMAIN_ID = 'public_content';

    /**
     * Last public-cache outcome for the request: 'HIT', 'MISS', or 'BYPASS'
     * (cache disabled). Read by the query profiler to emit X-TNCMS-Cache; null
     * until the first cached resolution this request.
     */
    private ?string $lastCacheState = null;

    /**
     * Whether the public cache is on. Requires BOTH a positive TTL (config) and
     * the canonical CMS Cache policy to be enabled (CORE-OPTIMIZE-1). When the
     * policy is OFF, resolution transparently falls through to source of truth
     * (BYPASS) — no cached value is read and none is written — while the
     * configured Laravel cache store is left completely untouched.
     */
    public function enabled(): bool
    {
        return $this->policyEnabled() && $this->ttl() > 0;
    }

    /**
     * Consult the canonical CMS Cache policy, resolved lazily to avoid a
     * construction-time dependency cycle. Fails open (enabled) on any resolution
     * error so a policy glitch never disables an otherwise-healthy cache.
     */
    private function policyEnabled(): bool
    {
        try {
            return app('cms.cache_policy')->enabled();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * The most recent cache outcome this request, for diagnostics only.
     */
    public function lastCacheState(): ?string
    {
        return $this->lastCacheState;
    }

    /**
     * Configured public cache TTL in seconds.
     */
    public function ttl(): int
    {
        return (int) config('cms.cache.public_ttl', 3600);
    }

    /**
     * The current cache version. Every public key embeds this, so bumping it
     * invalidates the whole namespace at once. Self-heals to 1 when missing or
     * corrupt; never throws.
     */
    public function version(): int
    {
        try {
            $value = Cache::get(self::VERSION_KEY);

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                Cache::forever(self::VERSION_KEY, 1);

                return 1;
            }

            return (int) $value;
        } catch (\Throwable $e) {
            report($e);

            return 1;
        }
    }

    /**
     * Invalidate every public cache entry by advancing the version. Called from
     * the content/term/slug/menu/settings write paths (model events).
     */
    public function flush(): void
    {
        try {
            Cache::forever(self::VERSION_KEY, $this->version() + 1);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Cache key for a resolved public path in a locale.
     */
    public function resolveKey(string $locale, string $fullPath): string
    {
        return sprintf('%s.resolve.%d.%s.%s', self::KEY_PREFIX, $this->version(), $locale, sha1($fullPath));
    }

    /**
     * Cache key for a term archive page (reserved for future archive caching;
     * the archive query is currently paginated but not object-cached).
     */
    public function termArchiveKey(string $locale, int $termId, int $page): string
    {
        return sprintf('%s.term_archive.%d.%s.%d.%d', self::KEY_PREFIX, $this->version(), $locale, $termId, $page);
    }

    /**
     * Resolve (and cache) the cms_slugs reference for a public path. The
     * resolver returns the reference metadata array or null when nothing
     * matches; a not-found result is cached too (negative caching) so repeated
     * misses do not re-query — a version bump on any write invalidates it.
     *
     * Falls back to running the resolver directly when the cache is disabled or
     * the store errors, so resolution never depends on a healthy cache.
     *
     * @param  Closure(): (array{reference_type: string, reference_id: int}|null)  $resolver
     * @return array{reference_type: string, reference_id: int}|null
     */
    public function rememberResolution(string $locale, string $fullPath, Closure $resolver): ?array
    {
        if (! $this->enabled()) {
            $this->lastCacheState = 'BYPASS';

            return $resolver();
        }

        try {
            // Default to HIT; the closure only runs on a cache miss and flips it.
            $this->lastCacheState = 'HIT';

            $cached = Cache::remember(
                $this->resolveKey($locale, $fullPath),
                $this->ttl(),
                function () use ($resolver): array {
                    $this->lastCacheState = 'MISS';
                    $ref = $resolver();

                    return $ref === null ? ['found' => false] : ['found' => true, ...$ref];
                },
            );
        } catch (\Throwable $e) {
            report($e);

            return $resolver();
        }

        if (($cached['found'] ?? false) !== true) {
            return null;
        }

        return [
            'reference_type' => (string) $cached['reference_type'],
            'reference_id' => (int) $cached['reference_id'],
        ];
    }

    /**
     * Whether the current request may read/write the public cache. Excludes
     * non-GET, authenticated/admin, and preview requests so logged-in editors
     * and preview links always see live, uncached output.
     */
    public function shouldCache(Request $request): bool
    {
        return $this->enabled() && $this->isCacheableRequest($request);
    }

    /**
     * Whether the request BELONGS to the cacheable-anonymous class — a guest GET
     * that is not a preview/draft — independent of whether the server-side cache
     * is currently enabled. This is the request-classification seam reused by
     * response-header optimization (CORE-OPTIMIZE-3): browser cache headers depend
     * only on WHO is asking, not on the server cache toggle. {@see shouldCache()}
     * layers the server-cache gate on top of this.
     */
    public function isCacheableRequest(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->hasAny(['preview', 'draft'])) {
            return false;
        }

        try {
            if ($request->user() !== null) {
                return false;
            }
        } catch (\Throwable) {
            // No auth guard resolvable on this request — treat it as a guest.
        }

        return true;
    }

    /**
     * Normalised request path used as the public cache discriminator.
     */
    public function normalizePath(Request $request): string
    {
        return ltrim($request->path(), '/');
    }

    /**
     * The effective runtime state — the single source of truth callers should
     * consult. True only when BOTH the operator policy is ON and a positive TTL
     * is configured. Identical to {@see enabled()}; named for diagnostic
     * readability (CORE-OPTIMIZE-2 §8/§50).
     */
    public function effectiveEnabled(): bool
    {
        return $this->enabled();
    }

    /**
     * How public resolutions are currently served: 'cache' when the effective
     * cache is on, otherwise 'source' (every request resolves straight from the
     * authoritative store). Never throws.
     */
    public function readMode(): string
    {
        return $this->enabled() ? 'cache' : 'source';
    }

    /**
     * Whether resolutions are written back to the cache this request: 'cache'
     * when effective, otherwise 'bypass' (nothing is written). Never throws.
     */
    public function writeMode(): string
    {
        return $this->enabled() ? 'cache' : 'bypass';
    }

    /**
     * The current public-cache invalidation epoch. Alias of {@see version()};
     * the version IS the epoch (CORE-OPTIMIZE-2 §46). Never throws.
     */
    public function epoch(): int
    {
        return $this->version();
    }

    /**
     * Honest health vocabulary (CORE-OPTIMIZE-2 §12/§13) proven from evidence,
     * never assumed from the absence of an exception:
     *
     *   - 'unavailable' — the cache store cannot even be read (backend down);
     *     public content still resolves from source, so this degrades, not fails.
     *   - 'disabled'    — operator policy OFF or non-positive TTL (a normal
     *     operator choice, NOT an error).
     *   - 'healthy'     — policy ON, positive TTL, and the epoch is readable.
     */
    public function status(): string
    {
        if (! $this->storeReadable()) {
            return 'unavailable';
        }

        if (! $this->policyEnabled() || $this->ttl() <= 0) {
            return 'disabled';
        }

        return 'healthy';
    }

    /**
     * Bounded, single-key probe of the cache store (§13 — never a full scan).
     * Reads only the epoch key; a store backend failure surfaces as false.
     */
    private function storeReadable(): bool
    {
        try {
            Cache::get(self::VERSION_KEY);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Path-free, count-free, secret-free health snapshot (CORE-OPTIMIZE-2 §8).
     * Consumed by the public /cms-health endpoint, so it deliberately carries NO
     * driver name, key, path or value — the admin-only diagnostics layer adds
     * the safe driver name. The legacy `enabled`/`ttl`/`version` keys are kept
     * for backward compatibility, with `enabled` now corrected to mean the
     * EFFECTIVE runtime state rather than TTL configuration.
     *
     * @return array{ready: bool, enabled: bool, policy_enabled: bool, configured_ttl: int, ttl: int, effective_enabled: bool, read_mode: string, write_mode: string, epoch: int, version: int, status: string}
     */
    public function health(): array
    {
        $ttl = $this->ttl();
        $policy = $this->policyEnabled();
        $effective = $policy && $ttl > 0;

        return [
            'ready' => true,
            // Corrected semantics: EFFECTIVE state, not TTL config (§8).
            'enabled' => $effective,
            'policy_enabled' => $policy,
            'configured_ttl' => $ttl,
            'ttl' => $ttl,
            'effective_enabled' => $effective,
            'read_mode' => $effective ? 'cache' : 'source',
            'write_mode' => $effective ? 'cache' : 'bypass',
            'epoch' => $this->version(),
            'version' => $this->version(),
            'status' => $this->status(),
        ];
    }
}
