<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\Search\SearchQuery;
use App\Search\SearchResponse;

/**
 * The execution orchestrator for the shared search platform (Phase 3.1.6N-C).
 *
 * A manager accepts a {@see SearchQuery}, resolves the requested scopes to
 * providers via the registry, executes them through a {@see SearchDriverInterface}
 * (isolating failures), ranks the merged results, and paginates into a
 * {@see SearchResponse}.
 *
 * It MUST NOT query models directly, render HTML, know any Product/Post/Book
 * detail, or bypass a provider's repositories. All entity knowledge lives behind
 * the providers registered in Phase 3.1.6N-B.
 */
interface SearchManagerInterface
{
    public function search(SearchQuery $query): SearchResponse;
}
