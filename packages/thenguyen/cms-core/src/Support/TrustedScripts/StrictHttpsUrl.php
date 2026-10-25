<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedScripts;

/**
 * Strict HTTPS URL grammar for trusted external scripts and CSP origins
 * (CORE-EXTENSION-TRUST S1, GAP-3 §6.4). Pure string work — never resolves DNS
 * and never fetches anything.
 *
 * Accepted: `https://<public-dns-host>[:443]/<path>[?<query>]`.
 * Rejected: any other scheme, protocol-relative, userinfo, ports other than 443,
 * IP literals, single-label / reserved / internal hosts, trailing-dot hosts,
 * fragments, whitespace and control characters, backslashes, dot segments,
 * encoded separators or dots, and malformed percent-encoding.
 *
 * The scheme must be written exactly `https` (mixed case is rejected).
 * Normalisation: host lower-cased, internationalised hosts converted
 * to ASCII (IDNA UTS-46, non-transitional), `:443` dropped. Path and query are
 * kept byte-for-byte (no re-ordering, no decoding), so the runtime URL is
 * always exactly the declared one.
 */
final class StrictHttpsUrl
{
    public const MAX_LENGTH = 2048;

    /** Reserved, local or non-public suffixes that may never serve a trusted script. */
    private const RESERVED_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'corp', 'lan', 'home',
        'home.arpa', 'test', 'invalid', 'example', 'onion', 'arpa',
    ];

    /** Path / query characters allowed besides percent-encoded octets. */
    private const SAFE_CHARS = "A-Za-z0-9\\-._~!$&'()*+,;=:@/";

    /** Normalised URL, or null when the input fails the grammar. */
    public static function normalize(string $url): ?string
    {
        $parts = self::split($url);

        if ($parts === null) {
            return null;
        }

        [$host, $path, $query] = $parts;

        return 'https://'.$host.$path.($query !== null ? '?'.$query : '');
    }

    /** `https://host` origin of a URL that passes the grammar, else null. */
    public static function origin(string $url): ?string
    {
        $parts = self::split($url);

        return $parts === null ? null : 'https://'.$parts[0];
    }

    /**
     * Normalised origin-only CSP source (`https://host`, nothing after the host),
     * or null when the value is not a plain HTTPS origin.
     */
    public static function normalizeOrigin(string $source): ?string
    {
        // Scheme must be literal lower-case "https" (no mixed-case variants).
        if (! preg_match('#^https://([^/?\#]+)$#', $source, $m)) {
            return null;
        }

        $host = self::normalizeHost($m[1]);

        return $host === null ? null : 'https://'.$host;
    }

    /** Normalised public DNS host (ASCII, lower-case), or null. Port :443 tolerated. */
    public static function normalizeHost(string $authority): ?string
    {
        if ($authority === '' || str_contains($authority, '@') || str_contains($authority, '[')) {
            return null;
        }

        $host = $authority;

        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);

            if ($port !== '443') {
                return null;
            }
        }

        if ($host === '' || str_ends_with($host, '.') || preg_match('/[\x00-\x20\x7f%\\\\]/', $host)) {
            return null;
        }

        if (preg_match('/[^\x21-\x7e]/', $host)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($ascii) || $ascii === '') {
                return null;
            }

            $host = $ascii;
        }

        $host = strtolower($host);

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        $tld = '(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})';

        if (strlen($host) > 253 || ! preg_match('/^(?:'.$label.'\.)+'.$tld.'$/', $host)) {
            return null;
        }

        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return null;
            }
        }

        return $host;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}|null [host, path, query]
     */
    private static function split(string $url): ?array
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7f\\\\#]/', $url)) {
            return null;
        }

        if (! preg_match('#^https://([^/?]+)(/[^?]*)(?:\?(.*))?$#s', $url, $m)) {
            return null;
        }

        $host = self::normalizeHost($m[1]);

        if ($host === null) {
            return null;
        }

        $path = $m[2];
        $query = isset($m[3]) ? $m[3] : null;

        if (! self::safeComponent($path) || str_starts_with($path, '//')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        if ($query !== null && ($query === '' || ! self::safeComponent(str_replace('?', '', $query)))) {
            return null;
        }

        return [$host, $path, $query];
    }

    /** Only safe characters and well-formed percent-encodings; no encoded `/`, `\` or `.`. */
    private static function safeComponent(string $value): bool
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value)) {
            return false;
        }

        if (preg_match('/%(2f|5c|2e|00)/i', $value)) {
            return false;
        }

        $stripped = preg_replace('/%[0-9A-Fa-f]{2}/', '', $value) ?? '';

        return preg_match('#^['.self::SAFE_CHARS.']*$#', $stripped) === 1;
    }
}
