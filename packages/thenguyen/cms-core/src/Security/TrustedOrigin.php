<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Security;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

/**
 * Trusted origin for security-sensitive absolute URLs (CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1).
 *
 * A link that carries a capability — a password-reset token, an email
 * verification signature — must never be built from the request: the Host
 * header and forwarded headers are client- or proxy-supplied. This service
 * derives the origin from the configured `app.url` (APP_URL) only, validated
 * strictly, and hands out a URL generator bound to it.
 *
 * - The origin is `scheme://host[:port]` plus an optional base path taken from
 *   APP_URL's path. No request value, trusted proxy header or the SEO
 *   `cms.deployment.canonical_host` policy can change it.
 * - An unusable APP_URL (not http/https, no or invalid host, an IPv6 literal,
 *   credentials, a query or fragment, a dot segment, a non-string) is a
 *   configuration error:
 *   {@see root()} throws and {@see problem()} reports why. Callers fail closed;
 *   there is no fallback to the request host.
 * - {@see urls()} returns a clone of the application URL generator re-rooted at
 *   the trusted origin. The shared generator is never mutated, so nothing leaks
 *   into other URLs of the request, later requests, or long-lived workers.
 *
 * For a valid APP_URL the scheme, host and port equal the SEO canonical origin
 * ({@see \TheNguyen\CMS\Seo\CanonicalHost::configured()}); only the validation
 * is stricter and the request-host policy does not apply.
 */
final class TrustedOrigin
{
    public function __construct(private readonly Application $app) {}

    /** The validated root (`scheme://host[:port][/base]`, no trailing slash). */
    public function root(): string
    {
        $parsed = $this->parse();
        if (is_string($parsed)) {
            throw new UntrustedOriginException($parsed);
        }

        return $parsed['root'];
    }

    /** Null when APP_URL is a usable trusted origin, else a short reason (never the value). */
    public function problem(): ?string
    {
        $parsed = $this->parse();

        return is_string($parsed) ? $parsed : null;
    }

    public function usable(): bool
    {
        return $this->problem() === null;
    }

    /**
     * A URL generator bound to the trusted origin, for building security links
     * (named routes, signed routes). Throws when the origin is unusable.
     */
    public function urls(): UrlGenerator
    {
        $parsed = $this->parse();
        if (is_string($parsed)) {
            throw new UntrustedOriginException($parsed);
        }

        /** @var UrlGenerator $shared */
        $shared = $this->app->make('url');
        $urls = clone $shared;
        // setRequest() drops the cloned route-URL generator (which points back at
        // the shared instance) and the cached root/scheme; forceRootUrl/forceScheme
        // then pin the clone to the trusted origin.
        $urls->setRequest(Request::create($parsed['origin'].'/'));
        $urls->forceRootUrl($parsed['root']);
        $urls->forceScheme($parsed['scheme']);

        return $urls;
    }

    /**
     * @return array{scheme: string, origin: string, root: string}|string the parts, or a reason
     */
    private function parse(): array|string
    {
        $value = $this->app->make('config')->get('app.url');
        if (! is_string($value) || trim($value) === '') {
            return 'APP_URL is empty or not a string';
        }

        $value = trim($value);
        if (preg_match('/[\s\x00-\x1f\x7f\\\\]/', $value) === 1) {
            return 'APP_URL contains whitespace, control or backslash characters';
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return 'APP_URL is not a valid URL';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return 'APP_URL must use http or https';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'APP_URL must not contain credentials';
        }

        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($value, '?') || str_contains($value, '#')) {
            return 'APP_URL must not contain a query or fragment';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if (str_starts_with($host, '[')) {
            // Laravel's route URL generator percent-encodes the brackets of an
            // IPv6 literal, so the mailed link would be unusable.
            return 'APP_URL with an IPv6 literal host is not supported for security links';
        }

        if (! $this->validHost($host)) {
            return 'APP_URL has no valid host';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && preg_match('#^(?:/(?!\.\.?(?:/|$))[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)+$#', $path) !== 1) {
            return 'APP_URL has an unsupported path';
        }

        $origin = $scheme.'://'.$host.$port;

        return ['scheme' => $scheme, 'origin' => $origin, 'root' => $origin.$path];
    }

    private function validHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
