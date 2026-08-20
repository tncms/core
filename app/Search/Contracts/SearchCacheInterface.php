<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\Search\SearchResponse;

/**
 * Optional result-cache boundary for the search manager (Phase 3.1.6N-C).
 *
 * The default {@see \App\Search\Cache\NullSearchCache} is a pass-through — this
 * phase does not implement caching and deliberately does NOT duplicate the
 * Knowledge Library `SearchCacheService`. A later phase (or a driver) may bind a
 * real, short-TTL implementation keyed by the query signature.
 */
interface SearchCacheInterface
{
    /**
     * Return a cached response for the key, or run and (optionally) store the
     * callback's response.
     *
     * @param  callable(): SearchResponse  $callback
     */
    public function remember(string $key, callable $callback): SearchResponse;
}
