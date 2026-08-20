<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Internal carrier for a driver run (Phase 3.1.6N-C): the raw (unranked) hits
 * gathered across providers plus any contained failures. The manager ranks and
 * paginates these into a {@see SearchResponse}.
 */
final class SearchExecutionResult
{
    /**
     * @param  list<SearchResult>   $results
     * @param  list<SearchFailure>  $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly array $failures = [],
    ) {}
}
