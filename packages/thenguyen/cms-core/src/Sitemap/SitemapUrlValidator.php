<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use TheNguyen\CMS\Support\CredentialQuery;
use Throwable;

/**
 * Validates provider URLs against Core's canonical base, locale authority and
 * route ownership (CORE-SITEMAP-R1 ADR D8). One instance per child render.
 */
final class SitemapUrlValidator
{
    private const MAX_LENGTH = 2048;

    /** @var array{scheme: string, host: string, port: int|null, path: string} */
    private array $base;

    /**
     * @param  list<string>  $locales  public locale codes
     */
    public function __construct(
        private readonly Router $router,
        string $baseUrl,
        private readonly array $locales,
        private readonly string $defaultLocale,
        private readonly bool $prefixDefault,
    ) {
        $parts = parse_url($baseUrl) ?: [];
        $this->base = [
            'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => rtrim((string) ($parts['path'] ?? ''), '/'),
        ];
    }

    /** Absolute, same-origin, locale-correct URL; when $owner is given it must route to that plugin. */
    public function valid(string $url, string $locale, ?string $owner): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || ! mb_check_encoding($url, 'UTF-8')
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?:2f|5c|00)/i', $url) === 1
            || preg_match('/[\x{FDD0}-\x{FDEF}\x{FFFE}\x{FFFF}]/u', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strtolower((string) ($parts['scheme'] ?? '')) !== $this->base['scheme']
            || strtolower((string) ($parts['host'] ?? '')) !== $this->base['host']
            || (isset($parts['port']) ? (int) $parts['port'] : null) !== $this->base['port']) {
            return false;
        }

        if (isset($parts['query']) && $this->carriesCredentials($parts['query'])) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '/');

        if ($this->base['path'] !== '') {
            if ($path !== $this->base['path'] && ! str_starts_with($path, $this->base['path'].'/')) {
                return false;
            }

            $path = substr($path, strlen($this->base['path'])) ?: '/';
        }

        // Dot segments would let a crawler-normalized URL escape the plugin's route.
        foreach (explode('/', $path) as $segment) {
            if (in_array(rawurldecode($segment), ['.', '..'], true)) {
                return false;
            }
        }

        return $this->localeMatches($path, $locale) && ($owner === null || $this->routesTo($url, $owner));
    }

    public function validLastmod(?DateTimeInterface $lastmod): bool
    {
        if ($lastmod === null) {
            return false;
        }

        $ts = $lastmod->getTimestamp();

        return $ts >= 0 && $ts <= time() + 86400;
    }

    /**
     * Signed-URL signatures and token-like parameters never belong in a public
     * sitemap (CORE-SITEMAP-S02 security gate). Ordinary queries (`?page=2`) stay valid.
     */
    private function carriesCredentials(string $query): bool
    {
        return CredentialQuery::carries($query);
    }

    private function localeMatches(string $path, string $locale): bool
    {
        if (! in_array($locale, $this->locales, true)) {
            return false;
        }

        $first = rawurldecode(explode('/', ltrim($path, '/'))[0]);

        if ($locale !== $this->defaultLocale || $this->prefixDefault) {
            return $first === $locale;
        }

        return ! in_array(strtolower($first), $this->locales, true);
    }

    private function routesTo(string $url, string $owner): bool
    {
        try {
            $route = $this->router->getRoutes()->match(Request::create($url, 'GET'));
        } catch (Throwable) {
            return false;
        }

        return in_array('cms.plugin-active:'.$owner, (array) $route->middleware(), true);
    }
}
