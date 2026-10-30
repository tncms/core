<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

/**
 * A source of sitemap URLs (CORE-SITEMAP-R1). Register one per plugin through
 * `cms.sitemap_providers`.
 *
 * Keys are positive integers, unique and stable per (type, locale) — usually
 * the primary key — and every method enumerates in ascending key order, so
 * Core can split a set into fixed-size chunks with bounded keyset queries.
 * Return only publicly available, indexable records, with absolute canonical
 * URLs generated through Core's locale authority (named routes / resolvers).
 */
interface SitemapProvider
{
    /** @return list<string> stable type names matching /^[a-z][a-z0-9_]{0,31}$/ */
    public function types(): array;

    /** Key of the entry $offset positions after $after (exclusive), or null when there is none. */
    public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int;

    /**
     * Entries with $after < key <= $until ($until null = open), ascending, at most $limit.
     *
     * @return iterable<SitemapEntry>
     */
    public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable;
}
