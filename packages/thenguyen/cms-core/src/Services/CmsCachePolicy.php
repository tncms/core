<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Services\Cache\CmsCacheOperationResult;
use TheNguyen\CMS\Services\Cache\PublicContentCacheWarmer;
use Throwable;

/**
 * Canonical CMS Cache policy authority (CORE-OPTIMIZE-1).
 *
 * Answers one question for every Core optimization-cache consumer:
 * "Is TNCMS application optimization caching enabled?" — so consumers do not
 * scatter raw setting-key checks throughout Core.
 *
 * Scope: this governs TNCMS *application optimization* caching only (audited
 * Core-owned performance caches such as {@see PublicContentCacheManager}). It
 * deliberately has NO authority over Laravel infrastructure state — sessions,
 * auth/remember tokens, locks, rate limiting, queue coordination, installer or
 * upgrade state — nor over the framework config/route/view build caches. Those
 * remain owned by Laravel and are never disabled or cleared through this policy.
 *
 * Recursion boundary (§17): the enabled flag is stored in cms_settings and read
 * through {@see SettingsManager}, whose own autoload cache is bootstrap
 * infrastructure that is NEVER gated by this policy. That keeps `cache_enabled`
 * resolvable while CMS Cache is OFF, so the policy never depends on the very
 * cache it controls.
 */
class CmsCachePolicy
{
    /**
     * The durable cms_settings key (group.key) holding the on/off flag. Stored
     * with autoload=true so it rides the settings autoload map on the hot path.
     */
    public const SETTING_KEY = 'optimize.cache_enabled';

    /**
     * Default when the site has no explicit setting: enabled, preserving the
     * pre-CORE-OPTIMIZE-1 behaviour where the public cache was always on.
     */
    public const DEFAULT_ENABLED = true;

    private ?SettingsManager $settings;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings;
    }

    /**
     * Whether TNCMS application optimization caching is enabled. Reads the
     * durable setting; falls back to {@see DEFAULT_ENABLED} when unset and on any
     * unexpected read failure, so a diagnostics/read glitch never changes the
     * site's caching posture silently (it holds the default, enabled).
     */
    public function enabled(): bool
    {
        try {
            return (bool) $this->settings()->get(self::SETTING_KEY, self::DEFAULT_ENABLED);
        } catch (Throwable $e) {
            report($e);

            return self::DEFAULT_ENABLED;
        }
    }

    /**
     * Convenience inverse of {@see enabled()} for consumer readability.
     */
    public function disabled(): bool
    {
        return ! $this->enabled();
    }

    /**
     * Targeted invalidation of the integrated CMS optimization cache. Today this
     * advances the public-content cache version epoch (a single cheap write that
     * orphans every previously written `tncms.public.*` key without tags), which
     * is safe on every store and works whether the toggle is ON or OFF.
     *
     * This is the canonical "Clear CMS Cache" entrypoint for Core and the future
     * plugin seam — it is intentionally NOT a global Cache::flush(): unrelated
     * Laravel, plugin, session, lock and rate-limit entries are untouched.
     *
     * Returns whether the epoch actually advanced, so callers can report an
     * honest outcome (§24) instead of a blind success when the store write
     * failed. Idempotent and safe to repeat; safe whether the toggle is ON or OFF.
     */
    public function clear(): bool
    {
        return $this->clearResult()->success;
    }

    /**
     * Same targeted epoch-advance invalidation as {@see clear()}, but returning
     * the full {@see CmsCacheOperationResult} (before/after epoch, honest
     * success, message key) for operator surfaces (CORE-OPTIMIZE-2 §15).
     */
    public function clearResult(): CmsCacheOperationResult
    {
        try {
            $cache = app('cms.public_cache');
            $before = $cache->version();
            $cache->flush();
            $after = $cache->version();
            $success = $after > $before;

            return CmsCacheOperationResult::clear(
                $success,
                $before,
                $after,
                $success ? 'optimize.clear.done' : 'optimize.clear.failed',
            );
        } catch (Throwable $e) {
            report($e);

            return CmsCacheOperationResult::clear(false, 0, 0, 'optimize.clear.failed');
        }
    }

    /**
     * Scoped warm-up of the public content cache domain (CORE-OPTIMIZE-2 §16).
     * Delegates to the bounded, single-flight {@see PublicContentCacheWarmer};
     * never throws. Cache remains optional — a failed warm never breaks content.
     */
    public function rebuild(): CmsCacheOperationResult
    {
        try {
            return app(PublicContentCacheWarmer::class)->warm();
        } catch (Throwable $e) {
            report($e);

            return CmsCacheOperationResult::rebuild(false, 'optimize.rebuild.failed');
        }
    }

    /**
     * Whether a warm/rebuild can do real, safe work right now (effective cache on
     * and a slug corpus present). The operator surface uses this so "Rebuild" is
     * never offered as a fake success (§32).
     */
    public function rebuildSupported(): bool
    {
        try {
            return app(PublicContentCacheWarmer::class)->supported();
        } catch (Throwable) {
            return false;
        }
    }

    private function settings(): SettingsManager
    {
        return $this->settings ??= app('cms.settings');
    }
}
