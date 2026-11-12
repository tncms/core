<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Seo;

use Illuminate\Http\Request;

/**
 * The canonical host authority for Core's public pages and SEO endpoints
 * (CORE-SEO-RELEASE-HARDENING-R1, H1).
 *
 * The configured APP_URL origin (scheme, host, port) is the site's canonical
 * authority. A request whose host is that host keeps its own root, so scheme
 * and port behave exactly as before. A request for any other host gets the
 * configured origin for every absolute URL it renders (canonical, og:url,
 * hreflang, links, sitemap locs, robots.txt), so a spoofed Host header or an
 * internal proxy host never becomes canonical. The request base path is kept,
 * so subdirectory installs stay correct.
 *
 * Forwarded headers are honoured only from trusted proxies (Laravel's
 * trustedproxy.proxies); even then a forwarded host other than the configured
 * one is not canonical.
 *
 * `cms.deployment.canonical_host` (CMS_CANONICAL_HOST): `configured` (default)
 * or `request` (the legacy request-root behaviour). Any other value is treated
 * as `configured`. Without a usable APP_URL origin (empty, not http/https, no
 * host) there is no authority to enforce and the request root is kept.
 */
final class CanonicalHost
{
    public const POLICY_CONFIGURED = 'configured';

    public const POLICY_REQUEST = 'request';

    /** Whether this instance forced the URL root on an earlier request. */
    private bool $forced = false;

    public static function policy(): string
    {
        $value = strtolower(trim((string) config('cms.deployment.canonical_host', self::POLICY_CONFIGURED)));

        return $value === self::POLICY_REQUEST ? self::POLICY_REQUEST : self::POLICY_CONFIGURED;
    }

    /**
     * The configured origin, or null when APP_URL does not define one.
     *
     * @return array{host: string, origin: string, scheme: string}|null
     */
    public static function configured(): ?array
    {
        $parts = parse_url(trim((string) config('app.url')));

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return ['host' => $host, 'origin' => $scheme.'://'.$host.$port, 'scheme' => $scheme];
    }

    /** The root absolute URLs must use for this request, or null to keep the request's own root. */
    public function forcedRoot(Request $request): ?string
    {
        if (self::policy() !== self::POLICY_CONFIGURED || ($configured = self::configured()) === null) {
            return null;
        }

        if (strtolower($request->getHost()) === $configured['host']) {
            return null;
        }

        return $configured['origin'].rtrim($request->getBaseUrl(), '/');
    }

    /**
     * Apply the decision to the URL generator. A root forced for an earlier
     * request in the same process (tests, long-lived workers) is released when
     * the next request needs none.
     */
    public function apply(Request $request): void
    {
        $root = $this->forcedRoot($request);
        $url = app('url');

        if ($root !== null) {
            $url->forceRootUrl($root);
            $url->forceScheme((string) parse_url($root, PHP_URL_SCHEME));
            $this->forced = true;

            return;
        }

        if ($this->forced) {
            $url->forceRootUrl(null);
            $url->forceScheme(null);
            $this->forced = false;
        }
    }
}
