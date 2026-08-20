<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\Contracts\SearchCacheInterface;
use App\Search\Contracts\SearchDriverInterface;
use App\Search\Contracts\SearchManagerInterface;
use App\Search\Contracts\SearchRankerInterface;

/**
 * Orchestrates a search end-to-end (Phase 3.1.6N-C):
 *
 *   normalize/guard → resolve scopes → driver executes providers (isolated) →
 *   rank → paginate → SearchResponse.
 *
 * The manager owns NO entity knowledge: it never touches a model, a repository,
 * or Product/Post/Book detail. All of that lives behind the providers the driver
 * invokes. Pagination is always bounded (the query clamps `perPage`); an empty
 * keyword or an unknown-only scope returns an empty response rather than loading
 * anything.
 */
final class SearchManager implements SearchManagerInterface
{
    public function __construct(
        private readonly SearchRegistry $registry,
        private readonly SearchDriverInterface $driver,
        private readonly SearchRankerInterface $ranker,
        private readonly SearchCacheInterface $cache,
    ) {}

    public function search(SearchQuery $query): SearchResponse
    {
        if ($query->isEmpty()) {
            return SearchResponse::empty($query);
        }

        $types = $this->registry->resolveTypes($query->types);

        if ($types === []) {
            return SearchResponse::empty($query);
        }

        return $this->cache->remember(
            $this->cacheKey($query, $types),
            function () use ($query, $types): SearchResponse {
                $execution = $this->driver->execute($query, $types);
                $ranked = $this->ranker->rank($execution->results, $query);

                $total = count($ranked);
                $offset = ($query->page - 1) * $query->perPage;
                $pageSlice = array_slice($ranked, $offset, $query->perPage);

                return new SearchResponse(
                    results: array_values($pageSlice),
                    page: $query->page,
                    perPage: $query->perPage,
                    total: $total,
                    executedScopes: $types,
                    failures: $execution->failures,
                );
            },
        );
    }

    /**
     * @param  list<string>  $types
     */
    private function cacheKey(SearchQuery $query, array $types): string
    {
        return md5(implode('|', [
            $query->keyword,
            $query->locale,
            implode(',', $types),
            (string) $query->page,
            (string) $query->perPage,
            $query->sort,
            (string) json_encode($query->filters),
        ]));
    }
}
