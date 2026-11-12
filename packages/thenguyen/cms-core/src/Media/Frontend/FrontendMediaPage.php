<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

/**
 * One keyset page of the actor's own media (newest first, ordered by id descending).
 * `nextCursor` is null on the last page.
 */
final class FrontendMediaPage
{
    /**
     * @param  list<MediaSummary>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor,
        public readonly int $perPage,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string, per_page: int}}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (MediaSummary $item): array => $item->toArray(), $this->items),
            'meta' => ['next_cursor' => $this->nextCursor, 'per_page' => $this->perPage],
        ];
    }
}
