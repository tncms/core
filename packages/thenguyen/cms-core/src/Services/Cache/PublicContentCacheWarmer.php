<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Services\PublicContentCacheManager;
use TheNguyen\CMS\Services\SlugManager;
use Throwable;

/**
 * Bounded, idempotent warm-up for the {@see PublicContentCacheManager} public
 * content domain (CORE-OPTIMIZE-2 §16–§24).
 *
 * Warming is *service-level*, never HTTP: it enumerates the CMS-owned cms_slugs
 * corpus (the exact keyspace the live guest resolver reads) and, for each
 * distinct (locale, full_path), populates the cache through the SAME resolution
 * authority a real request uses — {@see SlugManager::findPublic()} via
 * {@see PublicContentCacheManager::rememberResolution()}. It therefore:
 *
 *   - warms only canonical public Core content — never arbitrary/external URLs,
 *     authenticated pages, search queries, admin or plugin routes (§17);
 *   - is locale-correct — each slug row carries its own locale (§23);
 *   - is idempotent — rememberResolution writes only on a miss, so a repeat warm
 *     is all cache hits with no mutation (§21);
 *   - never mutates content, settings, sessions, update or installer state;
 *   - is bounded — chunked enumeration capped at a maximum, safe for a single
 *     shared-hosting request (§22);
 *   - is single-flight — a bounded, self-expiring lock prevents overlapping
 *     warms without Redis, Supervisor or a queue (§19); and
 *   - is optional — if warming is skipped/failed, lazy resolution still serves
 *     authoritative content (§20).
 */
final class PublicContentCacheWarmer
{
    /** Default maximum URLs warmed in one synchronous run (overridable via config). */
    public const DEFAULT_MAX_URLS = 2000;

    /** Lock name + bounded TTL (seconds); a crashed warm auto-recovers when it expires. */
    private const LOCK_KEY = 'tncms.public.cache.warm';

    private const LOCK_TTL = 120;

    public function __construct(
        private readonly PublicContentCacheManager $cache,
        private readonly SlugManager $slugs,
    ) {}

    public function maxUrls(): int
    {
        $configured = (int) config('cms.optimize.rebuild_max_urls', self::DEFAULT_MAX_URLS);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_URLS;
    }

    /**
     * Warming is only meaningful when the effective cache is on and the slug
     * corpus exists. Reported to the UI so a "Rebuild" action is not offered as a
     * fake success when it cannot do anything (§32).
     */
    public function supported(): bool
    {
        return $this->cache->effectiveEnabled() && Schema::hasTable('cms_slugs');
    }

    /**
     * Warm up to {@see maxUrls()} distinct public paths. Never throws; always
     * returns an honest {@see CmsCacheOperationResult}.
     */
    public function warm(): CmsCacheOperationResult
    {
        $started = microtime(true);

        if (! $this->cache->effectiveEnabled()) {
            return CmsCacheOperationResult::rebuild(
                success: false,
                messageKey: 'optimize.rebuild.skipped_disabled',
                context: ['reason' => 'disabled'],
            );
        }

        if (! Schema::hasTable('cms_slugs')) {
            return CmsCacheOperationResult::rebuild(
                success: false,
                messageKey: 'optimize.rebuild.unavailable',
                context: ['reason' => 'no_corpus'],
            );
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (! $lock->get()) {
            return CmsCacheOperationResult::rebuild(
                success: false,
                messageKey: 'optimize.rebuild.already_running',
                context: ['reason' => 'locked'],
            );
        }

        try {
            return $this->run($started);
        } finally {
            $lock->release();
        }
    }

    private function run(float $started): CmsCacheOperationResult
    {
        $max = $this->maxUrls();
        $processed = 0;
        $failed = 0;
        $seen = [];
        $locales = [];
        $capped = false;

        Slug::query()
            ->select(['id', 'locale', 'full_path'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, &$failed, &$seen, &$locales, &$capped, $max): bool {
                foreach ($rows as $row) {
                    $locale = (string) $row->locale;
                    $path = ltrim((string) $row->full_path, '/');
                    $signature = $locale.'|'.$path;

                    if (isset($seen[$signature])) {
                        continue;
                    }
                    $seen[$signature] = true;

                    if ($processed + $failed >= $max) {
                        $capped = true;

                        return false; // stop chunking — bound reached
                    }

                    if ($this->warmOne($locale, $path)) {
                        $processed++;
                        $locales[$locale] = true;
                    } else {
                        $failed++;
                    }
                }

                return true;
            }, 'id');

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $success = $failed === 0;

        return CmsCacheOperationResult::rebuild(
            success: $success,
            messageKey: $capped
                ? 'optimize.rebuild.partial'
                : ($success ? 'optimize.rebuild.done' : 'optimize.rebuild.partial'),
            processedCount: $processed,
            failedCount: $failed,
            durationMs: $durationMs,
            context: [
                'capped' => $capped,
                'max_urls' => $max,
                'locales' => array_keys($locales),
            ],
        );
    }

    /**
     * Warm a single (locale, path) through the real resolver. Reuses
     * findPublic() exactly as the FrontendController does — no content
     * resolution is duplicated beyond the trivial reference mapping.
     */
    private function warmOne(string $locale, string $path): bool
    {
        try {
            $this->cache->rememberResolution($locale, $path, function () use ($locale, $path): ?array {
                $row = $this->slugs->findPublic($path, $locale);

                if ($row === null) {
                    return null;
                }

                return [
                    'reference_type' => (string) $row->reference_type,
                    'reference_id' => (int) $row->reference_id,
                ];
            });

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
