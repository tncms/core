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
     * Last public-cache outcome for the request: 'HIT', 'MISS', or 'BYPASS'
     * (cache disabled). Read by the query profiler to emit X-TNCMS-Cache; null
     * until the first cached resolution this request.
     */
    private ?string $lastCacheState = null;

    /**
     * Whether the public cache is on. Off when the TTL is 0 (or negative).
     */
    public function enabled(): bool
    {
        return $this->ttl() > 0;
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
        if (! $this->enabled()) {
            return false;
        }

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
     * Count-only health snapshot for /cms-health. No keys, paths, or values.
     *
     * @return array{ready: bool, enabled: bool, ttl: int, version: int}
     */
    public function health(): array
    {
        $ttl = $this->ttl();

        return [
            'ready' => true,
            'enabled' => $ttl > 0,
            'ttl' => $ttl,
            'version' => $this->version(),
        ];
    }
}
