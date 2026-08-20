<?php

declare(strict_types=1);

namespace App\Search\Contracts;

use App\Search\SearchQuery;
use App\Search\SearchResult;

/**
 * A plugin's entry point into the shared search platform (Phase 3.1.6N-B
 * foundation). A provider owns one or more {@see SearchableEntityDefinition}s and
 * knows how to resolve keyword hits for them.
 *
 * A provider MUST:
 *   - declare the entity types it exposes ({@see definitions()});
 *   - resolve results through its own repositories / read pipeline;
 *   - return only published, public, non-deleted references, localized to the
 *     query locale, bounded by the query's result limit.
 *
 * A provider MUST NOT:
 *   - render views or own any global search UI;
 *   - bypass its repositories or expose raw models;
 *   - reach into another plugin's data (isolation is guaranteed by the registry
 *     rejecting duplicate type identifiers).
 *
 * Providers are registered against the singleton {@see \App\Search\SearchRegistry}
 * from the plugin's own service-provider `boot()`. Execution/merge/ranking across
 * providers is the job of a later search manager (Phase 3.1.6N-C); this contract
 * only fixes the extension point.
 */
interface SearchProvider
{
    /**
     * Unique provider identifier (e.g. "ecommerce", "knowledge-library"). Must
     * match `^[a-z][a-z0-9_-]*$`. Registering a different provider under an
     * existing key is rejected.
     */
    public function key(): string;

    /**
     * The searchable entity types this provider owns. May expose several (a
     * multi-entity provider such as Knowledge Library) — each type must be
     * globally unique across all providers.
     *
     * @return list<SearchableEntityDefinition>
     */
    public function definitions(): array;

    /**
     * Resolve keyword hits for this provider's in-scope types. Implementations
     * search only their own definitions that fall within `$query->types` (empty
     * = all), enforce their declared visibility rules, honour `$query->locale`,
     * and never return more than `$query->perPage` references.
     *
     * @return list<SearchResult>
     */
    public function search(SearchQuery $query): array;
}
