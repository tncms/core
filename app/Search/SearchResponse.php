<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Immutable result of executing a search (Phase 3.1.6N-C). Carries the current
 * page of ranked {@see SearchResult} references, pagination metadata, the scopes
 * actually executed, and any contained provider failures. Never carries models.
 */
final class SearchResponse
{
    /**
     * @param  list<SearchResult>   $results        Current page, ranked.
     * @param  list<string>         $executedScopes Entity types actually searched.
     * @param  list<SearchFailure>  $failures       Contained provider failures.
     */
    public function __construct(
        public readonly array $results,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $total,
        public readonly array $executedScopes,
        public readonly array $failures = [],
    ) {}

    /** An empty response (no keyword, or no known scope). */
    public static function empty(SearchQuery $query): self
    {
        return new self([], $query->page, $query->perPage, 0, [], []);
    }

    public function totalPages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;
    }

    public function isEmpty(): bool
    {
        return $this->results === [];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /** @return list<string> Safe, user-facing warning messages. */
    public function warnings(): array
    {
        return array_map(static fn (SearchFailure $f): string => $f->message, $this->failures);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'results' => array_map(static fn (SearchResult $r): array => $r->toArray(), $this->results),
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages(),
            'executed_scopes' => $this->executedScopes,
            'failures' => array_map(static fn (SearchFailure $f): array => $f->toArray(), $this->failures),
        ];
    }
}
