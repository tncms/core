<?php

declare(strict_types=1);

namespace App\Search\Cache;

use App\Search\Contracts\SearchCacheInterface;
use App\Search\SearchResponse;

/**
 * Pass-through cache (Phase 3.1.6N-C default). Always misses and runs the
 * callback, so the manager is cache-aware without this phase implementing — or
 * duplicating — any real caching.
 */
final class NullSearchCache implements SearchCacheInterface
{
    public function remember(string $key, callable $callback): SearchResponse
    {
        return $callback();
    }
}
