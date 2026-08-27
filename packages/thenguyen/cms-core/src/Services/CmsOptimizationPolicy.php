<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Throwable;

/**
 * Canonical frontend/runtime optimization policy authority (CORE-OPTIMIZE-3).
 *
 * Answers "how should Core shape public HTTP responses for the browser?" for the
 * response-optimization middleware and the runtime diagnostics surface — so those
 * consumers never scatter raw setting-key reads.
 *
 * Scope boundary (§4): this governs *response/runtime* optimization policy only.
 * It is deliberately NOT a second cache authority — {@see CmsCachePolicy} remains
 * the sole owner of "is TNCMS application optimization caching enabled?" and of
 * Clear/Rebuild. This policy has NO authority over sessions, auth/remember tokens,
 * CSRF, the cache store, installer/upgrade state, or framework build caches; it
 * only reads durable operator settings and reports safe, conservative answers.
 *
 * Durable settings live in cms_settings under the `optimize.response.*` namespace
 * (never `.env`, never framework config), read through {@see SettingsManager} — the
 * same authority and namespace as {@see CmsCachePolicy::SETTING_KEY}.
 *
 * Defaults are conservative and upgrade-safe (§4): response optimization is OFF by
 * default, so an upgraded site behaves exactly as before until an operator opts in.
 */
class CmsOptimizationPolicy
{
    /**
     * Master toggle for Core-managed HTTP response header optimization. Default
     * OFF: when unset the middleware is a strict no-op and responses keep their
     * pre-CORE-OPTIMIZE-3 headers (upgrade-safe).
     */
    public const RESPONSE_ENABLED_KEY = 'optimize.response.enabled';

    public const RESPONSE_ENABLED_DEFAULT = false;

    /**
     * Browser cache lifetime (seconds) applied to anonymous, cacheable public
     * HTML when response optimization is ON. 0 (default) means "revalidate, do not
     * positively cache" — the conservative default. Clamped to {@see TTL_MAX} so a
     * bad value can never pin a stale page in browsers for an unbounded time.
     */
    public const RESPONSE_PUBLIC_TTL_KEY = 'optimize.response.public_html_ttl';

    public const RESPONSE_PUBLIC_TTL_DEFAULT = 0;

    /** Hard upper bound for the public HTML browser TTL (24h). */
    public const TTL_MAX = 86400;

    private ?SettingsManager $settings;

    public function __construct(?SettingsManager $settings = null)
    {
        $this->settings = $settings;
    }

    /**
     * Whether Core-managed HTTP response header optimization is enabled. Reads the
     * durable setting; falls back to the conservative default (OFF) on any read
     * failure so a glitch never silently changes response semantics.
     */
    public function responseOptimizationEnabled(): bool
    {
        try {
            return (bool) $this->settings()->get(self::RESPONSE_ENABLED_KEY, self::RESPONSE_ENABLED_DEFAULT);
        } catch (Throwable $e) {
            report($e);

            return self::RESPONSE_ENABLED_DEFAULT;
        }
    }

    /**
     * Browser cache lifetime (seconds) for anonymous public HTML, clamped into
     * [0, {@see TTL_MAX}]. Any non-numeric/negative/oversized stored value resolves
     * to the conservative default or bound — never an unbounded or negative age.
     */
    public function publicHtmlTtl(): int
    {
        try {
            $raw = $this->settings()->get(self::RESPONSE_PUBLIC_TTL_KEY, self::RESPONSE_PUBLIC_TTL_DEFAULT);
        } catch (Throwable $e) {
            report($e);

            return self::RESPONSE_PUBLIC_TTL_DEFAULT;
        }

        if (! is_numeric($raw)) {
            return self::RESPONSE_PUBLIC_TTL_DEFAULT;
        }

        $ttl = (int) $raw;

        if ($ttl < 0) {
            return 0;
        }

        return min($ttl, self::TTL_MAX);
    }

    private function settings(): SettingsManager
    {
        return $this->settings ??= app('cms.settings');
    }
}
