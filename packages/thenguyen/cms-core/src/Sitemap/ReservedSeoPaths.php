<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

/**
 * The public paths Core's robots.txt and sitemap endpoints own
 * (CORE-SITEMAP-S01): `/robots.txt`, `/sitemap.xml` and `/sitemaps/*`.
 * Content slugs, permalink bases, localized route segments and plugin routes
 * may not claim them. Locale-prefixed paths (`/en/sitemaps/...`) and paths
 * inside a plugin's own namespace (`/shop/sitemap.xml`) are not reserved.
 */
final class ReservedSeoPaths
{
    /** First path segments Core owns for its SEO endpoints. */
    public const SEGMENTS = ['robots.txt', 'sitemap.xml', 'sitemaps'];

    /** Whether a route URI (Laravel form, no leading slash) claims a reserved path. */
    public static function claims(string $uri): bool
    {
        $uri = strtolower(trim($uri, '/'));

        return in_array($uri, self::SEGMENTS, true) || str_starts_with($uri, 'sitemaps/');
    }

    /** Whether a single URL segment (slug, base, localized segment) is reserved. */
    public static function isSegment(string $segment): bool
    {
        return in_array(mb_strtolower(trim($segment, '/')), self::SEGMENTS, true);
    }
}
