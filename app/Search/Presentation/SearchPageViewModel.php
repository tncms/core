<?php

declare(strict_types=1);

namespace App\Search\Presentation;

use App\Search\SearchResponse;
use App\Search\SearchScope;

/**
 * Everything the search Blade view needs, and nothing more (Phase 3.1.6N-D).
 * Built by the controller from the {@see SearchResponse} and the registry's
 * scopes/labels — the view never touches the manager, registry, or a model.
 */
final class SearchPageViewModel
{
    /**
     * @param  list<SearchScope>       $scopes   Registry-driven dropdown options.
     * @param  list<SearchResultView>  $results  Current page of hits.
     * @param  list<string>            $warnings Safe provider-failure messages.
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $selectedScope,
        public readonly array $scopes,
        public readonly array $results,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly bool $searched,
        public readonly bool $tooShort,
        public readonly int $minLength,
        public readonly array $warnings,
    ) {}

    /**
     * @param  list<SearchScope>       $scopes
     * @param  array<string, string>   $labels  type => human label.
     */
    public static function build(
        string $keyword,
        string $selectedScope,
        array $scopes,
        ?SearchResponse $response,
        array $labels,
        int $minLength,
        bool $tooShort,
    ): self {
        $results = [];
        $total = 0;
        $page = 1;
        $perPage = 0;
        $totalPages = 0;
        $warnings = [];
        $searched = false;

        if ($response !== null) {
            $searched = true;
            foreach ($response->results as $result) {
                $results[] = SearchResultView::fromResult($result, $labels[$result->type] ?? $result->type);
            }
            $total = $response->total;
            $page = $response->page;
            $perPage = $response->perPage;
            $totalPages = $response->totalPages();
            $warnings = $response->warnings();
        }

        return new self(
            keyword: $keyword,
            selectedScope: $selectedScope,
            scopes: $scopes,
            results: $results,
            total: $total,
            page: $page,
            perPage: $perPage,
            totalPages: $totalPages,
            searched: $searched,
            tooShort: $tooShort,
            minLength: $minLength,
            warnings: $warnings,
        );
    }

    public function hasResults(): bool
    {
        return $this->results !== [];
    }

    public function hasPagination(): bool
    {
        return $this->totalPages > 1;
    }
}
