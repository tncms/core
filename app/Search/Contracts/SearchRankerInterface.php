<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\Search\SearchQuery;
use App\Search\SearchResult;

/**
 * Orders merged results from all providers into a single ranked list (Phase
 * 3.1.6N-C). The initial implementation normalizes and combines the per-provider
 * scores and sorts deterministically. Semantic/AI/vector ranking is explicitly
 * out of scope.
 */
interface SearchRankerInterface
{
    /**
     * @param  list<SearchResult>  $results  Merged, unordered hits.
     * @return list<SearchResult>            Ranked, highest relevance first.
     */
    public function rank(array $results, SearchQuery $query): array;
}
