<?php

declare(strict_types=1);

namespace App\Search\Presentation;

use App\Search\SearchResult;

/**
 * A single search hit shaped for the Blade view (Phase 3.1.6N-D). Presentation
 * only — title/excerpt/url are already localized by the provider, and the type
 * is resolved to its human label. No model, no commercial data.
 */
final class SearchResultView
{
    public function __construct(
        public readonly string $title,
        public readonly string $excerpt,
        public readonly ?string $url,
        public readonly string $typeLabel,
        public readonly float $score,
    ) {}

    public static function fromResult(SearchResult $result, string $typeLabel): self
    {
        return new self(
            title: $result->title,
            excerpt: $result->excerpt,
            url: $result->url,
            typeLabel: $typeLabel,
            score: $result->score,
        );
    }
}
