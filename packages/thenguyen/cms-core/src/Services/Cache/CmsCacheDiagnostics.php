<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Cache;

use TheNguyen\CMS\Services\CmsCachePolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;
use Throwable;

/**
 * Read-only CMS cache diagnostics aggregator (CORE-OPTIMIZE-2 §9).
 *
 * The smallest authority that composes the EXISTING cache authorities
 * ({@see CmsCachePolicy}, {@see PublicContentCacheManager}) into one stable,
 * machine-readable, secret-free snapshot for the admin Optimize surface. It:
 *
 *   - never becomes a policy authority (it only reads);
 *   - never clears or rebuilds anything just because diagnostics are requested;
 *   - performs no expensive global store scan — only the managers' own bounded
 *     probes (§13);
 *   - exposes only a safe cache driver name ('file'/'database'/'redis'/'array'),
 *     never a DSN, path, credential, token or APP_KEY (§37).
 *
 * There is exactly ONE Core-managed cache domain today ({@see
 * PublicContentCacheManager::DOMAIN_ID}); the domain list is kept intentionally
 * small (§10) rather than a plugin registry.
 */
final class CmsCacheDiagnostics
{
    public function __construct(
        private readonly CmsCachePolicy $policy,
        private readonly PublicContentCacheManager $publicCache,
        private readonly PublicContentCacheWarmer $warmer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $health = $this->safeHealth();
        $rebuildSupported = $this->safe(fn (): bool => $this->warmer->supported(), false);

        return [
            'policy' => [
                'enabled' => $this->safe(fn (): bool => $this->policy->enabled(), false),
                'setting_key' => CmsCachePolicy::SETTING_KEY,
            ],
            'driver' => $this->safeDriver(),
            'public_cache' => $health,
            'status' => (string) ($health['status'] ?? 'unavailable'),
            'rebuild_supported' => $rebuildSupported,
            'rebuild_max_urls' => $this->safe(fn (): int => $this->warmer->maxUrls(), PublicContentCacheWarmer::DEFAULT_MAX_URLS),
            'domains' => [
                [
                    'id' => PublicContentCacheManager::DOMAIN_ID,
                    'label_key' => 'optimize.domain.public_content',
                    'status' => (string) ($health['status'] ?? 'unavailable'),
                    'clearable' => true,
                    'rebuildable' => $rebuildSupported,
                ],
            ],
        ];
    }

    /**
     * The configured cache store name only — a safe operator-chosen alias, never
     * connection detail. Falls back to 'unknown' if config is unreadable.
     */
    private function safeDriver(): string
    {
        try {
            $driver = config('cache.default');

            return is_string($driver) && $driver !== '' ? $driver : 'unknown';
        } catch (Throwable $e) {
            report($e);

            return 'unknown';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function safeHealth(): array
    {
        try {
            return $this->publicCache->health();
        } catch (Throwable $e) {
            report($e);

            return [
                'ready' => false,
                'enabled' => false,
                'policy_enabled' => false,
                'configured_ttl' => 0,
                'ttl' => 0,
                'effective_enabled' => false,
                'read_mode' => 'source',
                'write_mode' => 'bypass',
                'epoch' => 0,
                'version' => 0,
                'status' => 'unavailable',
            ];
        }
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
