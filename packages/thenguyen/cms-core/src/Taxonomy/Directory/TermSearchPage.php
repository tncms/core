<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

/**
 * One bounded page of search results. `nextCursor` is null on the last page. There is
 * deliberately no total count (it would cost a full scan).
 */
final class TermSearchPage
{
    /** @param list<TermSummary> $items */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly string $taxonomy,
        public readonly string $locale,
        /** The query actually matched after normalization (trim, whitespace collapse, length cap). */
        public readonly string $normalizedQuery,
    ) {}

    /** @return array{items: list<array<string, mixed>>, nextCursor: ?string, taxonomy: string, locale: string, query: string} */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (TermSummary $t): array => $t->toArray(), $this->items),
            'nextCursor' => $this->nextCursor,
            'taxonomy' => $this->taxonomy,
            'locale' => $this->locale,
            'query' => $this->normalizedQuery,
        ];
    }
}
