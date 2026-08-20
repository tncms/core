<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Immutable, sanitized search request (Phase 3.1.6N-B foundation). Carries the
 * keyword, the resolved locale, the selected scopes (entity types; empty = all),
 * structured filters, pagination and sort — everything a provider needs and
 * nothing it should not (no request, session, or model handles).
 *
 * Build instances through {@see create()} so pagination is always bounded — the
 * platform never permits unlimited loading.
 */
final class SearchQuery
{
    /** Default page size when a caller does not specify one. */
    public const DEFAULT_PER_PAGE = 20;

    /** Hard ceiling on page size; larger requests are clamped. */
    public const MAX_PER_PAGE = 50;

    /** Relevance-first ordering (default). */
    public const SORT_RELEVANCE = 'relevance';

    /**
     * @param  list<string>          $types    Selected scope keys; empty = all.
     * @param  array<string, mixed>  $filters  Structured, allow-listed filters.
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $locale,
        public readonly array $types = [],
        public readonly array $filters = [],
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
        public readonly string $sort = self::SORT_RELEVANCE,
    ) {}

    /**
     * Normalize and clamp raw input into a safe query. Trims the keyword,
     * lower-cases and de-duplicates scope keys, floors the page at 1 and clamps
     * the page size to [1, MAX_PER_PAGE].
     *
     * @param  array<int, string>    $types
     * @param  array<string, mixed>  $filters
     */
    public static function create(
        string $keyword,
        string $locale,
        array $types = [],
        array $filters = [],
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        string $sort = self::SORT_RELEVANCE,
    ): self {
        $normalizedTypes = array_values(array_unique(array_filter(array_map(
            static fn ($type): string => strtolower(trim((string) $type)),
            $types,
        ), static fn (string $type): bool => $type !== '')));

        return new self(
            keyword: trim($keyword),
            locale: trim($locale),
            types: $normalizedTypes,
            filters: $filters,
            page: max(1, $page),
            perPage: max(1, min(self::MAX_PER_PAGE, $perPage)),
            sort: $sort !== '' ? $sort : self::SORT_RELEVANCE,
        );
    }

    public function isEmpty(): bool
    {
        return $this->keyword === '';
    }

    /** True when no explicit scope was chosen, or "all" was chosen. */
    public function wantsAll(): bool
    {
        return $this->types === [] || in_array(SearchScope::ALL, $this->types, true);
    }

    /** True when the given entity type is in scope for this query. */
    public function wantsType(string $type): bool
    {
        return $this->wantsAll() || in_array($type, $this->types, true);
    }
}
