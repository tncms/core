<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\Search\SearchExecutionResult;
use App\Search\SearchQuery;

/**
 * How raw results are gathered for a query (Phase 3.1.6N-C boundary).
 *
 * The default {@see \App\Search\Drivers\ProviderSearchDriver} fans the query out
 * to the registered providers (each of which searches its own repositories) and
 * isolates per-provider failures. This same seam lets future drivers gather
 * results a different way WITHOUT changing the manager:
 *
 *   - Database  — the default provider fan-out (in place today).
 *   - Meilisearch / Elasticsearch — query a dedicated external index instead.
 *
 * No external service is installed by this phase; only the boundary is defined.
 */
interface SearchDriverInterface
{
    /** Stable driver name, e.g. "provider", "meilisearch". */
    public function name(): string;

    /**
     * Gather raw (unranked) results for the already-resolved entity types,
     * capturing any provider failure rather than letting it escape.
     *
     * @param  list<string>  $types  Resolved, known entity types to search.
     */
    public function execute(SearchQuery $query, array $types): SearchExecutionResult;
}
